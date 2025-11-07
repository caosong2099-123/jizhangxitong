<?php
require_once 'includes/header.php';
requireLogin();

$user_id = getCurrentUserId();
$current_month = date('Y-m-01');

// 处理预算设置
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $category = $_POST['category'];
    $amount = floatval($_POST['amount']);
    
    // 验证输入
    $errors = [];
    
    if (empty($category)) {
        $errors[] = "请选择分类";
    }
    
    if ($amount <= 0) {
        $errors[] = "预算金额必须大于0";
    }
    
    if (empty($errors)) {
        // 检查是否已存在该分类的预算
        $stmt = $pdo->prepare("SELECT id FROM budgets WHERE user_id = ? AND category = ? AND month_year = ?");
        $stmt->execute([$user_id, $category, $current_month]);
        
        if ($stmt->fetch()) {
            // 更新预算
            $stmt = $pdo->prepare("UPDATE budgets SET amount = ? WHERE user_id = ? AND category = ? AND month_year = ?");
            if ($stmt->execute([$amount, $user_id, $category, $current_month])) {
                setMessage("预算更新成功", "success");
            } else {
                $errors[] = "预算更新失败";
            }
        } else {
            // 插入新预算
            $stmt = $pdo->prepare("INSERT INTO budgets (user_id, category, amount, month_year) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$user_id, $category, $amount, $current_month])) {
                setMessage("预算设置成功", "success");
            } else {
                $errors[] = "预算设置失败";
            }
        }
        
        if (empty($errors)) {
            header("Location: budget.php");
            exit();
        }
    }
}

// 处理预算删除
if (isset($_GET['delete'])) {
    $budget_id = intval($_GET['delete']);
    
    // 验证预算属于当前用户
    $stmt = $pdo->prepare("SELECT id FROM budgets WHERE id = ? AND user_id = ?");
    $stmt->execute([$budget_id, $user_id]);
    
    if ($stmt->fetch()) {
        $stmt = $pdo->prepare("DELETE FROM budgets WHERE id = ?");
        if ($stmt->execute([$budget_id])) {
            setMessage("预算删除成功", "success");
            header("Location: budget.php");
            exit();
        } else {
            setMessage("预算删除失败", "error");
        }
    } else {
        setMessage("预算不存在", "error");
    }
}

// 获取当前月份的预算
$stmt = $pdo->prepare("SELECT * FROM budgets WHERE user_id = ? AND month_year = ? ORDER BY category");
$stmt->execute([$user_id, $current_month]);
$budgets = $stmt->fetchAll();

// 计算每个预算的实际支出
$budget_data = [];
foreach ($budgets as $budget) {
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'expense' AND category = ? AND transaction_date >= ? AND transaction_date < ?");
    $next_month = date('Y-m-01', strtotime('+1 month'));
    $stmt->execute([$user_id, $budget['category'], $current_month . ' 00:00:00', $next_month . ' 00:00:00']);
    $actual = $stmt->fetch()['total'];
    
    $budget_data[] = [
        'id' => $budget['id'],
        'category' => $budget['category'],
        'budget' => $budget['amount'],
        'actual' => $actual,
        'remaining' => $budget['amount'] - $actual
    ];
}

// 获取支出分类
$expense_categories = getCategories('expense');
?>

<div class="card">
    <h2>预算管理 - <?php echo date('Y年m月'); ?></h2>
    
    <div class="budget-form">
        <h3>设置预算</h3>
        <form method="POST" action="">
            <div class="form-group">
                <label for="category">支出分类</label>
                <select id="category" name="category" required>
                    <option value="">请选择分类</option>
                    <?php foreach ($expense_categories as $cat): ?>
                    <option value="<?php echo safeOutput($cat); ?>"><?php echo safeOutput($cat); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="amount">预算金额</label>
                <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
            </div>
            
            <button type="submit">设置预算</button>
        </form>
    </div>
    
    <div class="budget-list">
        <h3>当前预算</h3>
        <?php if (!empty($budget_data)): ?>
            <?php foreach ($budget_data as $data): ?>
            <div class="budget-item">
                <div class="budget-header">
                    <h4><?php echo safeOutput($data['category']); ?></h4>
                    <div class="budget-actions">
                        <a href="budget.php?delete=<?php echo $data['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定要删除这个预算吗？')">删除</a>
                    </div>
                </div>
                
                <div class="budget-progress">
                    <div class="progress-bar">
                        <?php
                        $percentage = $data['budget'] > 0 ? ($data['actual'] / $data['budget']) * 100 : 0;
                        $progress_class = 'progress-fill';
                        if ($percentage > 100) {
                            $percentage = 100;
                            $progress_class = 'progress-danger';
                        } elseif ($percentage > 80) {
                            $progress_class = 'progress-warning';
                        }
                        ?>
                        <div class="<?php echo $progress_class; ?>" style="width: <?php echo $percentage; ?>%"></div>
                    </div>
                    <div class="budget-numbers">
                        <span>已支出: ￥<?php echo number_format($data['actual'], 2); ?></span>
                        <span>预算: ￥<?php echo number_format($data['budget'], 2); ?></span>
                        <span>剩余: ￥<?php echo number_format($data['remaining'], 2); ?></span>
                        <span>进度: <?php echo round($percentage, 1); ?>%</span>
                    </div>
                </div>
                
                <?php if ($percentage > 100): ?>
                <div class="alert alert-error" style="margin-top: 10px;">
                    已超出预算 ￥<?php echo number_format(abs($data['remaining']), 2); ?>！
                </div>
                <?php elseif ($percentage > 80): ?>
                <div class="alert alert-warning" style="margin-top: 10px;">
                    预算已使用 <?php echo round($percentage, 1); ?>%，请注意控制开支。
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>本月尚未设置任何预算。</p>
        <?php endif; ?>
    </div>
</div>

<style>
.budget-item {
    border: 1px solid #ddd;
    border-radius: 5px;
    padding: 15px;
    margin-bottom: 15px;
}

.budget-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 10px;
}

.budget-header h4 {
    margin: 0;
}

.budget-actions .btn-sm {
    padding: 5px 10px;
    font-size: 14px;
}

.budget-numbers {
    display: flex;
    justify-content: space-between;
    margin-top: 10px;
    flex-wrap: wrap;
}

.budget-numbers span {
    margin-right: 15px;
}

@media (max-width: 768px) {
    .budget-header {
        flex-direction: column;
        align-items: flex-start;
    }
    
    .budget-actions {
        margin-top: 10px;
    }
    
    .budget-numbers {
        flex-direction: column;
    }
    
    .budget-numbers span {
        margin-right: 0;
        margin-bottom: 5px;
    }
}
</style>

<?php
require_once 'includes/footer.php';
?>