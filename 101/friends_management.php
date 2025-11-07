[file name]: friends_management.php
[file content begin]
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

// 获取用户账户列表
try {
    $stmt = $pdo->prepare("SELECT id, account_name, account_type, balance FROM accounts WHERE user_id = ? ORDER BY account_type");
    $stmt->execute([$user_id]);
    $accounts = $stmt->fetchAll();
} catch (PDOException $e) {
    die("数据库查询错误: " . $e->getMessage());
}

// 处理添加朋友
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_friend'])) {
    $name = trim($_POST['name']);
    $phone = trim($_POST['phone']);
    $note = trim($_POST['note']);
    
    // 验证输入
    $errors = [];
    
    if (empty($name)) {
        $errors[] = "请输入朋友姓名";
    }
    
    if (empty($errors)) {
        try {
            // 检查是否已存在同名朋友
            $stmt = $pdo->prepare("SELECT id FROM friends WHERE user_id = ? AND name = ?");
            $stmt->execute([$user_id, $name]);
            
            if ($stmt->fetch()) {
                $errors[] = "已存在同名朋友";
            } else {
                // 插入朋友记录
                $stmt = $pdo->prepare("INSERT INTO friends (user_id, name, phone, note) VALUES (?, ?, ?, ?)");
                
                if ($stmt->execute([$user_id, $name, $phone, $note])) {
                    setMessage("朋友添加成功", "success");
                    header("Location: friends_management.php");
                    exit();
                } else {
                    $errors[] = "添加朋友失败";
                }
            }
        } catch (PDOException $e) {
            $errors[] = "数据库错误: " . $e->getMessage();
        }
    }
}

// 处理添加借贷记录
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['add_transaction'])) {
    $friend_id = intval($_POST['friend_id']);
    $type = $_POST['type'];
    $amount = floatval($_POST['amount']);
    $account_id = intval($_POST['account_id']);
    $description = trim($_POST['description']);
    $transaction_date = $_POST['transaction_date'];
    $transaction_time = $_POST['transaction_time'];
    
    // 组合日期和时间
    $datetime = $transaction_date . ' ' . $transaction_time . ':00';
    
    // 验证输入
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "金额必须大于0";
    }
    
    if ($account_id <= 0) {
        $errors[] = "请选择账户";
    }
    
    if (!isValidDateTime($datetime)) {
        $errors[] = "请输入有效的日期和时间";
    }
    
    // 验证账户属于当前用户且余额足够（对于借出和还钱操作）
    if ($account_id > 0 && in_array($type, ['lend', 'return'])) {
        $stmt = $pdo->prepare("SELECT id, balance FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$account_id, $user_id]);
        $account = $stmt->fetch();
        
        if (!$account) {
            $errors[] = "账户不存在";
        } elseif ($account['balance'] < $amount) {
            $errors[] = "账户余额不足";
        }
    }
    
    if (empty($errors)) {
        try {
            // 验证朋友属于当前用户
            $stmt = $pdo->prepare("SELECT id FROM friends WHERE id = ? AND user_id = ?");
            $stmt->execute([$friend_id, $user_id]);
            
            if (!$stmt->fetch()) {
                $errors[] = "朋友不存在";
            } else {
                $pdo->beginTransaction();
                
                // 插入借贷记录
                $stmt = $pdo->prepare("INSERT INTO friend_transactions (user_id, account_id, friend_id, type, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $account_id, $friend_id, $type, $amount, $description, $datetime]);
                $transaction_id = $pdo->lastInsertId();
                
                // 更新账户余额和记录流水
                $transaction_type_map = [
                    'lend' => 'lend',
                    'repay' => 'repay', 
                    'borrow' => 'borrow',
                    'return' => 'return'
                ];
                
                $account_effect = [
                    'lend' => -1,      // 借出：账户减少
                    'repay' => 1,      // 还款：账户增加  
                    'borrow' => 1,     // 借入：账户增加
                    'return' => -1     // 还钱：账户减少
                ];
                
                $amount_change = $amount * $account_effect[$type];
                $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
                    ->execute([$amount_change, $account_id]);
                    
                // 记录账户流水
                $pdo->prepare("INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date) VALUES (?, ?, ?, ?, 'friend_transactions', ?, ?, ?)")
                    ->execute([$user_id, $account_id, $transaction_type_map[$type], $amount, $transaction_id, $description, $datetime]);
                
                $pdo->commit();
                setMessage("借贷记录添加成功", "success");
                header("Location: friends_management.php");
                exit();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "数据库错误: " . $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "操作失败: " . $e->getMessage();
        }
    }
}

// 处理快速添加债务操作
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['quick_transaction'])) {
    $friend_id = intval($_POST['friend_id']);
    $action = $_POST['action'];
    $account_id = intval($_POST['account_id']);
    $amount_str = $_POST['amount'];
    $description = trim($_POST['description']);
    
    // 修复金额处理 - 确保正确处理带小数点的金额
    $amount = floatval(str_replace(',', '', $amount_str));
    
    // 根据操作确定交易类型
    switch ($action) {
        case 'lend':
            $type = 'lend';
            $default_desc = '借出给朋友';
            break;
        case 'repay':
            $type = 'repay';
            $default_desc = '朋友还款';
            break;
        case 'borrow':
            $type = 'borrow';
            $default_desc = '向朋友借款';
            break;
        case 'return':
            $type = 'return';
            $default_desc = '还给朋友';
            break;
        default:
            $errors[] = "无效的操作类型";
            break;
    }
    
    if (empty($description)) {
        $description = $default_desc;
    }
    
    // 验证输入
    $errors = [];
    
    if ($amount <= 0) {
        $errors[] = "金额必须大于0";
    }
    
    if ($account_id <= 0) {
        $errors[] = "请选择账户";
    }
    
    // 验证账户属于当前用户且余额足够（对于借出和还钱操作）
    if ($account_id > 0 && in_array($type, ['lend', 'return'])) {
        $stmt = $pdo->prepare("SELECT id, balance FROM accounts WHERE id = ? AND user_id = ?");
        $stmt->execute([$account_id, $user_id]);
        $account = $stmt->fetch();
        
        if (!$account) {
            $errors[] = "账户不存在";
        } elseif ($account['balance'] < $amount) {
            $errors[] = "账户余额不足";
        }
    }
    
    if (empty($errors)) {
        try {
            // 验证朋友属于当前用户
            $stmt = $pdo->prepare("SELECT id FROM friends WHERE id = ? AND user_id = ?");
            $stmt->execute([$friend_id, $user_id]);
            
            if (!$stmt->fetch()) {
                $errors[] = "朋友不存在";
            } else {
                $pdo->beginTransaction();
                
                // 使用当前时间
                $datetime = date('Y-m-d H:i:s');
                
                // 插入借贷记录
                $stmt = $pdo->prepare("INSERT INTO friend_transactions (user_id, account_id, friend_id, type, amount, description, transaction_date) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$user_id, $account_id, $friend_id, $type, $amount, $description, $datetime]);
                $transaction_id = $pdo->lastInsertId();
                
                // 更新账户余额和记录流水
                $transaction_type_map = [
                    'lend' => 'lend',
                    'repay' => 'repay', 
                    'borrow' => 'borrow',
                    'return' => 'return'
                ];
                
                $account_effect = [
                    'lend' => -1,      // 借出：账户减少
                    'repay' => 1,      // 还款：账户增加  
                    'borrow' => 1,     // 借入：账户增加
                    'return' => -1     // 还钱：账户减少
                ];
                
                $amount_change = $amount * $account_effect[$type];
                $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
                    ->execute([$amount_change, $account_id]);
                    
                // 记录账户流水
                $pdo->prepare("INSERT INTO account_transactions (user_id, account_id, transaction_type, amount, related_table, related_id, description, transaction_date) VALUES (?, ?, ?, ?, 'friend_transactions', ?, ?, ?)")
                    ->execute([$user_id, $account_id, $transaction_type_map[$type], $amount, $transaction_id, $description, $datetime]);
                
                $pdo->commit();
                setMessage("债务操作成功", "success");
                header("Location: friends_management.php");
                exit();
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            $errors[] = "数据库错误: " . $e->getMessage();
        } catch (Exception $e) {
            $pdo->rollBack();
            $errors[] = "操作失败: " . $e->getMessage();
        }
    }
}

// 处理删除朋友
if (isset($_GET['delete_friend'])) {
    $friend_id = intval($_GET['delete_friend']);
    
    try {
        // 验证朋友属于当前用户
        $stmt = $pdo->prepare("SELECT id FROM friends WHERE id = ? AND user_id = ?");
        $stmt->execute([$friend_id, $user_id]);
        
        if ($stmt->fetch()) {
            // 删除朋友（由于外键约束，相关交易记录也会被删除）
            $stmt = $pdo->prepare("DELETE FROM friends WHERE id = ?");
            if ($stmt->execute([$friend_id])) {
                setMessage("朋友删除成功", "success");
                header("Location: friends_management.php");
                exit();
            } else {
                setMessage("删除失败", "error");
            }
        } else {
            setMessage("朋友不存在", "error");
        }
    } catch (PDOException $e) {
        setMessage("数据库错误: " . $e->getMessage(), "error");
    }
}

// 处理删除交易记录
if (isset($_GET['delete_transaction'])) {
    $transaction_id = intval($_GET['delete_transaction']);
    
    try {
        // 验证交易记录属于当前用户
        $stmt = $pdo->prepare("SELECT ft.id, ft.account_id, ft.type, ft.amount FROM friend_transactions ft 
                              JOIN friends f ON ft.friend_id = f.id 
                              WHERE ft.id = ? AND f.user_id = ?");
        $stmt->execute([$transaction_id, $user_id]);
        $transaction = $stmt->fetch();
        
        if ($transaction) {
            $pdo->beginTransaction();
            
            // 恢复账户余额
            $account_effect = [
                'lend' => 1,      // 删除借出：账户增加
                'repay' => -1,    // 删除还款：账户减少  
                'borrow' => -1,   // 删除借入：账户减少
                'return' => 1     // 删除还钱：账户增加
            ];
            
            $amount_change = $transaction['amount'] * $account_effect[$transaction['type']];
            $pdo->prepare("UPDATE accounts SET balance = balance + ? WHERE id = ?")
                ->execute([$amount_change, $transaction['account_id']]);
                
            // 删除账户流水记录
            $pdo->prepare("DELETE FROM account_transactions WHERE related_table = 'friend_transactions' AND related_id = ?")
                ->execute([$transaction_id]);
            
            // 删除交易记录
            $stmt = $pdo->prepare("DELETE FROM friend_transactions WHERE id = ?");
            if ($stmt->execute([$transaction_id])) {
                $pdo->commit();
                setMessage("交易记录删除成功", "success");
                header("Location: friends_management.php");
                exit();
            } else {
                $pdo->rollBack();
                setMessage("删除失败", "error");
            }
        } else {
            setMessage("记录不存在", "error");
        }
    } catch (PDOException $e) {
        $pdo->rollBack();
        setMessage("数据库错误: " . $e->getMessage(), "error");
    }
}

// 获取所有朋友及其借贷信息
try {
    // 获取朋友列表 - 确保只获取当前用户的
    $stmt = $pdo->prepare("SELECT * FROM friends WHERE user_id = ? ORDER BY name");
    $stmt->execute([$user_id]);
    $friends = $stmt->fetchAll();
    
    // 为每个朋友计算借贷情况
    foreach ($friends as &$friend) {
        // 获取该朋友的所有交易记录 - 确保通过朋友关联到当前用户
        $stmt = $pdo->prepare("SELECT ft.*, a.account_name FROM friend_transactions ft 
                              JOIN friends f ON ft.friend_id = f.id 
                              LEFT JOIN accounts a ON ft.account_id = a.id
                              WHERE ft.friend_id = ? AND f.user_id = ? 
                              ORDER BY ft.transaction_date DESC, ft.created_at DESC");
        $stmt->execute([$friend['id'], $user_id]);
        $friend['transactions'] = $stmt->fetchAll();
        
        // 计算借贷统计
        $lend_total = 0;
        $repay_total = 0;
        $borrow_total = 0;
        $return_total = 0;
        
        foreach ($friend['transactions'] as $transaction) {
            switch ($transaction['type']) {
                case 'lend':
                    $lend_total += $transaction['amount'];
                    break;
                case 'repay':
                    $repay_total += $transaction['amount'];
                    break;
                case 'borrow':
                    $borrow_total += $transaction['amount'];
                    break;
                case 'return':
                    $return_total += $transaction['amount'];
                    break;
            }
        }
        
        $friend['lend_total'] = $lend_total;
        $friend['repay_total'] = $repay_total;
        $friend['borrow_total'] = $borrow_total;
        $friend['return_total'] = $return_total;
        
        // 计算净欠款
        $friend['net_amount'] = ($borrow_total - $return_total) - ($lend_total - $repay_total);
        
        // 确定借贷关系
        if ($friend['net_amount'] > 0) {
            $friend['relationship'] = 'owed'; // 我欠对方
        } elseif ($friend['net_amount'] < 0) {
            $friend['relationship'] = 'owe'; // 对方欠我
        } else {
            $friend['relationship'] = 'settled'; // 已结清
        }
    }
    unset($friend); // 取消引用
    
} catch (PDOException $e) {
    die("数据库查询错误: " . $e->getMessage());
}

// 设置默认日期为今天
$default_date = date('Y-m-d');
$default_time = date('H:i');
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>朋友借贷管理 - 个人记账系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .friends-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .friend-card {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            border-left: 4px solid #4b6cb7;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        
        .friend-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        }
        
        .friend-card.owed {
            border-left-color: #2ecc71;
        }
        
        .friend-card.owe {
            border-left-color: #e74c3c;
        }
        
        .friend-card.settled {
            border-left-color: #95a5a6;
        }
        
        .friend-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .friend-name {
            font-size: 1.2em;
            font-weight: bold;
            margin: 0;
            cursor: pointer;
            color: #4b6cb7;
            transition: color 0.2s;
            padding: 5px 10px;
            border-radius: 4px;
        }
        
        .friend-name:hover {
            background-color: #f0f4ff;
            color: #3a56a8;
        }
        
        .friend-net-amount {
            font-size: 1.3em;
            font-weight: bold;
        }
        
        .owed .friend-net-amount {
            color: #2ecc71;
        }
        
        .owe .friend-net-amount {
            color: #e74c3c;
        }
        
        .settled .friend-net-amount {
            color: #95a5a6;
        }
        
        .friend-details {
            margin-bottom: 15px;
        }
        
        .friend-actions {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .transaction-list {
            max-height: 200px;
            overflow-y: auto;
            border: 1px solid #eee;
            border-radius: 5px;
            padding: 10px;
        }
        
        .transaction-item {
            display: flex;
            justify-content: space-between;
            padding: 8px 0;
            border-bottom: 1px solid #f5f5f5;
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-type {
            font-weight: bold;
        }
        
        .type-lend, .type-return {
            color: #2ecc71;
        }
        
        .type-borrow, .type-repay {
            color: #e74c3c;
        }
        
        .transaction-amount {
            font-weight: bold;
        }
        
        .transaction-date {
            font-size: 0.9em;
            color: #777;
        }
        
        .transaction-account {
            font-size: 0.8em;
            color: #999;
            margin-left: 5px;
        }
        
        .form-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
        }
        
        .form-tabs {
            display: flex;
            margin-bottom: 20px;
            border-bottom: 1px solid #ddd;
        }
        
        .form-tab {
            padding: 10px 20px;
            background: none;
            border: none;
            cursor: pointer;
            border-bottom: 3px solid transparent;
        }
        
        .form-tab.active {
            border-bottom-color: #4b6cb7;
            color: #4b6cb7;
            font-weight: bold;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .stat-item {
            background: white;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .owed-stat {
            color: #2ecc71;
        }
        
        .owe-stat {
            color: #e74c3c;
        }
        
        .btn-sm {
            padding: 5px 10px;
            font-size: 14px;
        }
        
        /* 模态框样式 - 修复定位问题 */
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
            overflow-y: auto;
            padding: 20px 0;
        }
        
        .modal-content {
            background-color: white;
            margin: 0 auto;
            padding: 30px;
            border-radius: 8px;
            width: 90%;
            max-width: 500px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.15);
            position: relative;
            top: 50%;
            transform: translateY(-50%);
            max-height: 90vh;
            overflow-y: auto;
        }
        
        .close {
            position: absolute;
            right: 15px;
            top: 15px;
            font-size: 24px;
            font-weight: bold;
            cursor: pointer;
            color: #999;
            z-index: 1001;
        }
        
        .close:hover {
            color: #333;
        }
        
        .modal-title {
            margin-top: 0;
            margin-bottom: 20px;
            color: #4b6cb7;
        }
        
        .quick-actions {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .quick-action-btn {
            padding: 15px;
            border: 2px solid #e0e0e0;
            border-radius: 8px;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
            text-align: center;
        }
        
        .quick-action-btn:hover {
            border-color: #4b6cb7;
            background: #f0f4ff;
            transform: translateY(-2px);
        }
        
        .quick-action-btn.active {
            border-color: #4b6cb7;
            background: #4b6cb7;
            color: white;
        }
        
        .action-icon {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
        }
        
        .action-lend { color: #2ecc71; }
        .action-repay { color: #27ae60; }
        .action-borrow { color: #e74c3c; }
        .action-return { color: #c0392b; }
        
        .quick-action-btn.active .action-icon {
            color: white;
        }
        
        .quick-form {
            display: none;
        }
        
        .quick-form.active {
            display: block;
        }
        
        .friend-info {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
            border-left: 4px solid #4b6cb7;
        }
        
        /* 自动消失的提示框样式 */
        .auto-hide-alert {
            position: fixed;
            top: 20px;
            right: 20px;
            padding: 15px 20px;
            border-radius: 5px;
            color: white;
            font-weight: bold;
            z-index: 10000;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
            animation: slideIn 0.3s ease-out;
        }
        
        .auto-hide-alert.success {
            background-color: #2ecc71;
        }
        
        .auto-hide-alert.error {
            background-color: #e74c3c;
        }
        
        @keyframes slideIn {
            from {
                transform: translateX(100%);
                opacity: 0;
            }
            to {
                transform: translateX(0);
                opacity: 1;
            }
        }
        
        @keyframes slideOut {
            from {
                transform: translateX(0);
                opacity: 1;
            }
            to {
                transform: translateX(100%);
                opacity: 0;
            }
        }
        
        .account-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 10px;
            margin-left: 5px;
        }
        
        .badge-cash {
            background: #2ecc71;
            color: white;
        }
        
        .badge-huiwang {
            background: #e74c3c;
            color: white;
        }
        
        .badge-aba {
            background: #3498db;
            color: white;
        }

        /* 响应式设计 */
        @media (max-width: 768px) {
            .modal-content {
                width: 95%;
                padding: 20px;
                margin: 10px auto;
            }
            
            .quick-actions {
                grid-template-columns: 1fr;
            }
            
            .friends-grid {
                grid-template-columns: 1fr;
            }
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
                <li><a href="friends_management.php" class="active">朋友借贷</a></li>
                <li><a href="account_management.php">账户管理</a></li>
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
            <h2>朋友借贷管理</h2>
            
            <!-- 统计信息 -->
            <div class="stats-grid">
                <div class="stat-item">
                    <div>朋友总数</div>
                    <div class="stat-value"><?php echo count($friends); ?></div>
                </div>
                <?php
                $owed_total = 0;
                $owe_total = 0;
                $settled_count = 0;
                
                foreach ($friends as $friend) {
                    if ($friend['relationship'] == 'owed') {
                        $owed_total += $friend['net_amount'];
                    } elseif ($friend['relationship'] == 'owe') {
                        $owe_total += abs($friend['net_amount']);
                    } else {
                        $settled_count++;
                    }
                }
                ?>
                <div class="stat-item">
                    <div>待付总额</div>
                    <div class="stat-value owed-stat">￥<?php echo number_format($owed_total, 2); ?></div>
                </div>
                <div class="stat-item">
                    <div>待收总额</div>
                    <div class="stat-value owe-stat">￥<?php echo number_format($owe_total, 2); ?></div>
                </div>
                <div class="stat-item">
                    <div>已结清</div>
                    <div class="stat-value"><?php echo $settled_count; ?> 人</div>
                </div>
            </div>
            
            <!-- 快速操作提示 -->
            <div style="background: #e8f4fd; padding: 15px; border-radius: 5px; margin-bottom: 20px; border-left: 4px solid #4b6cb7;">
                <strong>💡 快速操作提示：</strong> 点击朋友姓名可以快速进行债务操作
            </div>
            
            <!-- 表单区域 -->
            <div class="form-container">
                <div class="form-tabs">
                    <button class="form-tab active" data-tab="add-friend">添加朋友</button>
                    <button class="form-tab" data-tab="add-transaction">添加借贷记录</button>
                </div>
                
                <!-- 添加朋友表单 -->
                <div id="add-friend" class="tab-content active">
                    <h3>添加朋友</h3>
                    <form method="POST" action="">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                            <div class="form-group">
                                <label for="name">朋友姓名</label>
                                <input type="text" id="name" name="name" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="phone">电话号码（可选）</label>
                                <input type="text" id="phone" name="phone">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="note">备注（可选）</label>
                            <textarea id="note" name="note" rows="2" placeholder="例如：同事、同学等关系说明"></textarea>
                        </div>
                        
                        <button type="submit" name="add_friend">添加朋友</button>
                    </form>
                </div>
                
                <!-- 添加借贷记录表单 -->
                <div id="add-transaction" class="tab-content">
                    <h3>添加借贷记录</h3>
                    <form method="POST" action="">
                        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                            <div class="form-group">
                                <label for="friend_id">选择朋友</label>
                                <select id="friend_id" name="friend_id" required>
                                    <option value="">请选择朋友</option>
                                    <?php foreach ($friends as $friend): ?>
                                    <option value="<?php echo $friend['id']; ?>"><?php echo safeOutput($friend['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="account_id">资金账户</label>
                                <select id="account_id" name="account_id" required>
                                    <option value="">请选择账户</option>
                                    <?php foreach ($accounts as $account): ?>
                                    <option value="<?php echo $account['id']; ?>" data-balance="<?php echo $account['balance']; ?>">
                                        <?php echo safeOutput($account['account_name']); ?> (余额: ￥<?php echo number_format($account['balance'], 2); ?>)
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="type">交易类型</label>
                                <select id="type" name="type" required>
                                    <option value="">请选择类型</option>
                                    <option value="lend">借出（我借给朋友）</option>
                                    <option value="repay">还款（朋友还我钱）</option>
                                    <option value="borrow">借入（我向朋友借）</option>
                                    <option value="return">还钱（我还朋友钱）</option>
                                </select>
                            </div>
                            
                            <div class="form-group">
                                <label for="amount">金额</label>
                                <input type="number" id="amount" name="amount" step="0.01" min="0.01" required>
                            </div>
                            
                            <div class="form-group">
                                <label for="transaction_date">交易日期</label>
                                <input type="date" id="transaction_date" name="transaction_date" required value="<?php echo $default_date; ?>">
                            </div>
                            
                            <div class="form-group">
                                <label for="transaction_time">交易时间</label>
                                <input type="time" id="transaction_time" name="transaction_time" required value="<?php echo $default_time; ?>">
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">交易说明</label>
                            <textarea id="description" name="description" rows="2" placeholder="例如：借款用途、还款方式等"></textarea>
                        </div>
                        
                        <button type="submit" name="add_transaction">添加记录</button>
                    </form>
                </div>
            </div>
            
            <!-- 朋友列表 -->
            <h3>朋友借贷情况</h3>
            <?php if (!empty($friends)): ?>
            <div class="friends-grid">
                <?php foreach ($friends as $friend): ?>
                <div class="friend-card <?php echo $friend['relationship']; ?>">
                    <div class="friend-header">
                        <h3 class="friend-name" data-friend-id="<?php echo $friend['id']; ?>" 
                            data-friend-name="<?php echo safeOutput($friend['name']); ?>"
                            data-net-amount="<?php echo $friend['net_amount']; ?>"
                            data-relationship="<?php echo $friend['relationship']; ?>">
                            <?php echo safeOutput($friend['name']); ?>
                        </h3>
                        <div class="friend-net-amount">
                            <?php 
                            if ($friend['relationship'] == 'owed') {
                                echo '欠他 ￥' . number_format($friend['net_amount'], 2);
                            } elseif ($friend['relationship'] == 'owe') {
                                echo '欠我 ￥' . number_format(abs($friend['net_amount']), 2);
                            } else {
                                echo '已结清';
                            }
                            ?>
                        </div>
                    </div>
                    
                    <div class="friend-details">
                        <?php if ($friend['phone']): ?>
                        <p>电话: <?php echo safeOutput($friend['phone']); ?></p>
                        <?php endif; ?>
                        
                        <?php if ($friend['note']): ?>
                        <p>备注: <?php echo safeOutput($friend['note']); ?></p>
                        <?php endif; ?>
                        
                        <div class="friend-stats">
                            <p>我借出: ￥<?php echo number_format($friend['lend_total'], 2); ?></p>
                            <p>我还入: ￥<?php echo number_format($friend['repay_total'], 2); ?></p>
                            <p>我借入: ￥<?php echo number_format($friend['borrow_total'], 2); ?></p>
                            <p>我还出: ￥<?php echo number_format($friend['return_total'], 2); ?></p>
                        </div>
                    </div>
                    
                    <div class="friend-actions">
                        <a href="friend_detail.php?id=<?php echo $friend['id']; ?>" class="btn">查看详情</a>
                        <a href="friends_management.php?delete_friend=<?php echo $friend['id']; ?>" class="btn btn-danger" onclick="return confirm('确定要删除这个朋友及其所有借贷记录吗？')">删除</a>
                    </div>
                    
                    <h4>最近交易记录</h4>
                    <div class="transaction-list">
                        <?php if (!empty($friend['transactions'])): ?>
                            <?php 
                            $recent_transactions = array_slice($friend['transactions'], 0, 5); // 只显示最近5条
                            foreach ($recent_transactions as $transaction): 
                            ?>
                            <div class="transaction-item">
                                <div>
                                    <span class="transaction-type type-<?php echo $transaction['type']; ?>">
                                        <?php 
                                        switch ($transaction['type']) {
                                            case 'lend': echo '借出'; break;
                                            case 'repay': echo '还款'; break;
                                            case 'borrow': echo '借入'; break;
                                            case 'return': echo '还钱'; break;
                                        }
                                        ?>
                                    </span>
                                    <span class="transaction-amount">￥<?php echo number_format($transaction['amount'], 2); ?></span>
                                    <div class="transaction-date">
                                        <?php echo formatDateTime($transaction['transaction_date']); ?>
                                        <?php if ($transaction['account_name']): ?>
                                        <span class="transaction-account">
                                            (<?php echo safeOutput($transaction['account_name']); ?>)
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div>
                                    <a href="friends_management.php?delete_transaction=<?php echo $transaction['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定要删除这条记录吗？')">删除</a>
                                </div>
                            </div>
                            <?php endforeach; ?>
                            
                            <?php if (count($friend['transactions']) > 5): ?>
                            <p style="text-align: center; margin-top: 10px;">
                                <a href="friend_detail.php?id=<?php echo $friend['id']; ?>">查看全部记录 (<?php echo count($friend['transactions']); ?> 条)</a>
                            </p>
                            <?php endif; ?>
                        <?php else: ?>
                            <p>暂无交易记录</p>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php else: ?>
            <p>暂无朋友记录。请先添加朋友。</p>
            <?php endif; ?>
        </div>
    </main>
    
    <!-- 快速操作模态框 -->
    <div id="quickActionModal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h3 class="modal-title">快速债务操作</h3>
            
            <div class="friend-info" id="modalFriendInfo">
                <!-- 朋友信息将在这里动态填充 -->
            </div>
            
            <div class="quick-actions">
                <div class="quick-action-btn" data-action="lend">
                    <span class="action-icon action-lend">💰</span>
                    <div>我借出</div>
                    <small>借钱给朋友</small>
                </div>
                <div class="quick-action-btn" data-action="repay">
                    <span class="action-icon action-repay">💵</span>
                    <div>朋友还款</div>
                    <small>朋友还钱给我</small>
                </div>
                <div class="quick-action-btn" data-action="borrow">
                    <span class="action-icon action-borrow">📝</span>
                    <div>我借入</div>
                    <small>向朋友借钱</small>
                </div>
                <div class="quick-action-btn" data-action="return">
                    <span class="action-icon action-return">🔄</span>
                    <div>我还款</div>
                    <small>还钱给朋友</small>
                </div>
            </div>
            
            <form id="quickTransactionForm" method="POST" action="" novalidate>
                <input type="hidden" name="quick_transaction" value="1">
                <input type="hidden" id="quick_friend_id" name="friend_id">
                <input type="hidden" id="quick_action" name="action">
                
                <div class="form-group">
                    <label for="quick_account_id">资金账户</label>
                    <select id="quick_account_id" name="account_id" required>
                        <option value="">请选择账户</option>
                        <?php foreach ($accounts as $account): ?>
                        <option value="<?php echo $account['id']; ?>" data-balance="<?php echo $account['balance']; ?>">
                            <?php echo safeOutput($account['account_name']); ?> (余额: ￥<?php echo number_format($account['balance'], 2); ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <small id="quick_account_balance_info" style="display: none;"></small>
                </div>
                
                <div class="quick-form" id="lendForm">
                    <div class="form-group">
                        <label for="lend_amount">借出金额</label>
                        <input type="number" id="lend_amount" name="amount" step="0.01" min="0.01" placeholder="请输入金额" required>
                    </div>
                    <div class="form-group">
                        <label for="lend_description">借款说明（可选）</label>
                        <input type="text" id="lend_description" name="description" placeholder="例如：借款用途">
                    </div>
                    <button type="submit" class="btn-success">确认借出</button>
                </div>
                
                <div class="quick-form" id="repayForm">
                    <div class="form-group">
                        <label for="repay_amount">还款金额</label>
                        <input type="number" id="repay_amount" name="amount" step="0.01" min="0.01" placeholder="请输入金额" required>
                    </div>
                    <div class="form-group">
                        <label for="repay_description">还款说明（可选）</label>
                        <input type="text" id="repay_description" name="description" placeholder="例如：还款方式">
                    </div>
                    <button type="submit" class="btn-success">确认还款</button>
                </div>
                
                <div class="quick-form" id="borrowForm">
                    <div class="form-group">
                        <label for="borrow_amount">借款金额</label>
                        <input type="number" id="borrow_amount" name="amount" step="0.01" min="0.01" placeholder="请输入金额" required>
                    </div>
                    <div class="form-group">
                        <label for="borrow_description">借款说明（可选）</label>
                        <input type="text" id="borrow_description" name="description" placeholder="例如：借款用途">
                    </div>
                    <button type="submit" class="btn-success">确认借款</button>
                </div>
                
                <div class="quick-form" id="returnForm">
                    <div class="form-group">
                        <label for="return_amount">还款金额</label>
                        <input type="number" id="return_amount" name="amount" step="0.01" min="0.01" placeholder="请输入金额" required>
                    </div>
                    <div class="form-group">
                        <label for="return_description">还款说明（可选）</label>
                        <input type="text" id="return_description" name="description" placeholder="例如：还款方式">
                    </div>
                    <button type="submit" class="btn-success">确认还款</button>
                </div>
            </form>
        </div>
    </div>
    
    <footer style="text-align: center; padding: 20px; margin-top: 30px; color: #666;">
        <p>个人记账系统 &copy; <?php echo date('Y'); ?> - 基于 PHP + MySQL 开发</p>
    </footer>
    
    <script>
        // 标签页切换功能
        document.addEventListener('DOMContentLoaded', function() {
            const formTabs = document.querySelectorAll('.form-tab');
            const tabContents = document.querySelectorAll('.tab-content');
            
            formTabs.forEach(tab => {
                tab.addEventListener('click', function() {
                    const tabId = this.getAttribute('data-tab');
                    
                    // 移除所有active类
                    formTabs.forEach(t => t.classList.remove('active'));
                    tabContents.forEach(content => content.classList.remove('active'));
                    
                    // 添加active类到当前标签
                    this.classList.add('active');
                    document.getElementById(tabId).classList.add('active');
                });
            });
            
            // 金额输入框格式化
            const amountInputs = document.querySelectorAll('input[type="number"]');
            amountInputs.forEach(input => {
                input.addEventListener('blur', function() {
                    if (this.value) {
                        // 确保金额是有效的数字
                        let value = parseFloat(this.value);
                        if (!isNaN(value) && value > 0) {
                            this.value = value.toFixed(2);
                        }
                    }
                });
                
                // 实时验证金额输入
                input.addEventListener('input', function() {
                    let value = this.value;
                    // 移除非数字字符（除了小数点）
                    value = value.replace(/[^\d.]/g, '');
                    // 确保只有一个小数点
                    const parts = value.split('.');
                    if (parts.length > 2) {
                        value = parts[0] + '.' + parts.slice(1).join('');
                    }
                    // 限制小数点后最多两位
                    if (parts.length > 1 && parts[1].length > 2) {
                        value = parts[0] + '.' + parts[1].substring(0, 2);
                    }
                    this.value = value;
                });
            });
            
            // 快速操作模态框功能
            const modal = document.getElementById('quickActionModal');
            const closeBtn = document.querySelector('.close');
            const quickActionBtns = document.querySelectorAll('.quick-action-btn');
            const quickForms = document.querySelectorAll('.quick-form');
            const friendNameElements = document.querySelectorAll('.friend-name');
            const quickAccountSelect = document.getElementById('quick_account_id');
            const quickBalanceInfo = document.getElementById('quick_account_balance_info');
            
            let currentFriend = null;
            
            // 点击朋友名字打开模态框
            friendNameElements.forEach(element => {
                element.addEventListener('click', function() {
                    currentFriend = {
                        id: this.getAttribute('data-friend-id'),
                        name: this.getAttribute('data-friend-name'),
                        netAmount: parseFloat(this.getAttribute('data-net-amount')),
                        relationship: this.getAttribute('data-relationship')
                    };
                    
                    // 更新模态框中的朋友信息
                    updateFriendInfo();
                    
                    // 重置表单
                    resetQuickForms();
                    
                    // 显示模态框
                    modal.style.display = 'block';
                    
                    // 防止背景滚动
                    document.body.style.overflow = 'hidden';
                });
            });
            
            // 关闭模态框
            closeBtn.addEventListener('click', function() {
                modal.style.display = 'none';
                document.body.style.overflow = 'auto';
            });
            
            // 点击模态框外部关闭
            window.addEventListener('click', function(event) {
                if (event.target == modal) {
                    modal.style.display = 'none';
                    document.body.style.overflow = 'auto';
                }
            });
            
            // 快速操作按钮点击事件
            quickActionBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    const action = this.getAttribute('data-action');
                    
                    // 移除所有按钮的active类
                    quickActionBtns.forEach(b => b.classList.remove('active'));
                    // 添加active类到当前按钮
                    this.classList.add('active');
                    
                    // 隐藏所有表单
                    quickForms.forEach(form => {
                        form.classList.remove('active');
                    });
                    
                    // 显示对应的表单
                    const activeForm = document.getElementById(action + 'Form');
                    activeForm.classList.add('active');
                    
                    // 设置隐藏字段
                    document.getElementById('quick_friend_id').value = currentFriend.id;
                    document.getElementById('quick_action').value = action;
                    
                    // 根据借贷关系设置建议金额
                    setSuggestedAmount(action);
                    
                    // 更新账户余额信息
                    updateQuickAccountInfo();
                    
                    // 自动聚焦到金额输入框
                    const amountInput = activeForm.querySelector('input[type="number"]');
                    setTimeout(() => {
                        amountInput.focus();
                    }, 100);
                });
            });
            
            // 更新朋友信息显示
            function updateFriendInfo() {
                const friendInfo = document.getElementById('modalFriendInfo');
                let relationshipText = '';
                
                if (currentFriend.relationship === 'owed') {
                    relationshipText = `对方欠我 <strong style="color: #2ecc71;">￥${Math.abs(currentFriend.netAmount).toFixed(2)}</strong>`;
                } else if (currentFriend.relationship === 'owe') {
                    relationshipText = `我欠对方 <strong style="color: #e74c3c;">￥${Math.abs(currentFriend.netAmount).toFixed(2)}</strong>`;
                } else {
                    relationshipText = `<strong style="color: #95a5a6;">借贷已结清</strong>`;
                }
                
                friendInfo.innerHTML = `
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <strong>${currentFriend.name}</strong>
                            <div style="margin-top: 5px; font-size: 14px;">${relationshipText}</div>
                        </div>
                    </div>
                `;
            }
            
            // 重置所有快速操作表单
            function resetQuickForms() {
                quickActionBtns.forEach(btn => btn.classList.remove('active'));
                quickForms.forEach(form => {
                    const amountInput = form.querySelector('input[type="number"]');
                    const descInput = form.querySelector('input[type="text"]');
                    amountInput.value = '';
                    descInput.value = '';
                    form.classList.remove('active');
                });
                
                document.getElementById('quick_friend_id').value = '';
                document.getElementById('quick_action').value = '';
                quickAccountSelect.value = '';
                quickBalanceInfo.style.display = 'none';
            }
            
            // 根据借贷关系设置建议金额
            function setSuggestedAmount(action) {
                const activeForm = document.querySelector('.quick-form.active');
                if (!activeForm) return;
                
                const amountInput = activeForm.querySelector('input[type="number"]');
                
                if (currentFriend.netAmount !== 0) {
                    if ((action === 'repay' && currentFriend.relationship === 'owed') || 
                        (action === 'return' && currentFriend.relationship === 'owe')) {
                        // 如果是还款操作，自动填充建议金额
                        amountInput.value = Math.abs(currentFriend.netAmount).toFixed(2);
                        amountInput.placeholder = `建议金额: ￥${Math.abs(currentFriend.netAmount).toFixed(2)}`;
                    } else {
                        amountInput.value = '';
                        amountInput.placeholder = '请输入金额';
                    }
                } else {
                    amountInput.value = '';
                    amountInput.placeholder = '请输入金额';
                }
            }
            
            // 更新快速操作中的账户余额信息
            function updateQuickAccountInfo() {
                const selectedOption = quickAccountSelect.options[quickAccountSelect.selectedIndex];
                if (selectedOption.value && selectedOption.dataset.balance) {
                    const balance = parseFloat(selectedOption.dataset.balance);
                    const activeForm = document.querySelector('.quick-form.active');
                    const amountInput = activeForm ? activeForm.querySelector('input[type="number"]') : null;
                    const amount = amountInput ? parseFloat(amountInput.value) || 0 : 0;
                    const action = document.getElementById('quick_action').value;
                    
                    let message = `当前余额: ￥${balance.toFixed(2)}`;
                    
                    if (action && ['lend', 'return'].includes(action) && amount > 0) {
                        const remaining = balance - amount;
                        if (remaining < 0) {
                            message += ` ❌ 余额不足，还需 ￥${Math.abs(remaining).toFixed(2)}`;
                            quickBalanceInfo.style.color = 'red';
                        } else {
                            message += ` → 交易后余额: ￥${remaining.toFixed(2)}`;
                            quickBalanceInfo.style.color = 'green';
                        }
                    } else if (action && ['repay', 'borrow'].includes(action) && amount > 0) {
                        const newBalance = balance + amount;
                        message += ` → 交易后余额: ￥${newBalance.toFixed(2)}`;
                        quickBalanceInfo.style.color = 'green';
                    }
                    
                    quickBalanceInfo.textContent = message;
                    quickBalanceInfo.style.display = 'block';
                } else {
                    quickBalanceInfo.style.display = 'none';
                }
            }
            
            // 监听快速操作账户选择和金额输入
            quickAccountSelect.addEventListener('change', updateQuickAccountInfo);
            document.querySelectorAll('.quick-form input[type="number"]').forEach(input => {
                input.addEventListener('input', updateQuickAccountInfo);
            });
            
            // 显示自动消失的提示框
            function showAutoHideAlert(message, type) {
                const alertDiv = document.createElement('div');
                alertDiv.className = `auto-hide-alert ${type}`;
                alertDiv.textContent = message;
                document.body.appendChild(alertDiv);
                
                // 3秒后自动消失
                setTimeout(() => {
                    alertDiv.style.animation = 'slideOut 0.3s ease-out';
                    setTimeout(() => {
                        if (alertDiv.parentNode) {
                            document.body.removeChild(alertDiv);
                        }
                    }, 300);
                }, 3000);
            }
            
            // 表单提交前的验证
            document.getElementById('quickTransactionForm').addEventListener('submit', function(e) {
                e.preventDefault();
                
                const activeForm = document.querySelector('.quick-form.active');
                if (!activeForm) {
                    showAutoHideAlert('请选择一种操作类型', 'error');
                    return false;
                }
                
                const amountInput = activeForm.querySelector('input[type="number"]');
                const amount = amountInput.value;
                const accountId = quickAccountSelect.value;
                const action = document.getElementById('quick_action').value;
                
                // 更严格的金额验证
                if (!amount || amount.trim() === '') {
                    showAutoHideAlert('请输入金额', 'error');
                    amountInput.focus();
                    return false;
                }
                
                const amountNum = parseFloat(amount);
                if (isNaN(amountNum) || amountNum <= 0) {
                    showAutoHideAlert('金额必须大于0', 'error');
                    amountInput.focus();
                    return false;
                }
                
                // 账户验证
                if (!accountId) {
                    showAutoHideAlert('请选择账户', 'error');
                    quickAccountSelect.focus();
                    return false;
                }
                
                // 余额验证（对于借出和还钱操作）
                if (['lend', 'return'].includes(action)) {
                    const selectedOption = quickAccountSelect.options[quickAccountSelect.selectedIndex];
                    const balance = parseFloat(selectedOption.dataset.balance);
                    if (amountNum > balance) {
                        showAutoHideAlert('账户余额不足', 'error');
                        amountInput.focus();
                        return false;
                    }
                }
                
                // 确保金额格式正确
                amountInput.value = amountNum.toFixed(2);
                
                // 显示成功消息
                showAutoHideAlert('操作成功！', 'success');
                
                // 延迟提交表单，让用户看到成功消息
                setTimeout(() => {
                    // 创建新的表单数据对象，确保数据正确传递
                    const formData = new FormData();
                    formData.append('quick_transaction', '1');
                    formData.append('friend_id', document.getElementById('quick_friend_id').value);
                    formData.append('action', document.getElementById('quick_action').value);
                    formData.append('account_id', quickAccountSelect.value);
                    formData.append('amount', amountInput.value);
                    formData.append('description', activeForm.querySelector('input[type="text"]').value);
                    
                    // 使用fetch API提交表单，避免页面刷新
                    fetch('friends_management.php', {
                        method: 'POST',
                        body: formData
                    })
                    .then(response => {
                        if (response.ok) {
                            // 成功后刷新页面
                            window.location.reload();
                        } else {
                            showAutoHideAlert('操作失败，请重试', 'error');
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        showAutoHideAlert('网络错误，请重试', 'error');
                    });
                }, 1500);
                
                return false;
            });
            
            // 监听借贷记录表单中的账户选择和金额输入
            const accountSelect = document.getElementById('account_id');
            const amountField = document.getElementById('amount');
            const typeSelect = document.getElementById('type');
            
            function updateAccountBalanceInfo() {
                const selectedOption = accountSelect.options[accountSelect.selectedIndex];
                if (selectedOption.value && selectedOption.dataset.balance) {
                    const balance = parseFloat(selectedOption.dataset.balance);
                    const amount = parseFloat(amountField.value) || 0;
                    const type = typeSelect.value;
                    
                    let message = `当前余额: ￥${balance.toFixed(2)}`;
                    
                    if (type === 'lend' && amount > 0) {
                        const remaining = balance - amount;
                        if (remaining < 0) {
                            message += ` ❌ 余额不足，还需 ￥${Math.abs(remaining).toFixed(2)}`;
                        } else {
                            message += ` → 交易后余额: ￥${remaining.toFixed(2)}`;
                        }
                    } else if (type === 'repay' && amount > 0) {
                        const newBalance = balance + amount;
                        message += ` → 交易后余额: ￥${newBalance.toFixed(2)}`;
                    }
                    
                    // 可以在旁边显示提示信息
                    console.log(message); // 这里可以替换为在页面显示提示信息
                }
            }
            
            if (accountSelect && amountField && typeSelect) {
                accountSelect.addEventListener('change', updateAccountBalanceInfo);
                amountField.addEventListener('input', updateAccountBalanceInfo);
                typeSelect.addEventListener('change', updateAccountBalanceInfo);
            }
        });
    </script>
</body>
</html>
[file content end]