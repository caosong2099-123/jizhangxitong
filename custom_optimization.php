<?php
/**
 * 定制化数据库优化脚本 - 针对您的记账系统表结构
 */

// 数据库配置
$host = 'localhost';
$dbname = 'jizhangxitong';
$username = 'root';
$password = '';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $username, $password);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "<h2>开始执行定制化数据库优化...</h2><br>";
    
    $success_count = 0;
    $error_count = 0;
    
    // 1. 优化 transactions 表（主要的交易记录表）
    echo "<h3>1. 优化 transactions 表</h3>";
    $transactions_indexes = [
        "ALTER TABLE transactions ADD INDEX idx_trans_user_date (user_id, transaction_date)",
        "ALTER TABLE transactions ADD INDEX idx_trans_user_type (user_id, type)",
        "ALTER TABLE transactions ADD INDEX idx_trans_user_category (user_id, category)",
        "ALTER TABLE transactions ADD INDEX idx_trans_date (transaction_date)",
        "ALTER TABLE transactions ADD INDEX idx_trans_account (account_id)"
    ];
    
    foreach ($transactions_indexes as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ 成功: $sql<br>";
            $success_count++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                echo "✗ 失败: " . $e->getMessage() . "<br>";
                $error_count++;
            } else {
                echo "✓ 跳过（已存在）: $sql<br>";
            }
        }
    }
    
    // 2. 优化 account_transactions 表（账户交易流水表）
    echo "<h3>2. 优化 account_transactions 表</h3>";
    $account_transactions_indexes = [
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_user (user_id)",
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_account (account_id)",
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_type (transaction_type)",
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_date (transaction_date)",
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_related (related_table, related_id)"
    ];
    
    foreach ($account_transactions_indexes as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ 成功: $sql<br>";
            $success_count++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                echo "✗ 失败: " . $e->getMessage() . "<br>";
                $error_count++;
            } else {
                echo "✓ 跳过（已存在）: $sql<br>";
            }
        }
    }
    
    // 3. 优化 accounts 表（账户表）
    echo "<h3>3. 优化 accounts 表</h3>";
    $accounts_indexes = [
        "ALTER TABLE accounts ADD INDEX idx_acc_user_type (user_id, account_type)",
        "ALTER TABLE accounts ADD INDEX idx_acc_balance (balance)"
    ];
    
    foreach ($accounts_indexes as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ 成功: $sql<br>";
            $success_count++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                echo "✗ 失败: " . $e->getMessage() . "<br>";
                $error_count++;
            } else {
                echo "✓ 跳过（已存在）: $sql<br>";
            }
        }
    }
    
    // 4. 优化 budgets 表（预算表）
    echo "<h3>4. 优化 budgets 表</h3>";
    $budgets_indexes = [
        "ALTER TABLE budgets ADD INDEX idx_budget_user (user_id)",
        "ALTER TABLE budgets ADD INDEX idx_budget_month (month_year)",
        "ALTER TABLE budgets ADD INDEX idx_budget_user_month (user_id, month_year)",
        "ALTER TABLE budgets ADD INDEX idx_budget_category (category)"
    ];
    
    foreach ($budgets_indexes as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ 成功: $sql<br>";
            $success_count++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                echo "✗ 失败: " . $e->getMessage() . "<br>";
                $error_count++;
            } else {
                echo "✓ 跳过（已存在）: $sql<br>";
            }
        }
    }
    
    // 5. 优化 friend_transactions 表（朋友交易表）
    echo "<h3>5. 优化 friend_transactions 表</h3>";
    $friend_transactions_indexes = [
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_user (user_id)",
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_friend (friend_id)",
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_date (transaction_date)",
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_type (type)",
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_user_friend (user_id, friend_id)"
    ];
    
    foreach ($friend_transactions_indexes as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ 成功: $sql<br>";
            $success_count++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                echo "✗ 失败: " . $e->getMessage() . "<br>";
                $error_count++;
            } else {
                echo "✓ 跳过（已存在）: $sql<br>";
            }
        }
    }
    
    // 6. 优化 friends 表（朋友表）
    echo "<h3>6. 优化 friends 表</h3>";
    $friends_indexes = [
        "ALTER TABLE friends ADD INDEX idx_friends_phone (phone)",
        "ALTER TABLE friends ADD INDEX idx_friends_user_created (user_id, created_at)"
    ];
    
    foreach ($friends_indexes as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ 成功: $sql<br>";
            $success_count++;
        } catch (PDOException $e) {
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                echo "✗ 失败: " . $e->getMessage() . "<br>";
                $error_count++;
            } else {
                echo "✓ 跳过（已存在）: $sql<br>";
            }
        }
    }
    
    // 7. 创建实用视图
    echo "<h3>7. 创建实用视图</h3>";
    
    $views = [
        // 月度交易汇总视图
        "CREATE OR REPLACE VIEW monthly_transaction_summary AS 
         SELECT 
             user_id, 
             YEAR(transaction_date) as year, 
             MONTH(transaction_date) as month,
             type,
             category,
             COUNT(*) as transaction_count,
             SUM(amount) as total_amount
         FROM transactions 
         GROUP BY user_id, YEAR(transaction_date), MONTH(transaction_date), type, category",
         
        // 账户余额变化视图
        "CREATE OR REPLACE VIEW account_balance_trend AS
         SELECT 
             at.user_id,
             at.account_id,
             a.account_name,
             DATE(at.transaction_date) as date,
             SUM(CASE 
                 WHEN at.transaction_type IN ('income', 'return', 'repay') THEN at.amount 
                 WHEN at.transaction_type IN ('expense', 'lend', 'borrow') THEN -at.amount
                 ELSE 0 
             END) as daily_net_change
         FROM account_transactions at
         JOIN accounts a ON at.account_id = a.id
         GROUP BY at.user_id, at.account_id, a.account_name, DATE(at.transaction_date)",
         
        // 预算执行情况视图
        "CREATE OR REPLACE VIEW budget_vs_actual AS
         SELECT 
             b.user_id,
             b.month_year,
             b.category,
             b.amount as budget_amount,
             COALESCE(SUM(t.amount), 0) as actual_amount,
             (b.amount - COALESCE(SUM(t.amount), 0)) as difference
         FROM budgets b
         LEFT JOIN transactions t ON 
             b.user_id = t.user_id 
             AND b.category = t.category 
             AND YEAR(t.transaction_date) = YEAR(b.month_year)
             AND MONTH(t.transaction_date) = MONTH(b.month_year)
             AND t.type = 'expense'
         GROUP BY b.user_id, b.month_year, b.category, b.amount"
    ];
    
    foreach ($views as $view_sql) {
        try {
            $pdo->exec($view_sql);
            echo "✓ 视图创建成功<br>";
            $success_count++;
        } catch (PDOException $e) {
            echo "✗ 视图创建失败: " . $e->getMessage() . "<br>";
            $error_count++;
        }
    }
    
    echo "<h2>优化完成！</h2>";
    echo "成功操作: $success_count, 失败: $error_count<br><br>";
    
    // 显示优化后的索引情况
    echo "<h3>优化后的索引情况：</h3>";
    $tables = ['transactions', 'account_transactions', 'accounts', 'budgets', 'friend_transactions', 'friends'];
    
    foreach ($tables as $table) {
        echo "<h4>表: $table</h4>";
        $indexes = $pdo->query("SHOW INDEX FROM $table")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($indexes)) {
            echo "无索引<br>";
        } else {
            echo "<table border='1' style='margin: 10px;'>";
            echo "<tr><th>索引名</th><th>字段</th><th>类型</th></tr>";
            foreach ($indexes as $index) {
                echo "<tr>";
                echo "<td>{$index['Key_name']}</td>";
                echo "<td>{$index['Column_name']}</td>";
                echo "<td>{$index['Index_type']}</td>";
                echo "</tr>";
            }
            echo "</table>";
        }
    }
    
} catch (PDOException $e) {
    die("数据库连接失败: " . $e->getMessage());
}
?>