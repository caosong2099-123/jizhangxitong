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
$friend_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if (!$friend_id) {
    header("Location: friends_management.php");
    exit();
}

// 获取朋友信息
try {
    $stmt = $pdo->prepare("SELECT * FROM friends WHERE id = ? AND user_id = ?");
    $stmt->execute([$friend_id, $user_id]);
    $friend = $stmt->fetch();
    
    if (!$friend) {
        setMessage("朋友不存在", "error");
        header("Location: friends_management.php");
        exit();
    }
    
    // 获取该朋友的所有交易记录（按时间升序排列，便于计算累计余额）
    $stmt = $pdo->prepare("SELECT * FROM friend_transactions WHERE friend_id = ? ORDER BY transaction_date ASC, created_at ASC");
    $stmt->execute([$friend_id]);
    $transactions = $stmt->fetchAll();
    
    // 计算借贷统计和每笔交易后的余额
    $lend_total = 0;
    $repay_total = 0;
    $borrow_total = 0;
    $return_total = 0;
    
    // 计算每笔交易后的净欠款
    $running_balance = 0;
    foreach ($transactions as &$transaction) {
        switch ($transaction['type']) {
            case 'lend':
                $lend_total += $transaction['amount'];
                $running_balance -= $transaction['amount']; // 借出：我欠对方减少（对方欠我增加）
                break;
            case 'repay':
                $repay_total += $transaction['amount'];
                $running_balance += $transaction['amount']; // 还款：我欠对方增加（对方欠我减少）
                break;
            case 'borrow':
                $borrow_total += $transaction['amount'];
                $running_balance += $transaction['amount']; // 借入：我欠对方增加（对方欠我减少）
                break;
            case 'return':
                $return_total += $transaction['amount'];
                $running_balance -= $transaction['amount']; // 还钱：我欠对方减少（对方欠我增加）
                break;
        }
        
        // 保存每笔交易后的余额
        $transaction['balance_after'] = $running_balance;
    }
    unset($transaction); // 取消引用
    
    // 计算最终净欠款
    $net_amount = ($borrow_total - $return_total) - ($lend_total - $repay_total);
    
    // 确定借贷关系
    if ($net_amount > 0) {
        $relationship = 'owed'; // 对方欠我
    } elseif ($net_amount < 0) {
        $relationship = 'owe'; // 我欠对方
    } else {
        $relationship = 'settled'; // 已结清
    }
    
    // 计算所有账户的总余额变化
    // 获取当前所有账户的总余额
    $stmt = $pdo->prepare("SELECT SUM(balance) as total_balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_balance_result = $stmt->fetch();
    $total_balance = $total_balance_result['total_balance'] ?? 0;
    
    // 获取该朋友的所有交易记录（按时间倒序排列，便于计算余额变化）
    $stmt = $pdo->prepare("SELECT * FROM friend_transactions WHERE friend_id = ? ORDER BY transaction_date DESC, created_at DESC");
    $stmt->execute([$friend_id]);
    $friend_transactions = $stmt->fetchAll();
    
    // 计算每笔交易后的总余额
    $transactions_with_total_balance = [];
    foreach ($friend_transactions as $transaction) {
        $amount = $transaction['amount'];
        $type = $transaction['type'];
        
        // 根据交易类型确定金额方向
        $is_income = in_array($type, ['repay', 'borrow']);
        
        // 保存当前总余额到交易记录
        $transaction['total_balance_after'] = $total_balance;
        
        // 反向计算：从当前总余额减去这笔交易的影响，得到交易前的总余额
        if ($is_income) {
            $total_balance -= $amount;
        } else {
            $total_balance += $amount;
        }
        
        $transaction['total_balance_before'] = $total_balance;
        $transactions_with_total_balance[] = $transaction;
    }
    
    // 反转数组，让最早的在前面
    $transactions_with_total_balance = array_reverse($transactions_with_total_balance);
    
} catch (PDOException $e) {
    die("数据库查询错误: " . $e->getMessage());
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo safeOutput($friend['name']); ?> - 借贷详情 - 个人记账系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .friend-header {
            background: white;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .friend-name {
            font-size: 1.5em;
            font-weight: bold;
            margin: 0 0 10px 0;
        }
        
        .friend-net-amount {
            font-size: 1.8em;
            font-weight: bold;
            margin: 10px 0;
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
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        
        .stat-item {
            background: #f8f9fa;
            padding: 15px;
            border-radius: 5px;
            text-align: center;
        }
        
        .stat-value {
            font-size: 1.5em;
            font-weight: bold;
            margin: 5px 0;
        }
        
        .transaction-list {
            background: white;
            border-radius: 8px;
            padding: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .transaction-item {
            padding: 12px 0;
            border-bottom: 1px solid #eee;
        }
        
        .transaction-item:last-child {
            border-bottom: none;
        }
        
        .transaction-type {
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .type-lend, .type-return {
            background: #d4edda;
            color: #155724;
        }
        
        .type-borrow, .type-repay {
            background: #f8d7da;
            color: #721c24;
        }
        
        .transaction-amount {
            font-weight: bold;
        }
        
        .transaction-date {
            font-size: 0.9em;
            color: #777;
        }
        
        .transaction-description {
            margin-top: 5px;
            color: #555;
        }
        
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #4b6cb7;
            text-decoration: none;
        }
        
        .back-link:hover {
            text-decoration: underline;
        }
        
        .transaction-balance {
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
            margin-left: 10px;
        }
        
        .balance-owed {
            background: #d4edda;
            color: #155724;
        }
        
        .balance-owe {
            background: #f8d7da;
            color: #721c24;
        }
        
        .balance-settled {
            background: #e2e3e5;
            color: #383d41;
        }
        
        .transaction-details {
            margin-bottom: 8px;
        }
        
        .transaction-actions {
            display: flex;
            gap: 5px;
            margin-top: 8px;
        }
        
        .total-balance-info {
            background: #e8f5e8;
            padding: 8px;
            border-radius: 5px;
            margin: 8px 0;
            border-left: 4px solid #28a745;
            font-size: 12px;
        }
        
        .balance-before {
            color: #6c757d;
        }
        
        .balance-after {
            color: #28a745;
            font-weight: bold;
        }
        
        .balance-change {
            display: inline-block;
            margin: 0 5px;
            font-weight: bold;
        }
        
        .balance-increase {
            color: #28a745;
        }
        
        .balance-decrease {
            color: #dc3545;
        }
        
        .transaction-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
        }
        
        .transaction-main-info {
            flex: 1;
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
        
        <a href="friends_management.php" class="back-link">← 返回朋友列表</a>
        
        <div class="friend-header <?php echo $relationship; ?>">
            <h2 class="friend-name"><?php echo safeOutput($friend['name']); ?></h2>
            
            <?php if ($friend['phone']): ?>
            <p>电话: <?php echo safeOutput($friend['phone']); ?></p>
            <?php endif; ?>
            
            <?php if ($friend['note']): ?>
            <p>备注: <?php echo safeOutput($friend['note']); ?></p>
            <?php endif; ?>
            
            <div class="friend-net-amount">
                <?php 
                if ($relationship == 'owed') {
                    echo '对方欠我 ￥' . number_format($net_amount, 2);
                } elseif ($relationship == 'owe') {
                    echo '我欠对方 ￥' . number_format(abs($net_amount), 2);
                } else {
                    echo '借贷已结清';
                }
                ?>
            </div>
            
            <div class="stats-grid">
                <div class="stat-item">
                    <div>我借出总额</div>
                    <div class="stat-value">￥<?php echo number_format($lend_total, 2); ?></div>
                </div>
                <div class="stat-item">
                    <div>我还入总额</div>
                    <div class="stat-value">￥<?php echo number_format($repay_total, 2); ?></div>
                </div>
                <div class="stat-item">
                    <div>我借入总额</div>
                    <div class="stat-value">￥<?php echo number_format($borrow_total, 2); ?></div>
                </div>
                <div class="stat-item">
                    <div>我还出总额</div>
                    <div class="stat-value">￥<?php echo number_format($return_total, 2); ?></div>
                </div>
            </div>
        </div>
        
        <div class="transaction-list">
            <h3>全部交易记录 (<?php echo count($transactions); ?> 条)</h3>
            
            <?php if (!empty($transactions_with_total_balance)): ?>
                <?php foreach ($transactions_with_total_balance as $transaction): 
                    $is_income = in_array($transaction['type'], ['repay', 'borrow']);
                    $balance_change = $is_income ? $transaction['amount'] : -$transaction['amount'];
                ?>
                <div class="transaction-item">
                    <div class="transaction-header">
                        <div class="transaction-main-info">
                            <div class="transaction-details">
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
                                <span class="transaction-date"><?php echo formatDateTime($transaction['transaction_date']); ?></span>
                                
                                <!-- 显示交易后的借贷余额 -->
                                <span class="transaction-balance 
                                    <?php 
                                    // 找到对应的借贷余额
                                    $loan_balance = 0;
                                    foreach ($transactions as $t) {
                                        if ($t['id'] == $transaction['id']) {
                                            $loan_balance = $t['balance_after'];
                                            break;
                                        }
                                    }
                                    if ($loan_balance > 0) {
                                        echo 'balance-owed';
                                    } elseif ($loan_balance < 0) {
                                        echo 'balance-owe';
                                    } else {
                                        echo 'balance-settled';
                                    }
                                    ?>">
                                    <?php 
                                    if ($loan_balance > 0) {
                                        echo '我欠对方 ￥' . number_format($loan_balance, 2);
                                    } elseif ($loan_balance < 0) {
                                        echo '对方欠我 ￥' . number_format(abs($loan_balance), 2);
                                    } else {
                                        echo '已结清';
                                    }
                                    ?>
                                </span>
                            </div>
                            
                            <?php if ($transaction['description']): ?>
                            <div class="transaction-description"><?php echo safeOutput($transaction['description']); ?></div>
                            <?php endif; ?>
                            
                            <!-- 显示所有账户的总余额变化 -->
                            <div class="total-balance-info">
                                <span class="balance-before">交易前总资产: ￥<?php echo number_format($transaction['total_balance_before'], 2); ?></span>
                                <span class="balance-change <?php echo $is_income ? 'balance-increase' : 'balance-decrease'; ?>">
                                    <?php echo $is_income ? '↗ +' : '↘ -'; ?>
                                </span>
                                <span class="balance-after">交易后总资产: ￥<?php echo number_format($transaction['total_balance_after'], 2); ?></span>
                            </div>
                        </div>
                        
                        <div class="transaction-actions">
                            <a href="friends_management.php?delete_transaction=<?php echo $transaction['id']; ?>" class="btn btn-danger btn-sm" onclick="return confirm('确定要删除这条记录吗？')">删除</a>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php else: ?>
                <p>暂无交易记录</p>
            <?php endif; ?>
        </div>
    </main>
    
    <footer style="text-align: center; padding: 20px; margin-top: 30px; color: #666;">
        <p>个人记账系统 &copy; <?php echo date('Y'); ?> - 基于 PHP + MySQL 开发</p>
    </footer>
</body>
</html>