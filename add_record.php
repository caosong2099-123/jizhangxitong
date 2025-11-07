<?php
require_once 'includes/header.php';
requireLogin();

$user_id = getCurrentUserId();

// 处理表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $type = $_POST['type'];
    $amount = floatval($_POST['amount']);
    $category = $_POST['category'];
    $account_id = intval($_POST['account_id']);
    $date = $_POST['date'];
    $time = $_POST['time'];
    $description = trim($_POST['description']);
    
    // 组合日期和时间
    $datetime = $date . ' ' . $time . ':00';
    
    // 验证输入
    $errors = [];
    
    if (!in_array($type, ['income', 'expense'])) {
        $errors[] = "请选择正确的记录类型";
    }
    
    if ($amount <= 0) {
        $errors[] = "金额必须大于0";
    }
    
    if (empty($category)) {
        $errors[] = "请选择分类";
    }
    
    if ($account_id <= 0) {
        $errors[] = "请选择账户";
    }
    
    if (!isValidDateTime($datetime)) {
        $errors[] = "请输入有效的日期和时间";
    }
    
    // 验证账户属于当前用户
    if ($account_id > 0) {
        $stmt = $pdo->prepare("SELECT id, balance FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$account_id, $user_id]);
        $account = $stmt->fetch();
        
        if (!$account) {
            $errors[] = "账户不存在";
        } elseif ($type == 'expense' && $account['balance'] < $amount) {
            $errors[] = "账户余额不足";
        }
    }
    
    if (empty($errors)) {
        $pdo->beginTransaction();
        try {
            // 插入记录
            $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, type, amount, category, transaction_date, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$user_id, $account_id, $type, $amount, $category, $datetime, $description]);
            $transaction_id = $pdo->lastInsertId();
            
            // 更新账户余额
            if ($type == 'income') {
                $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
                    ->execute([$amount, $account_id]);
            } else {
                $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")
                    ->execute([$amount, $account_id]);
            }
            
            // 记录账户流水
            $pdo->prepare("INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date) VALUES (?, ?, ?, ?, 'transactions', ?, ?, ?)")
                ->execute([$user_id, $account_id, $type, $amount, $transaction_id, $description, $datetime]);
            
            $pdo->commit();
            setMessage("记录添加成功", "success");
            header("Location: dashboard.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "添加记录失败: " . $e->getMessage();
        }
    }
}

// 设置默认日期时间为现在
$default_date = date('Y-m-d');
$default_time = date('H:i');

// 获取用户账户列表
try {
    $stmt = $pdo->prepare("SELECT id, account_name, account_type, balance FROM accounts WHERE user_id = ? ORDER BY account_type");
    $stmt->execute([$user_id]);
    $user_accounts = $stmt->fetchAll();
} catch (PDOException $e) {
    // 如果accounts表不存在，显示友好错误
    if (strpos($e->getMessage(), "Table 'jizhangxitong.accounts' doesn't exist") !== false) {
        $accounts_error = "账户功能未初始化，请先运行 <a href='upgrade_accounts_jizhang.php'>数据库升级脚本</a>";
        $user_accounts = [];
    } else {
        die("数据库查询错误: " . $e->getMessage());
    }
}
?>

<div class="card">
    <h2>添加记账记录</h2>
    
    <?php if (!empty($errors)): ?>
    <div class="alert alert-error">
        <?php foreach ($errors as $error): ?>
        <p><?php echo safeOutput($error); ?></p>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <?php if (isset($accounts_error)): ?>
    <div class="alert alert-error">
        <?php echo $accounts_error; ?>
    </div>
    <?php endif; ?>
    
    <form method="POST" action="">
        <div class="form-group">
            <label for="type">记录类型</label>
            <select id="type" name="type" required>
                <option value="">请选择类型</option>
                <option value="income" <?php echo (isset($_POST['type']) && $_POST['type'] == 'income') ? 'selected' : ''; ?>>收入</option>
                <option value="expense" <?php echo (isset($_POST['type']) && $_POST['type'] == 'expense') ? 'selected' : ''; ?>>支出</option>
            </select>
        </div>
        
        <div class="form-group">
            <label for="account_id">资金账户</label>
            <select id="account_id" name="account_id" required <?php echo empty($user_accounts) ? 'disabled' : ''; ?>>
                <option value="">请选择账户</option>
                <?php foreach ($user_accounts as $account): ?>
                <option value="<?php echo $account['id']; ?>" data-balance="<?php echo $account['balance']; ?>" <?php echo (isset($_POST['account_id']) && $_POST['account_id'] == $account['id']) ? 'selected' : ''; ?>>
                    <?php echo safeOutput($account['account_name']); ?> (余额: ￥<?php echo number_format($account['balance'], 2); ?>)
                </option>
                <?php endforeach; ?>
            </select>
            <small id="account-balance-info" style="display: none;"></small>
            <?php if (empty($user_accounts)): ?>
            <small style="color: red;">请先运行数据库升级脚本来启用账户功能</small>
            <?php endif; ?>
        </div>
        
        <div class="form-group">
            <label for="amount">金额</label>
            <input type="number" id="amount" name="amount" step="0.01" min="0.01" required value="<?php echo isset($_POST['amount']) ? safeOutput($_POST['amount']) : ''; ?>">
        </div>
        
        <div class="form-group">
            <label for="category">分类</label>
            <select id="category" name="category" required>
                <option value="">请选择分类</option>
                <!-- 分类选项将通过JavaScript动态加载 -->
            </select>
        </div>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px;">
            <div class="form-group">
                <label for="date">日期</label>
                <input type="date" id="date" name="date" required value="<?php echo isset($_POST['date']) ? safeOutput($_POST['date']) : $default_date; ?>">
            </div>
            
            <div class="form-group">
                <label for="time">时间</label>
                <input type="time" id="time" name="time" required value="<?php echo isset($_POST['time']) ? safeOutput($_POST['time']) : $default_time; ?>">
            </div>
        </div>
        
        <div class="form-group">
            <label for="description">描述</label>
            <textarea id="description" name="description" rows="3"><?php echo isset($_POST['description']) ? safeOutput($_POST['description']) : ''; ?></textarea>
        </div>
        
        <button type="submit" <?php echo empty($user_accounts) ? 'disabled' : ''; ?>>保存记录</button>
        <?php if (empty($user_accounts)): ?>
        <p style="color: red; margin-top: 10px;">无法保存记录，请先启用账户功能</p>
        <?php endif; ?>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const typeSelect = document.getElementById('type');
    const categorySelect = document.getElementById('category');
    const accountSelect = document.getElementById('account_id');
    const amountInput = document.getElementById('amount');
    const balanceInfo = document.getElementById('account-balance-info');
    
    // 分类数据
    const categories = {
        income: ['工资', '奖金', '投资回报', '兼职收入', '其他收入'],
        expense: ['食品', '住房', '交通', '娱乐', '医疗', '教育', '购物', '其他支出']
    };
    
    // 更新分类选项
    function updateCategories() {
        // 清空现有选项（保留第一个提示选项）
        while (categorySelect.children.length > 1) {
            categorySelect.removeChild(categorySelect.lastChild);
        }
        
        // 根据类型添加选项
        const type = typeSelect.value;
        if (type && categories[type]) {
            categories[type].forEach(cat => {
                const option = document.createElement('option');
                option.value = cat;
                option.textContent = cat;
                categorySelect.appendChild(option);
            });
        }
    }
    
    // 更新账户余额信息
    function updateAccountInfo() {
        const selectedOption = accountSelect.options[accountSelect.selectedIndex];
        if (selectedOption.value && selectedOption.dataset.balance) {
            const balance = parseFloat(selectedOption.dataset.balance);
            const amount = parseFloat(amountInput.value) || 0;
            const type = typeSelect.value;
            
            let message = `当前余额: ￥${balance.toFixed(2)}`;
            
            if (type === 'expense' && amount > 0) {
                const remaining = balance - amount;
                if (remaining < 0) {
                    message += ` ❌ 余额不足，还需 ￥${Math.abs(remaining).toFixed(2)}`;
                    balanceInfo.style.color = 'red';
                } else {
                    message += ` → 交易后余额: ￥${remaining.toFixed(2)}`;
                    balanceInfo.style.color = 'green';
                }
            } else if (type === 'income' && amount > 0) {
                const newBalance = balance + amount;
                message += ` → 交易后余额: ￥${newBalance.toFixed(2)}`;
                balanceInfo.style.color = 'green';
            }
            
            balanceInfo.textContent = message;
            balanceInfo.style.display = 'block';
        } else {
            balanceInfo.style.display = 'none';
        }
    }
    
    // 初始化分类选项
    updateCategories();
    
    // 类型更改时更新分类
    typeSelect.addEventListener('change', function() {
        updateCategories();
        updateAccountInfo();
    });
    
    // 账户选择或金额输入时更新余额信息
    if (accountSelect) {
        accountSelect.addEventListener('change', updateAccountInfo);
        amountInput.addEventListener('input', updateAccountInfo);
        typeSelect.addEventListener('change', updateAccountInfo);
    }
});
</script>

<?php
require_once 'includes/footer.php';
?>