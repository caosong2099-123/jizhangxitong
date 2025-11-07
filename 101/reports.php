[file name]: reports.php
[file content begin]
<?php
require_once 'includes/header.php';
requireLogin();

$user_id = getCurrentUserId();

// 获取筛选参数
$report_type = isset($_GET['report_type']) ? $_GET['report_type'] : 'monthly';
$year = isset($_GET['year']) ? intval($_GET['year']) : date('Y');
$month = isset($_GET['month']) ? intval($_GET['month']) : date('m');
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : date('Y-m-01');
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : date('Y-m-t');

// 初始化数据
$monthly_data = [];
$category_data = [];
$friend_summary = [];
$income_expense_comparison = [];
$account_summary = [];

try {
    if ($report_type == 'monthly' || $report_type == 'category' || $report_type == 'all') {
        // 月度收支统计
        $month_start = date('Y-m-01', strtotime("$year-$month-01"));
        $month_end = date('Y-m-t', strtotime("$year-$month-01"));
        
        // 收入统计
        $stmt = $pdo->prepare("
            SELECT 
                SUM(amount) as total_income,
                COUNT(*) as count_income
            FROM transactions 
            WHERE user_id = ? AND type = 'income' 
            AND transaction_date BETWEEN ? AND ?
        ");
        $stmt->execute([$user_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59']);
        $income_stats = $stmt->fetch();
        
        // 支出统计
        $stmt = $pdo->prepare("
            SELECT 
                SUM(amount) as total_expense,
                COUNT(*) as count_expense
            FROM transactions 
            WHERE user_id = ? AND type = 'expense' 
            AND transaction_date BETWEEN ? AND ?
        ");
        $stmt->execute([$user_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59']);
        $expense_stats = $stmt->fetch();
        
        $monthly_data = [
            'income' => [
                'total' => $income_stats['total_income'] ?: 0,
                'count' => $income_stats['count_income'] ?: 0
            ],
            'expense' => [
                'total' => $expense_stats['total_expense'] ?: 0,
                'count' => $expense_stats['count_expense'] ?: 0
            ],
            'balance' => ($income_stats['total_income'] ?: 0) - ($expense_stats['total_expense'] ?: 0)
        ];
        
        // 分类统计
        $stmt = $pdo->prepare("
            SELECT 
                category,
                type,
                SUM(amount) as total_amount,
                COUNT(*) as transaction_count
            FROM transactions 
            WHERE user_id = ? 
            AND transaction_date BETWEEN ? AND ?
            GROUP BY category, type
            ORDER BY type, total_amount DESC
        ");
        $stmt->execute([$user_id, $month_start . ' 00:00:00', $month_end . ' 23:59:59']);
        $category_stats = $stmt->fetchAll();
        
        foreach ($category_stats as $stat) {
            $category_data[] = [
                'category' => $stat['category'],
                'type' => $stat['type'],
                'total_amount' => $stat['total_amount'],
                'transaction_count' => $stat['transaction_count']
            ];
        }
    }
    
    if ($report_type == 'friends' || $report_type == 'all') {
        // 朋友借贷统计
        $stmt = $pdo->prepare("
            SELECT 
                f.name as friend_name,
                SUM(CASE WHEN ft.type = 'lend' THEN ft.amount ELSE 0 END) as total_lend,
                SUM(CASE WHEN ft.type = 'repay' THEN ft.amount ELSE 0 END) as total_repay,
                SUM(CASE WHEN ft.type = 'borrow' THEN ft.amount ELSE 0 END) as total_borrow,
                SUM(CASE WHEN ft.type = 'return' THEN ft.amount ELSE 0 END) as total_return,
                COUNT(*) as transaction_count
            FROM friend_transactions ft
            JOIN friends f ON ft.friend_id = f.id
            WHERE ft.user_id = ?
            GROUP BY f.id, f.name
            ORDER BY (total_borrow - total_return) - (total_lend - total_repay) DESC
        ");
        $stmt->execute([$user_id]);
        $friend_stats = $stmt->fetchAll();
        
        foreach ($friend_stats as $stat) {
            $net_amount = ($stat['total_borrow'] - $stat['total_return']) - ($stat['total_lend'] - $stat['total_repay']);
            $friend_summary[] = [
                'friend_name' => $stat['friend_name'],
                'total_lend' => $stat['total_lend'],
                'total_repay' => $stat['total_repay'],
                'total_borrow' => $stat['total_borrow'],
                'total_return' => $stat['total_return'],
                'net_amount' => $net_amount,
                'transaction_count' => $stat['transaction_count'],
                'relationship' => $net_amount > 0 ? 'owed' : ($net_amount < 0 ? 'owe' : 'settled')
            ];
        }
    }
    
    if ($report_type == 'comparison' || $report_type == 'all') {
        // 月度对比统计（最近6个月）
        $comparison_data = [];
        for ($i = 5; $i >= 0; $i--) {
            $compare_month = date('Y-m', strtotime("-$i months"));
            $compare_start = date('Y-m-01', strtotime($compare_month));
            $compare_end = date('Y-m-t', strtotime($compare_month));
            
            // 收入
            $stmt = $pdo->prepare("
                SELECT SUM(amount) as total 
                FROM transactions 
                WHERE user_id = ? AND type = 'income' 
                AND transaction_date BETWEEN ? AND ?
            ");
            $stmt->execute([$user_id, $compare_start . ' 00:00:00', $compare_end . ' 23:59:59']);
            $income = $stmt->fetch()['total'] ?: 0;
            
            // 支出
            $stmt = $pdo->prepare("
                SELECT SUM(amount) as total 
                FROM transactions 
                WHERE user_id = ? AND type = 'expense' 
                AND transaction_date BETWEEN ? AND ?
            ");
            $stmt->execute([$user_id, $compare_start . ' 00:00:00', $compare_end . ' 23:59:59']);
            $expense = $stmt->fetch()['total'] ?: 0;
            
            $comparison_data[] = [
                'month' => $compare_month,
                'income' => $income,
                'expense' => $expense,
                'balance' => $income - $expense
            ];
        }
        
        $income_expense_comparison = $comparison_data;
    }
    
    if ($report_type == 'accounts' || $report_type == 'all') {
        // 账户统计
        $stmt = $pdo->prepare("
            SELECT 
                account_name,
                account_type,
                balance,
                created_at,
                updated_at
            FROM accounts 
            WHERE user_id = ? 
            ORDER BY balance DESC
        ");
        $stmt->execute([$user_id]);
        $accounts = $stmt->fetchAll();
        
        foreach ($accounts as $account) {
            // 计算账户月度变动
            $month_start = date('Y-m-01', strtotime("$year-$month-01"));
            $month_end = date('Y-m-t', strtotime("$year-$month-01"));
            
            $stmt = $pdo->prepare("
                SELECT 
                    SUM(CASE WHEN transaction_type IN ('income', 'repay', 'borrow') THEN amount ELSE 0 END) as total_income,
                    SUM(CASE WHEN transaction_type IN ('expense', 'lend', 'return') THEN amount ELSE 0 END) as total_expense
                FROM account_transactions 
                WHERE user_id = ? AND account_id = ? 
                AND transaction_date BETWEEN ? AND ?
            ");
            $stmt->execute([$user_id, $account['id'], $month_start . ' 00:00:00', $month_end . ' 23:59:59']);
            $account_stats = $stmt->fetch();
            
            $account_summary[] = [
                'account_name' => $account['account_name'],
                'account_type' => $account['account_type'],
                'balance' => $account['balance'],
                'month_income' => $account_stats['total_income'] ?: 0,
                'month_expense' => $account_stats['total_expense'] ?: 0,
                'month_net' => ($account_stats['total_income'] ?: 0) - ($account_stats['total_expense'] ?: 0),
                'updated_at' => $account['updated_at']
            ];
        }
    }
    
} catch (PDOException $e) {
    die("数据库查询错误: " . $e->getMessage());
}

// 获取可用的年份和月份
$current_year = date('Y');
$current_month = date('m');
$years = range($current_year - 2, $current_year);
$months = [];
for ($i = 1; $i <= 12; $i++) {
    $months[$i] = $i . '月';
}
?>

<div class="card">
    <h2>统计报表</h2>
    
    <!-- 筛选表单 -->
    <form method="GET" action="" class="filter-form">
        <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
            <div class="form-group">
                <label for="report_type">报表类型</label>
                <select id="report_type" name="report_type">
                    <option value="monthly" <?php echo $report_type == 'monthly' ? 'selected' : ''; ?>>月度统计</option>
                    <option value="category" <?php echo $report_type == 'category' ? 'selected' : ''; ?>>分类统计</option>
                    <option value="friends" <?php echo $report_type == 'friends' ? 'selected' : ''; ?>>借贷统计</option>
                    <option value="accounts" <?php echo $report_type == 'accounts' ? 'selected' : ''; ?>>账户统计</option>
                    <option value="comparison" <?php echo $report_type == 'comparison' ? 'selected' : ''; ?>>月度对比</option>
                    <option value="all" <?php echo $report_type == 'all' ? 'selected' : ''; ?>>完整报表</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="year">年份</label>
                <select id="year" name="year">
                    <?php foreach ($years as $y): ?>
                    <option value="<?php echo $y; ?>" <?php echo $year == $y ? 'selected' : ''; ?>><?php echo $y; ?>年</option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label for="month">月份</label>
                <select id="month" name="month">
                    <?php foreach ($months as $key => $name): ?>
                    <option value="<?php echo $key; ?>" <?php echo $month == $key ? 'selected' : ''; ?>><?php echo $name; ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <button type="submit">生成报表</button>
        <a href="reports.php" class="btn">重置</a>
    </form>
    
    <!-- 月度统计 -->
    <?php if (($report_type == 'monthly' || $report_type == 'all') && !empty($monthly_data)): ?>
    <div class="report-section">
        <h3>月度收支统计 - <?php echo $year; ?>年<?php echo $month; ?>月</h3>
        
        <div class="stats-grid" style="grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));">
            <div class="stat-card">
                <div>总收入</div>
                <div class="stat-value income-stat">￥<?php echo number_format($monthly_data['income']['total'], 2); ?></div>
                <div class="stat-detail"><?php echo $monthly_data['income']['count']; ?> 笔记录</div>
            </div>
            
            <div class="stat-card">
                <div>总支出</div>
                <div class="stat-value expense-stat">￥<?php echo number_format($monthly_data['expense']['total'], 2); ?></div>
                <div class="stat-detail"><?php echo $monthly_data['expense']['count']; ?> 笔记录</div>
            </div>
            
            <div class="stat-card">
                <div>月度结余</div>
                <div class="stat-value <?php echo $monthly_data['balance'] >= 0 ? 'income-stat' : 'expense-stat'; ?>">
                    ￥<?php echo number_format($monthly_data['balance'], 2); ?>
                </div>
                <div class="stat-detail">
                    <?php 
                    if ($monthly_data['balance'] > 0) {
                        echo '盈余';
                    } elseif ($monthly_data['balance'] < 0) {
                        echo '赤字';
                    } else {
                        echo '收支平衡';
                    }
                    ?>
                </div>
            </div>
            
            <div class="stat-card">
                <div>储蓄率</div>
                <div class="stat-value <?php echo ($monthly_data['income']['total'] > 0 ? (($monthly_data['balance'] / $monthly_data['income']['total']) * 100 >= 20 ? 'income-stat' : 'expense-stat') : 'expense-stat'); ?>">
                    <?php 
                    if ($monthly_data['income']['total'] > 0) {
                        echo number_format(($monthly_data['balance'] / $monthly_data['income']['total']) * 100, 1) . '%';
                    } else {
                        echo '0%';
                    }
                    ?>
                </div>
                <div class="stat-detail">收入留存比例</div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 分类统计 -->
    <?php if (($report_type == 'category' || $report_type == 'all') && !empty($category_data)): ?>
    <div class="report-section">
        <h3>分类统计 - <?php echo $year; ?>年<?php echo $month; ?>月</h3>
        
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
            <!-- 收入分类 -->
            <div>
                <h4>收入分类</h4>
                <?php 
                $income_categories = array_filter($category_data, function($item) {
                    return $item['type'] == 'income';
                });
                ?>
                <?php if (!empty($income_categories)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>分类</th>
                            <th>金额</th>
                            <th>笔数</th>
                            <th>占比</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_income = array_sum(array_column($income_categories, 'total_amount'));
                        foreach ($income_categories as $category): 
                            $percentage = $total_income > 0 ? ($category['total_amount'] / $total_income) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo safeOutput($category['category']); ?></td>
                            <td class="income">￥<?php echo number_format($category['total_amount'], 2); ?></td>
                            <td><?php echo $category['transaction_count']; ?></td>
                            <td><?php echo number_format($percentage, 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p>本月无收入记录</p>
                <?php endif; ?>
            </div>
            
            <!-- 支出分类 -->
            <div>
                <h4>支出分类</h4>
                <?php 
                $expense_categories = array_filter($category_data, function($item) {
                    return $item['type'] == 'expense';
                });
                ?>
                <?php if (!empty($expense_categories)): ?>
                <table>
                    <thead>
                        <tr>
                            <th>分类</th>
                            <th>金额</th>
                            <th>笔数</th>
                            <th>占比</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $total_expense = array_sum(array_column($expense_categories, 'total_amount'));
                        foreach ($expense_categories as $category): 
                            $percentage = $total_expense > 0 ? ($category['total_amount'] / $total_expense) * 100 : 0;
                        ?>
                        <tr>
                            <td><?php echo safeOutput($category['category']); ?></td>
                            <td class="expense">￥<?php echo number_format($category['total_amount'], 2); ?></td>
                            <td><?php echo $category['transaction_count']; ?></td>
                            <td><?php echo number_format($percentage, 1); ?>%</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php else: ?>
                <p>本月无支出记录</p>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 朋友借贷统计 -->
    <?php if (($report_type == 'friends' || $report_type == 'all') && !empty($friend_summary)): ?>
    <div class="report-section">
        <h3>朋友借贷统计</h3>
        
        <table>
            <thead>
                <tr>
                    <th>朋友姓名</th>
                    <th>我借出</th>
                    <th>我还入</th>
                    <th>我借入</th>
                    <th>我还出</th>
                    <th>净额</th>
                    <th>交易笔数</th>
                    <th>状态</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_lend = 0;
                $total_repay = 0;
                $total_borrow = 0;
                $total_return = 0;
                ?>
                <?php foreach ($friend_summary as $friend): ?>
                <?php 
                $total_lend += $friend['total_lend'];
                $total_repay += $friend['total_repay'];
                $total_borrow += $friend['total_borrow'];
                $total_return += $friend['total_return'];
                ?>
                <tr>
                    <td><?php echo safeOutput($friend['friend_name']); ?></td>
                    <td>￥<?php echo number_format($friend['total_lend'], 2); ?></td>
                    <td>￥<?php echo number_format($friend['total_repay'], 2); ?></td>
                    <td>￥<?php echo number_format($friend['total_borrow'], 2); ?></td>
                    <td>￥<?php echo number_format($friend['total_return'], 2); ?></td>
                    <td class="<?php echo $friend['relationship'] == 'owed' ? 'income' : ($friend['relationship'] == 'owe' ? 'expense' : ''); ?>">
                        <?php 
                        if ($friend['relationship'] == 'owed') {
                            echo '欠我 ￥' . number_format(abs($friend['net_amount']), 2);
                        } elseif ($friend['relationship'] == 'owe') {
                            echo '我欠 ￥' . number_format(abs($friend['net_amount']), 2);
                        } else {
                            echo '已结清';
                        }
                        ?>
                    </td>
                    <td><?php echo $friend['transaction_count']; ?></td>
                    <td>
                        <span class="status-<?php echo $friend['relationship']; ?>">
                            <?php 
                            echo $friend['relationship'] == 'owed' ? '对方欠我' : 
                                 ($friend['relationship'] == 'owe' ? '我欠对方' : '已结清');
                            ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
                <!-- 总计行 -->
                <tr style="font-weight: bold; background-color: #f8f9fa;">
                    <td>总计</td>
                    <td>￥<?php echo number_format($total_lend, 2); ?></td>
                    <td>￥<?php echo number_format($total_repay, 2); ?></td>
                    <td>￥<?php echo number_format($total_borrow, 2); ?></td>
                    <td>￥<?php echo number_format($total_return, 2); ?></td>
                    <td colspan="3">
                        净借贷: 
                        <span class="<?php echo ($total_borrow - $total_return) - ($total_lend - $total_repay) > 0 ? 'income' : 'expense'; ?>">
                            ￥<?php echo number_format(abs(($total_borrow - $total_return) - ($total_lend - $total_repay)), 2); ?>
                        </span>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- 账户统计 -->
    <?php if (($report_type == 'accounts' || $report_type == 'all') && !empty($account_summary)): ?>
    <div class="report-section">
        <h3>账户统计 - <?php echo $year; ?>年<?php echo $month; ?>月</h3>
        
        <table>
            <thead>
                <tr>
                    <th>账户名称</th>
                    <th>账户类型</th>
                    <th>当前余额</th>
                    <th>本月收入</th>
                    <th>本月支出</th>
                    <th>本月净额</th>
                    <th>最后更新</th>
                </tr>
            </thead>
            <tbody>
                <?php 
                $total_balance = 0;
                $total_month_income = 0;
                $total_month_expense = 0;
                ?>
                <?php foreach ($account_summary as $account): ?>
                <?php 
                $total_balance += $account['balance'];
                $total_month_income += $account['month_income'];
                $total_month_expense += $account['month_expense'];
                ?>
                <tr>
                    <td><?php echo safeOutput($account['account_name']); ?></td>
                    <td><?php echo safeOutput($account['account_type']); ?></td>
                    <td class="<?php echo $account['balance'] >= 0 ? 'income' : 'expense'; ?>">
                        ￥<?php echo number_format($account['balance'], 2); ?>
                    </td>
                    <td class="income">￥<?php echo number_format($account['month_income'], 2); ?></td>
                    <td class="expense">￥<?php echo number_format($account['month_expense'], 2); ?></td>
                    <td class="<?php echo $account['month_net'] >= 0 ? 'income' : 'expense'; ?>">
                        ￥<?php echo number_format($account['month_net'], 2); ?>
                    </td>
                    <td><?php echo formatDateTime($account['updated_at']); ?></td>
                </tr>
                <?php endforeach; ?>
                <!-- 总计行 -->
                <tr style="font-weight: bold; background-color: #f8f9fa;">
                    <td colspan="2">总计</td>
                    <td class="income">￥<?php echo number_format($total_balance, 2); ?></td>
                    <td class="income">￥<?php echo number_format($total_month_income, 2); ?></td>
                    <td class="expense">￥<?php echo number_format($total_month_expense, 2); ?></td>
                    <td class="<?php echo ($total_month_income - $total_month_expense) >= 0 ? 'income' : 'expense'; ?>">
                        ￥<?php echo number_format($total_month_income - $total_month_expense, 2); ?>
                    </td>
                    <td>-</td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <!-- 月度对比 -->
    <?php if (($report_type == 'comparison' || $report_type == 'all') && !empty($income_expense_comparison)): ?>
    <div class="report-section">
        <h3>月度收支对比（最近6个月）</h3>
        
        <table>
            <thead>
                <tr>
                    <th>月份</th>
                    <th>收入</th>
                    <th>支出</th>
                    <th>结余</th>
                    <th>储蓄率</th>
                    <th>趋势</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($income_expense_comparison as $index => $month_data): ?>
                <tr>
                    <td><?php echo $month_data['month']; ?></td>
                    <td class="income">￥<?php echo number_format($month_data['income'], 2); ?></td>
                    <td class="expense">￥<?php echo number_format($month_data['expense'], 2); ?></td>
                    <td class="<?php echo $month_data['balance'] >= 0 ? 'income' : 'expense'; ?>">
                        ￥<?php echo number_format($month_data['balance'], 2); ?>
                    </td>
                    <td>
                        <?php 
                        if ($month_data['income'] > 0) {
                            $savings_rate = ($month_data['balance'] / $month_data['income']) * 100;
                            echo number_format($savings_rate, 1) . '%';
                        } else {
                            echo '0%';
                        }
                        ?>
                    </td>
                    <td>
                        <?php 
                        if ($index > 0) {
                            $prev_balance = $income_expense_comparison[$index - 1]['balance'];
                            $change = $month_data['balance'] - $prev_balance;
                            if ($change > 0) {
                                echo '<span style="color: #2ecc71;">↗ 改善</span>';
                            } elseif ($change < 0) {
                                echo '<span style="color: #e74c3c;">↘ 恶化</span>';
                            } else {
                                echo '<span style="color: #95a5a6;">→ 持平</span>';
                            }
                        } else {
                            echo '-';
                        }
                        ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php if ($report_type != 'all' && empty($monthly_data) && empty($category_data) && empty($friend_summary) && empty($income_expense_comparison) && empty($account_summary)): ?>
    <div class="report-section">
        <p>没有找到符合条件的统计数据。</p>
    </div>
    <?php endif; ?>
</div>

<style>
.report-section {
    margin-bottom: 30px;
    padding: 20px;
    border: 1px solid #e0e0e0;
    border-radius: 5px;
    background: white;
}

.report-section h3 {
    margin-top: 0;
    padding-bottom: 10px;
    border-bottom: 2px solid #4b6cb7;
    color: #4b6cb7;
}

.report-section h4 {
    color: #555;
    margin-bottom: 15px;
}

.stat-detail {
    font-size: 0.9em;
    color: #666;
    margin-top: 5px;
}

.status-owed {
    color: #2ecc71;
    font-weight: bold;
}

.status-owe {
    color: #e74c3c;
    font-weight: bold;
}

.status-settled {
    color: #95a5a6;
    font-weight: bold;
}

.filter-form {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 5px;
    margin-bottom: 20px;
}

@media (max-width: 768px) {
    .report-section > div {
        grid-template-columns: 1fr !important;
    }
    
    table {
        font-size: 14px;
    }
}
</style>

<?php
require_once 'includes/footer.php';
?>
[file content end]