<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php';

// 检查登录状态
if (!isLoggedIn()) {
    header("Location: index.php");
    exit();
}

$user_id = getCurrentUserId();

// 处理创建新账户
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['create_account'])) {
    $account_name = trim($_POST['account_name']);
    $account_type = trim($_POST['account_type']);
    $initial_balance = floatval($_POST['initial_balance']);
    
    // 验证输入
    $errors = [];
    
    if (empty($account_name)) {
        $errors[] = "账户名称不能为空";
    }
    
    if (empty($account_type)) {
        $errors[] = "账户类型不能为空";
    }
    
    // 检查账户名称是否已存在
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE user_id = ? AND account_name = ?");
    $stmt->execute([$user_id, $account_name]);
    if ($stmt->fetch()) {
        $errors[] = "账户名称已存在";
    }
    
    if (empty($errors)) {
        try {
            $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_name, account_type, balance) VALUES (?, ?, ?, ?)");
            if ($stmt->execute([$user_id, $account_name, $account_type, $initial_balance])) {
                setMessage("账户创建成功", "success");
                header("Location: account_management.php");
                exit();
            } else {
                $errors[] = "账户创建失败";
            }
        } catch (PDOException $e) {
            $errors[] = "数据库错误: " . $e->getMessage();
        }
    }
}

// 处理账户余额调整
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['adjust_balance'])) {
    $account_id = intval($_POST['account_id']);
    $adjustment_type = $_POST['adjustment_type'];
    $amount = floatval($_POST['amount']);
    $description = trim($_POST['description']);
    
    // 验证输入
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "金额必须大于0";
    }
    
    // 验证账户属于当前用户
    $stmt = $pdo->prepare("SELECT id FROM accounts WHERE id = ? AND user_id = ?");
    $stmt->execute([$account_id, $user_id]);
    
    if (!$stmt->fetch()) {
        $errors[] = "账户不存在";
    }
    
    if (empty($errors)) {
        try {
            $pdo->beginTransaction();
            
            // 更新账户余额
            if ($adjustment_type == 'increase') {
                $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
                    ->execute([$amount, $account_id]);
                $transaction_type = 'income';
            } else {
                $pdo->prepare("UPDATE accounts SET balance = balance - ? WHERE id = ?")
                    ->execute([$amount, $account_id]);
                $transaction_type = 'expense';
            }
            
            // 记录账户流水
            $pdo->prepare("INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date) VALUES (?, ?, ?, ?, 'balance_adjustment', 0, ?, NOW())")
                ->execute([$user_id, $account_id, $transaction_type, $amount, $description]);
            
            $pdo->commit();
            setMessage("账户余额调整成功", "success");
            header("Location: account_management.php");
            exit();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "调整失败: " . $e->getMessage();
        }
    }
}

// 获取用户账户列表
try {
    $stmt = $pdo->prepare("SELECT * FROM accounts WHERE user_id = ? ORDER BY created_at DESC");
    $stmt->execute([$user_id]);
    $accounts = $stmt->fetchAll();
    
    // 获取账户总余额
    $stmt = $pdo->prepare("SELECT SUM(balance) as total_balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_balance = $stmt->fetch()['total_balance'] ?? 0;
    
} catch (PDOException $e) {
    die("数据库查询错误: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>账户管理 - 个人记账系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .accounts-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .account-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #4b6cb7;
            transition: transform 0.2s;
        }
        
        .account-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .account-name {
            font-size: 1.2em;
            font-weight: bold;
            margin: 0 0 10px 0;
            color: #333;
        }
        
        .account-type {
            display: inline-block;
            padding: 3px 8px;
            background: #e9ecef;
            border-radius: 12px;
            font-size: 12px;
            color: #666;
            margin-bottom: 10px;
        }
        
        .account-balance {
            font-size: 1.5em;
            font-weight: bold;
            margin: 10px 0;
        }
        
        .positive {
            color: #2ecc71;
        }
        
        .negative {
            color: #e74c3c;
        }
        
        .create-form, .adjust-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 15px;
        }
        
        .total-balance {
            text-align: center;
            font-size: 1.3em;
            font-weight: bold;
            margin: 20px 0;
            padding: 15px;
            background: linear-gradient(135deg, #4b6cb7, #182848);
            color: white;
            border-radius: 8px;
        }
        
        .no-accounts {
            text-align: center;
            padding: 40px;
            background: #f8f9fa;
            border-radius: 8px;
            margin: 20px 0;
        }
        
        .no-accounts h3 {
            color: #666;
            margin-bottom: 15px;
        }
    </style>
</head>
<body>
    <?php if (isLoggedIn()): ?>
    <header>
        <div class="container">
            <div class="header-content">
                <h1>个人记账系统</h1>
                <div class="user-info">
                    欢迎, <?php echo safeOutput($_SESSION['username']); ?>!
                    <a href="logout.php" class="logout-btn">退出</a>
                </div>
            </div>
        </div>
    </header>
    
    <nav>
        <div class="container">
            <ul class="nav-menu">
                <li><a href="dashboard.php">仪表盘</a></li>
                <li><a href="add_record.php">添加记录</a></li>
                <li><a href="view_records.php">查看记录</a></li>
                <li><a href="budget.php">预算管理</a></li>
                <li><a href="friends_management.php">朋友借贷</a></li>
                <li><a href="account_management.php" class="active">账户管理</a></li>
                <li><a href="account_transactions.php">账户流水</a></li>
                <li><a href="reports.php">统计报表</a></li>
                <li><a href="export.php">数据导出</a></li>
                <li><a href="backup.php">数据备份</a></li>
            </ul>
        </div>
    </nav>
    <?php endif; ?>
    
    <main class="container">
        <?php 
        $message = getMessage();
        if ($message): ?>
        <div class="alert alert-<?php echo $message['type']; ?>">
            <?php echo safeOutput($message['message']); ?>
        </div>
        <?php endif; ?>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
            <p><?php echo safeOutput($error); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <div class="card">
            <h2>账户管理</h2>
            
            <div class="total-balance">
                总资产: ￥<?php echo number_format($total_balance, 2); ?>
            </div>
            
            <!-- 创建账户表单 -->
            <div class="create-form">
                <h3>创建新账户</h3>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="account_name">账户名称</label>
                            <input type="text" id="account_name" name="account_name" required 
                                   placeholder="例如：现金钱包、银行卡、支付宝等">
                        </div>
                        
                        <div class="form-group">
                            <label for="account_type">账户类型</label>
                            <input type="text" id="account_type" name="account_type" required 
                                   placeholder="例如：现金、银行卡、电子钱包等">
                        </div>
                        
                        <div class="form-group">
                            <label for="initial_balance">初始余额</label>
                            <input type="number" id="initial_balance" name="initial_balance" step="0.01" value="0.00" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="create_account" class="btn-success">创建账户</button>
                </form>
            </div>
            
            <!-- 账户列表 -->
            <?php if (!empty($accounts)): ?>
            <div class="accounts-grid">
                <?php foreach ($accounts as $account): ?>
                <div class="account-card">
                    <div class="account-name"><?php echo safeOutput($account['account_name']); ?></div>
                    <div class="account-type"><?php echo safeOutput($account['account_type']); ?></div>
                    <div class="account-balance <?php echo $account['balance'] >= 0 ? 'positive' : 'negative'; ?>">
                        ￥<?php echo number_format($account['balance'], 2); ?>
                    </div>
                    <div class="account-info">
                        <p>创建时间: <?php echo formatDateTime($account['created_at']); ?></p>
                        <p>最后更新: <?php echo formatDateTime($account['updated_at']); ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <div class="no-accounts">
                <h3>暂无账户</h3>
                <p>请先在上方创建您的第一个账户</p>
            </div>
            <?php endif; ?>
            
            <!-- 调整余额表单 -->
            <?php if (!empty($accounts)): ?>
            <div class="adjust-form">
                <h3>调整账户余额</h3>
                <form method="POST" action="">
                    <div class="form-row">
                        <div class="form-group">
                            <label for="account_id">选择账户</label>
                            <select id="account_id" name="account_id" required>
                                <option value="">请选择账户</option>
                                <?php foreach ($accounts as $account): ?>
                                <option value="<?php echo $account['id']; ?>">
                                    <?php echo safeOutput($account['account_name']); ?> (￥<?php echo number_format($account['balance'], 2); ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="adjustment_type">调整类型</label>
                            <select id="adjustment_type" name="adjustment_type" required>
                                <option value="increase">增加余额</option>
                                <option value="decrease">减少余额</option>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="amount">金额</label>
                            <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label for="description">说明</label>
                        <input type="text" id="description" name="description" placeholder="例如：初始资金、现金存入、取款等" required>
                    </div>
                    
                    <button type="submit" name="adjust_balance" class="btn-warning">确认调整</button>
                </form>
            </div>
            <?php endif; ?>
        </div>
    </main>
    
    <footer style="text-align: center; padding: 20px; margin-top: 30px; color: #666;">
        <p>个人记账系统 &copy; <?php echo date('Y'); ?> - 基于 PHP + MySQL 开发</p>
    </footer>
</body>
</html>