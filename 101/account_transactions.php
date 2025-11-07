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

// 获取筛选条件 - 设置30天默认值
$account_filter = isset($_GET['account_id']) ? intval($_GET['account_id']) : 0;
$type_filter = isset($_GET['type']) ? $_GET['type'] : '';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-d', strtotime('-30 days'));
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-d');
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100; // 默认显示100条

// 构建查询条件
$conditions = ["at.user_id = ?"];
$params = [$user_id];

if ($account_filter > 0) {
    $conditions[] = "at.account_id = ?";
    $params[] = $account_filter;
}

if (!empty($type_filter)) {
    $conditions[] = "at.transaction_type = ?";
    $params[] = $type_filter;
}

if (!empty($start_date)) {
    $conditions[] = "at.transaction_date >= ?";
    $params[] = $start_date . ' 00:00:00';
}

if (!empty($end_date)) {
    $conditions[] = "at.transaction_date <= ?";
    $params[] = $end_date . ' 23:59:59';
}

$where_clause = implode(' AND ', $conditions);

try {
    // 构建基础查询
    $sql = "
        SELECT at.*, a.account_name, a.account_type 
        FROM account_transactions at 
        JOIN accounts a ON at.account_id = a.id 
        WHERE $where_clause 
        ORDER BY at.transaction_date DESC, at.created_at DESC
    ";
    
    // 修复 LIMIT 子句的问题
    if ($limit > 0) {
        $sql .= " LIMIT " . intval($limit); // 直接拼接整数，避免参数绑定问题
    }
    
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $transactions = $stmt->fetchAll();

    // 获取账户列表用于筛选
    $stmt = $pdo->prepare("SELECT id, account_name, account_type, balance FROM accounts WHERE user_id = ? ORDER BY account_type");
    $stmt->execute([$user_id]);
    $accounts = $stmt->fetchAll();

    // 获取所有账户的初始总余额
    $stmt = $pdo->prepare("SELECT SUM(balance) as total_balance FROM accounts WHERE user_id = ?");
    $stmt->execute([$user_id]);
    $total_balance_result = $stmt->fetch();
    $total_balance = $total_balance_result['total_balance'] ?? 0;

    // 计算每笔交易后的总余额（所有账户的总计）
    $transactions_with_total_balance = [];
    foreach ($transactions as $transaction) {
        $amount = $transaction['amount'];
        $type = $transaction['transaction_type'];
        
        // 根据交易类型确定金额方向
        $is_income = in_array($type, ['income', 'repay', 'return', 'borrow']);
        
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
    <title>账户流水 - 个人记账系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        .transaction-type {
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .type-income {
            background: #d4edda;
            color: #155724;
        }
        
        .type-expense {
            background: #f8d7da;
            color: #721c24;
        }
        
        .type-lend, .type-borrow, .type-repay, .type-return {
            background: #fff3cd;
            color: #856404;
        }
        
        .account-badge {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 12px;
            font-size: 12px;
            margin-left: 5px;
            color: white;
        }
        
        .badge-cash {
            background: #2ecc71;
        }
        
        .badge-huiwang {
            background: #e74c3c;
        }
        
        .badge-aba {
            background: #3498db;
        }
        
        .filter-form {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        
        .quick-date-buttons {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            flex-wrap: wrap;
        }
        
        .limit-selector {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
        }
        
        .balance-info {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
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
        
        .total-balance-summary {
            background: linear-gradient(135deg, #4b6cb7, #182848);
            color: white;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: center;
        }
        
        .total-balance-summary h3 {
            margin: 0 0 10px 0;
            font-size: 1.2em;
        }
        
        .total-balance-amount {
            font-size: 1.8em;
            font-weight: bold;
            margin: 0;
        }
        
        .total-balance-info {
            background: #e8f5e8;
            padding: 10px;
            border-radius: 5px;
            margin: 5px 0;
            border-left: 4px solid #28a745;
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
                <li><a href="account_management.php">账户管理</a></li>
                <li><a href="account_transactions.php" class="active">账户流水</a></li>
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
        
        <div class="card">
            <h2>账户流水明细</h2>
            
            <!-- 总余额汇总 -->
            <div class="total-balance-summary">
                <h3>当前总资产</h3>
                <?php
                $current_total_balance = 0;
                foreach ($accounts as $account) {
                    $current_total_balance += $account['balance'];
                }
                ?>
                <p class="total-balance-amount">￥<?php echo number_format($current_total_balance, 2); ?></p>
            </div>
            
            <!-- 筛选表单 -->
            <form method="GET" action="" class="filter-form" id="filterForm">
                <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label for="account_id">账户</label>
                        <select id="account_id" name="account_id">
                            <option value="">全部账户</option>
                            <?php foreach ($accounts as $account): ?>
                            <option value="<?php echo $account['id']; ?>" <?php echo $account_filter == $account['id'] ? 'selected' : ''; ?>>
                                <?php echo safeOutput($account['account_name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="type">交易类型</label>
                        <select id="type" name="type">
                            <option value="">全部类型</option>
                            <option value="income" <?php echo $type_filter == 'income' ? 'selected' : ''; ?>>收入</option>
                            <option value="expense" <?php echo $type_filter == 'expense' ? 'selected' : ''; ?>>支出</option>
                            <option value="lend" <?php echo $type_filter == 'lend' ? 'selected' : ''; ?>>借出</option>
                            <option value="borrow" <?php echo $type_filter == 'borrow' ? 'selected' : ''; ?>>借入</option>
                            <option value="repay" <?php echo $type_filter == 'repay' ? 'selected' : ''; ?>>还款</option>
                            <option value="return" <?php echo $type_filter == 'return' ? 'selected' : ''; ?>>还钱</option>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label for="start_date">开始日期</label>
                        <input type="date" id="start_date" name="start_date" value="<?php echo safeOutput($start_date); ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="end_date">结束日期</label>
                        <input type="date" id="end_date" name="end_date" value="<?php echo safeOutput($end_date); ?>">
                    </div>
                </div>
                
                <!-- 记录数量选择器 -->
                <div class="limit-selector">
                    <label for="limit">显示记录数:</label>
                    <select id="limit" name="limit" onchange="document.getElementById('filterForm').submit()">
                        <option value="50" <?php echo $limit == 50 ? 'selected' : ''; ?>>50条</option>
                        <option value="100" <?php echo $limit == 100 ? 'selected' : ''; ?>>100条</option>
                        <option value="200" <?php echo $limit == 200 ? 'selected' : ''; ?>>200条</option>
                        <option value="500" <?php echo $limit == 500 ? 'selected' : ''; ?>>500条</option>
                        <option value="0" <?php echo $limit == 0 ? 'selected' : ''; ?>>全部</option>
                    </select>
                </div>
                
                <!-- 快速日期选择按钮 -->
                <div class="quick-date-buttons">
                    <button type="button" class="btn btn-sm" onclick="setDateRange('today')">今天</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('yesterday')">昨天</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('week')">本周</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('month')">本月</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('30days')">最近30天</button>
                    <button type="button" class="btn btn-sm" onclick="setDateRange('year')">一年</button>
                </div>
                
                <button type="submit">筛选</button>
                <a href="account_transactions.php" class="btn">重置</a>
            </form>
            
            <!-- 流水表格 -->
            <?php if (!empty($transactions_with_total_balance)): ?>
            <table>
                <thead>
                    <tr>
                        <th>日期时间</th>
                        <th>账户</th>
                        <th>类型</th>
                        <th>金额</th>
                        <th>总余额变化</th>
                        <th>描述</th>
                        <th>关联业务</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($transactions_with_total_balance as $transaction): 
                        $is_income = in_array($transaction['transaction_type'], ['income', 'repay', 'return', 'borrow']);
                        $balance_change = $is_income ? $transaction['amount'] : -$transaction['amount'];
                    ?>
                    <tr>
                        <td><?php echo formatDateTime($transaction['transaction_date']); ?></td>
                        <td>
                            <?php echo safeOutput($transaction['account_name']); ?>
                            <span class="account-badge badge-<?php echo $transaction['account_type']; ?>">
                                <?php 
                                switch ($transaction['account_type']) {
                                    case 'cash': echo '现金'; break;
                                    case 'huiwang': echo '汇旺'; break;
                                    case 'aba': echo 'ABA'; break;
                                    default: echo safeOutput($transaction['account_type']);
                                }
                                ?>
                            </span>
                        </td>
                        <td>
                            <span class="transaction-type type-<?php echo $transaction['transaction_type']; ?>">
                                <?php 
                                switch ($transaction['transaction_type']) {
                                    case 'income': echo '收入'; break;
                                    case 'expense': echo '支出'; break;
                                    case 'lend': echo '借出'; break;
                                    case 'borrow': echo '借入'; break;
                                    case 'repay': echo '还款'; break;
                                    case 'return': echo '还钱'; break;
                                    default: echo $transaction['transaction_type'];
                                }
                                ?>
                            </span>
                        </td>
                        <td class="<?php echo $is_income ? 'income' : 'expense'; ?>">
                            <?php echo ($is_income ? '+' : '-') . '￥' . number_format($transaction['amount'], 2); ?>
                        </td>
                        <td>
                            <div class="total-balance-info">
                                <span class="balance-before">交易前总资产: ￥<?php echo number_format($transaction['total_balance_before'], 2); ?></span>
                                <span class="balance-change <?php echo $is_income ? 'balance-increase' : 'balance-decrease'; ?>">
                                    <?php echo $is_income ? '↗ +' : '↘ -'; ?>
                                </span>
                                <span class="balance-after">交易后总资产: ￥<?php echo number_format($transaction['total_balance_after'], 2); ?></span>
                            </div>
                        </td>
                        <td><?php echo safeOutput($transaction['description'] ?: '-'); ?></td>
                        <td>
                            <?php 
                            if ($transaction['related_table'] == 'transactions') {
                                echo '<a href="view_records.php">普通交易</a>';
                            } elseif ($transaction['related_table'] == 'friend_transactions') {
                                echo '<a href="friends_management.php">朋友借贷</a>';
                            } elseif ($transaction['related_table'] == 'balance_adjustment') {
                                echo '余额调整';
                            } else {
                                echo safeOutput($transaction['related_table']);
                            }
                            ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <p style="margin-top: 15px;">共 <?php echo count($transactions_with_total_balance); ?> 条记录</p>
            <?php else: ?>
            <p>没有找到符合条件的记录。</p>
            <?php endif; ?>
        </div>
    </main>
    
    <footer style="text-align: center; padding: 20px; margin-top: 30px; color: #666;">
        <p>个人记账系统 &copy; <?php echo date('Y'); ?> - 基于 PHP + MySQL 开发</p>
    </footer>
    
    <script>
    function setDateRange(range) {
        const today = new Date();
        const startDate = document.getElementById('start_date');
        const endDate = document.getElementById('end_date');
        
        switch(range) {
            case 'today':
                startDate.value = today.toISOString().split('T')[0];
                endDate.value = today.toISOString().split('T')[0];
                break;
            case 'yesterday':
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                startDate.value = yesterday.toISOString().split('T')[0];
                endDate.value = yesterday.toISOString().split('T')[0];
                break;
            case 'week':
                const startOfWeek = new Date(today);
                startOfWeek.setDate(today.getDate() - today.getDay());
                startDate.value = startOfWeek.toISOString().split('T')[0];
                endDate.value = today.toISOString().split('T')[0];
                break;
            case 'month':
                const startOfMonth = new Date(today.getFullYear(), today.getMonth(), 1);
                startDate.value = startOfMonth.toISOString().split('T')[0];
                endDate.value = today.toISOString().split('T')[0];
                break;
            case '30days':
                const thirtyDaysAgo = new Date(today);
                thirtyDaysAgo.setDate(today.getDate() - 30);
                startDate.value = thirtyDaysAgo.toISOString().split('T')[0];
                endDate.value = today.toISOString().split('T')[0];
                break;
            case 'year':
                const startOfYear = new Date(today.getFullYear(), 0, 1);
                startDate.value = startOfYear.toISOString().split('T')[0];
                endDate.value = today.toISOString().split('T')[0];
                break;
        }
        
        // 自动提交表单
        document.getElementById('filterForm').submit();
    }
    </script>
</body>
</html>