<?php
require_once 'includes/header.php';
requireLogin();

$user_id = getCurrentUserId();

// 获取当前月份的收支统计
$current_month_start = date('Y-m-01 00:00:00');
$next_month_start = date('Y-m-01 00:00:00', strtotime('+1 month'));

// 初始化变量
$month_income = 0;
$month_expense = 0;
$balance = 0;
$total_income = 0;
$total_expense = 0;
$recent_transactions = [];
$budget_data = [];
$lend_total = 0;
$borrow_total = 0;

try {
    // 检查表是否存在
    $table_check = $pdo->query("SHOW TABLES LIKE 'transactions'")->fetch();
    if (!$table_check) {
        throw new Exception("数据库表不存在，请先运行修复脚本");
    }
    
    // 检查表结构
    $columns = $pdo->query("DESCRIBE transactions")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('type', $columns)) {
        throw new Exception("数据库表结构不正确，请运行修复脚本");
    }
    
    // 本月收入
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'income' AND transaction_date >= ? AND transaction_date < ?");
    $stmt->execute([$user_id, $current_month_start, $next_month_start]);
    $month_income = $stmt->fetch()['total'];
    
    // 本月支出
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'expense' AND transaction_date >= ? AND transaction_date < ?");
    $stmt->execute([$user_id, $current_month_start, $next_month_start]);
    $month_expense = $stmt->fetch()['total'];
    
    $balance = $month_income - $month_expense;
    
    // 总收入
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'income'");
    $stmt->execute([$user_id]);
    $total_income = $stmt->fetch()['total'];
    
    // 总支出
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'expense'");
    $stmt->execute([$user_id]);
    $total_expense = $stmt->fetch()['total'];
    
    // 最近交易记录
    $stmt = $pdo->prepare("SELECT t.*, a.account_name FROM transactions t LEFT JOIN accounts a ON t.account_id = a.id WHERE t.user_id = ? ORDER BY t.transaction_date DESC, t.created_at DESC LIMIT 5");
    $stmt->execute([$user_id]);
    $recent_transactions = $stmt->fetchAll();
    
    // 预算数据
    $budget_stmt = $pdo->prepare("SELECT category, amount FROM budgets WHERE user_id = ? AND month_year = ?");
    $budget_stmt->execute([$user_id, date('Y-m-01')]);
    $budgets = $budget_stmt->fetchAll();
    
    foreach ($budgets as $budget) {
        $expense_stmt = $pdo->prepare("SELECT COALESCE(SUM(amount), 0) as total FROM transactions WHERE user_id = ? AND type = 'expense' AND category = ? AND transaction_date >= ? AND transaction_date < ?");
        $expense_stmt->execute([$user_id, $budget['category'], $current_month_start, $next_month_start]);
        $actual = $expense_stmt->fetch()['total'];
        
        $budget_data[] = [
            'category' => $budget['category'],
            'budget' => $budget['amount'],
            'actual' => $actual,
            'remaining' => $budget['amount'] - $actual
        ];
    }
    
    // 借贷数据 - 使用新的朋友借贷系统
    $loan_stmt = $pdo->prepare("
        SELECT 
            SUM(CASE WHEN net_amount > 0 THEN net_amount ELSE 0 END) as total_owed,
            SUM(CASE WHEN net_amount < 0 THEN ABS(net_amount) ELSE 0 END) as total_owe
        FROM (
            SELECT 
                f.id,
                (SUM(CASE WHEN ft.type = 'borrow' THEN ft.amount ELSE 0 END) - 
                 SUM(CASE WHEN ft.type = 'return' THEN ft.amount ELSE 0 END)) -
                (SUM(CASE WHEN ft.type = 'lend' THEN ft.amount ELSE 0 END) - 
                 SUM(CASE WHEN ft.type = 'repay' THEN ft.amount ELSE 0 END)) as net_amount
            FROM friends f
            LEFT JOIN friend_transactions ft ON f.id = ft.friend_id
            WHERE f.user_id = ?
            GROUP BY f.id
        ) as friend_net
    ");
    $loan_stmt->execute([$user_id]);
    $loan_totals = $loan_stmt->fetch();
    
    $lend_total = $loan_totals['total_owed'] ?? 0;
    $borrow_total = $loan_totals['total_owe'] ?? 0;
    
    // 获取账户余额
    $stmt = $pdo->prepare("SELECT account_type, account_name, balance FROM accounts WHERE user_id = ? ORDER BY account_type");
    $stmt->execute([$user_id]);
    $user_accounts = $stmt->fetchAll();
    
    // 计算各账户余额
    $cash_balance = 0;
    $huiwang_balance = 0;
    $aba_balance = 0;
    foreach ($user_accounts as $account) {
        if ($account['account_type'] == 'cash') $cash_balance = $account['balance'];
        if ($account['account_type'] == 'huiwang') $huiwang_balance = $account['balance'];
        if ($account['account_type'] == 'aba') $aba_balance = $account['balance'];
    }
    
} catch (Exception $e) {
    $error = $e->getMessage();
}
?>

<div class="dashboard">
    <h2>财务概览</h2>
    
    <div class="stats">
        <div class="stat-card">
            <div>本月收入</div>
            <div class="stat-value income-stat">￥<?php echo number_format($month_income, 2); ?></div>
        </div>
        <div class="stat-card">
            <div>本月支出</div>
            <div class="stat-value expense-stat">￥<?php echo number_format($month_expense, 2); ?></div>
        </div>
        <div class="stat-card">
            <div>本月结余</div>
            <div class="stat-value <?php echo $balance >= 0 ? 'income-stat' : 'expense-stat'; ?>">￥<?php echo number_format($balance, 2); ?></div>
        </div>
        <div class="stat-card">
            <div>待收借款</div>
            <div class="stat-value income-stat">￥<?php echo number_format($lend_total, 2); ?></div>
        </div>
        <div class="stat-card">
            <div>待还借款</div>
            <div class="stat-value expense-stat">￥<?php echo number_format($borrow_total, 2); ?></div>
        </div>
    </div>
    
    <!-- 账户余额统计 -->
    <div class="card">
        <h3>账户余额</h3>
        <div class="stats">
            <div class="stat-card">
                <div>现金账户</div>
                <div class="stat-value income-stat">￥<?php echo number_format($cash_balance, 2); ?></div>
            </div>
            <div class="stat-card">
                <div>汇旺账户</div>
                <div class="stat-value income-stat">￥<?php echo number_format($huiwang_balance, 2); ?></div>
            </div>
            <div class="stat-card">
                <div>ABA账户</div>
                <div class="stat-value income-stat">￥<?php echo number_format($aba_balance, 2); ?></div>
            </div>
            <div class="stat-card">
                <div>总资产</div>
                <div class="stat-value income-stat">￥<?php echo number_format($cash_balance + $huiwang_balance + $aba_balance, 2); ?></div>
            </div>
        </div>
    </div>
    
    <div class="card">
        <h3>预算执行情况</h3>
        <?php if (!empty($budget_data)): ?>
            <?php foreach ($budget_data as $data): ?>
            <div class="budget-progress">
                <div class="budget-category"><?php echo safeOutput($data['category']); ?></div>
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
                </div>
            </div>
            <?php endforeach; ?>
        <?php else: ?>
            <p>本月尚未设置预算。 <a href="budget.php">立即设置</a></p>
        <?php endif; ?>
    </div>
    
    <div class="card">
        <h3>最近交易记录</h3>
        <?php if (!empty($recent_transactions)): ?>
        <table>
            <thead>
                <tr>
                    <th>日期时间</th>
                    <th>账户</th>
                    <th>类型</th>
                    <th>分类</th>
                    <th>金额</th>
                    <th>描述</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_transactions as $transaction): ?>
                <tr>
                    <td><?php echo formatDateTime($transaction['transaction_date']); ?></td>
                    <td>
                        <?php echo safeOutput($transaction['account_name'] ?: '未指定'); ?>
                        <?php if ($transaction['account_name']): ?>
                        <span class="account-badge badge-<?php 
                            switch($transaction['account_name']) {
                                case '现金账户': echo 'cash'; break;
                                case '汇旺账户': echo 'huiwang'; break;
                                case 'ABA账户': echo 'aba'; break;
                                default: echo 'cash';
                            }
                        ?>" style="font-size: 10px; padding: 1px 5px;">
                            <?php 
                            switch($transaction['account_name']) {
                                case '现金账户': echo '现金'; break;
                                case '汇旺账户': echo '汇旺'; break;
                                case 'ABA账户': echo 'ABA'; break;
                                default: echo '其他';
                            }
                            ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td><span class="<?php echo $transaction['type']; ?>"><?php echo $transaction['type'] == 'income' ? '收入' : '支出'; ?></span></td>
                    <td><?php echo safeOutput($transaction['category']); ?></td>
                    <td class="<?php echo $transaction['type']; ?>"><?php echo ($transaction['type'] == 'income' ? '+' : '-') . '￥' . number_format($transaction['amount'], 2); ?></td>
                    <td><?php echo safeOutput($transaction['description'] ?: '-'); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="text-align: center; margin-top: 10px;">
            <a href="view_records.php">查看全部记录</a>
        </p>
        <?php else: ?>
            <p>暂无交易记录。 <a href="add_record.php">添加第一笔记录</a></p>
        <?php endif; ?>
    </div>
</div>

<style>
.account-badge {
    display: inline-block;
    padding: 2px 6px;
    border-radius: 10px;
    font-size: 10px;
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
</style>

<?php
require_once 'includes/footer.php';
?>
