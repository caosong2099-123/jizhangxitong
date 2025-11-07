<?php
/**
 * 记账系统一键安装脚本
 * 访问：http://yourdomain.com/install.php
 * 安装完成后请删除此文件！
 */

// 错误报告
error_reporting(E_ALL);
ini_set('display_errors', 1);

// 安装配置
$config = [
    'db_host' => 'localhost',
    'db_name' => 'jizhangxitong',
    'db_charset' => 'utf8mb4',
    'admin_username' => 'admin',
    'admin_email' => 'admin@example.com',
    'admin_password' => 'admin123456'
];

// 检查是否已安装
if (file_exists('config/installed.lock')) {
    die('系统已安装，如需重新安装请删除 config/installed.lock 文件');
}

// 处理安装表单提交
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config['db_host'] = $_POST['db_host'] ?? $config['db_host'];
    $config['db_name'] = $_POST['db_name'] ?? $config['db_name'];
    $config['db_user'] = $_POST['db_user'] ?? 'root';
    $config['db_pass'] = $_POST['db_pass'] ?? '';
    $config['admin_username'] = $_POST['admin_username'] ?? $config['admin_username'];
    $config['admin_email'] = $_POST['admin_email'] ?? $config['admin_email'];
    $config['admin_password'] = $_POST['admin_password'] ?? $config['admin_password'];
    
    try {
        installSystem($config);
        // 创建安装锁定文件
        file_put_contents('config/installed.lock', date('Y-m-d H:i:s'));
        echo '<script>alert("安装成功！"); window.location.href = "index.php";</script>';
        exit;
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

/**
 * 执行系统安装
 */
function installSystem($config) {
    // 创建数据库连接（不指定数据库名，用于创建数据库）
    $dsn = "mysql:host={$config['db_host']};charset={$config['db_charset']}";
    $pdo = new PDO($dsn, $config['db_user'], $config['db_pass']);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 创建数据库
    $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$config['db_name']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE `{$config['db_name']}`");
    
    echo "开始创建数据库表结构...<br>";
    flush();
    
    // 1. 创建用户表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL,
            `email` varchar(100) NOT NULL,
            `password_hash` varchar(255) NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `username` (`username`),
            UNIQUE KEY `email` (`email`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ 创建 users 表<br>";
    flush();
    
    // 2. 创建账户表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `accounts` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `account_name` varchar(100) NOT NULL,
            `account_type` varchar(50) NOT NULL,
            `balance` decimal(10,2) DEFAULT '0.00',
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_user_account_name` (`user_id`,`account_name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ 创建 accounts 表<br>";
    flush();
    
    // 3. 创建交易表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `account_id` int(11) DEFAULT NULL,
            `type` enum('income','expense') NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `category` varchar(50) NOT NULL,
            `transaction_date` datetime NOT NULL,
            `description` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ 创建 transactions 表<br>";
    flush();
    
    // 4. 创建账户交易流水表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `account_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `account_id` int(11) NOT NULL,
            `transaction_type` enum('income','expense','transfer','lend','borrow','repay','return') NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `related_table` varchar(50) NOT NULL,
            `related_id` int(11) NOT NULL,
            `description` text,
            `transaction_date` datetime NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `account_id` (`account_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ 创建 account_transactions 表<br>";
    flush();
    
    // 5. 创建预算表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `budgets` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `category` varchar(50) NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `month_year` date NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ 创建 budgets 表<br>";
    flush();
    
    // 6. 创建朋友表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `friends` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `name` varchar(100) NOT NULL,
            `phone` varchar(20) DEFAULT NULL,
            `note` text,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `unique_friend` (`user_id`,`name`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ 创建 friends 表<br>";
    flush();
    
    // 7. 创建朋友交易表
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `friend_transactions` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `account_id` int(11) DEFAULT NULL,
            `friend_id` int(11) NOT NULL,
            `type` enum('lend','repay','borrow','return') NOT NULL,
            `amount` decimal(10,2) NOT NULL,
            `description` text,
            `transaction_date` datetime NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `friend_id` (`friend_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
    echo "✓ 创建 friend_transactions 表<br>";
    flush();
    
    echo "开始创建索引优化...<br>";
    flush();
    
    // 创建优化索引
    $indexes = [
        // transactions 表索引
        "ALTER TABLE transactions ADD INDEX idx_trans_user_date (user_id, transaction_date)",
        "ALTER TABLE transactions ADD INDEX idx_trans_user_type (user_id, type)",
        "ALTER TABLE transactions ADD INDEX idx_trans_user_category (user_id, category)",
        "ALTER TABLE transactions ADD INDEX idx_trans_date (transaction_date)",
        "ALTER TABLE transactions ADD INDEX idx_trans_account (account_id)",
        
        // account_transactions 表索引
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_user (user_id)",
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_type (transaction_type)",
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_date (transaction_date)",
        "ALTER TABLE account_transactions ADD INDEX idx_acc_trans_related (related_table, related_id)",
        
        // accounts 表索引
        "ALTER TABLE accounts ADD INDEX idx_acc_user_type (user_id, account_type)",
        "ALTER TABLE accounts ADD INDEX idx_acc_balance (balance)",
        
        // budgets 表索引
        "ALTER TABLE budgets ADD INDEX idx_budget_user (user_id)",
        "ALTER TABLE budgets ADD INDEX idx_budget_month (month_year)",
        "ALTER TABLE budgets ADD INDEX idx_budget_user_month (user_id, month_year)",
        "ALTER TABLE budgets ADD INDEX idx_budget_category (category)",
        
        // friend_transactions 表索引
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_user (user_id)",
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_date (transaction_date)",
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_type (type)",
        "ALTER TABLE friend_transactions ADD INDEX idx_friend_trans_user_friend (user_id, friend_id)",
        
        // friends 表索引
        "ALTER TABLE friends ADD INDEX idx_friends_phone (phone)",
        "ALTER TABLE friends ADD INDEX idx_friends_user_created (user_id, created_at)"
    ];
    
    foreach ($indexes as $sql) {
        try {
            $pdo->exec($sql);
            echo "✓ 创建索引: " . substr($sql, 0, 60) . "...<br>";
            flush();
        } catch (PDOException $e) {
            // 忽略重复索引错误
            if (strpos($e->getMessage(), 'Duplicate key') === false) {
                throw $e;
            }
        }
    }
    
    echo "开始创建实用视图...<br>";
    flush();
    
    // 创建视图
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
        $pdo->exec($view_sql);
        echo "✓ 创建视图<br>";
        flush();
    }
    
    echo "开始插入初始数据...<br>";
    flush();
    
    // 创建管理员用户
    $password_hash = password_hash($config['admin_password'], PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
    $stmt->execute([$config['admin_username'], $config['admin_email'], $password_hash]);
    $user_id = $pdo->lastInsertId();
    
    echo "✓ 创建管理员用户: {$config['admin_username']}<br>";
    flush();
    
    // 创建默认账户
    $default_accounts = [
        ['现金账户', 'cash', 1000.00],
        ['银行账户', 'bank', 5000.00],
        ['支付宝', 'digital', 2000.00],
        ['微信钱包', 'digital', 1000.00]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO accounts (user_id, account_name, account_type, balance) VALUES (?, ?, ?, ?)");
    foreach ($default_accounts as $account) {
        $stmt->execute([$user_id, $account[0], $account[1], $account[2]]);
    }
    
    echo "✓ 创建默认账户<br>";
    flush();
    
    // 创建默认预算
    $current_month = date('Y-m-01');
    $default_budgets = [
        ['餐饮', 1000],
        ['交通', 500],
        ['娱乐', 300],
        ['购物', 800]
    ];
    
    $stmt = $pdo->prepare("INSERT INTO budgets (user_id, category, amount, month_year) VALUES (?, ?, ?, ?)");
    foreach ($default_budgets as $budget) {
        $stmt->execute([$user_id, $budget[0], $budget[1], $current_month]);
    }
    
    echo "✓ 创建默认预算<br>";
    flush();
    
    // 创建示例交易记录
    $stmt = $pdo->prepare("INSERT INTO transactions (user_id, account_id, type, amount, category, transaction_date, description) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $sample_transactions = [
        [1, 'expense', 25.50, '餐饮', date('Y-m-d H:i:s', strtotime('-1 day')), '午餐'],
        [1, 'expense', 8.00, '交通', date('Y-m-d H:i:s', strtotime('-2 days')), '地铁费'],
        [1, 'income', 5000.00, '工资', date('Y-m-d H:i:s', strtotime('-5 days')), '月薪'],
        [1, 'expense', 120.00, '购物', date('Y-m-d H:i:s', strtotime('-3 days')), '购买衣服']
    ];
    
    foreach ($sample_transactions as $transaction) {
        $stmt->execute([$user_id, $transaction[0], $transaction[1], $transaction[2], $transaction[3], $transaction[4], $transaction[5]]);
    }
    
    echo "✓ 创建示例交易记录<br>";
    flush();
    
    // 创建配置文件
    createConfigFile($config);
    echo "✓ 创建配置文件<br>";
    flush();
    
    echo "<br><strong>安装完成！</strong><br>";
    echo "管理员账号: {$config['admin_username']}<br>";
    echo "管理员密码: {$config['admin_password']}<br>";
    echo "请妥善保存这些信息，安装完成后请立即删除 install.php 文件！";
}

/**
 * 创建配置文件
 */
function createConfigFile($config) {
    $config_content = "<?php
/**
 * 记账系统数据库配置
 * 自动生成于: " . date('Y-m-d H:i:s') . "
 */

class DatabaseConfig {
    public static \$host = '{$config['db_host']}';
    public static \$dbname = '{$config['db_name']}';
    public static \$username = '{$config['db_user']}';
    public static \$password = '{$config['db_pass']}';
    public static \$charset = 'utf8mb4';
}

// 系统配置
class AppConfig {
    public static \$app_name = '个人记账系统';
    public static \$version = '1.0';
    public static \$debug = true;
}
?>";

    // 确保config目录存在
    if (!is_dir('config')) {
        mkdir('config', 0755, true);
    }
    
    file_put_contents('config/database.php', $config_content);
}

// 显示安装界面
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>记账系统安装向导</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Microsoft YaHei', Arial, sans-serif; line-height: 1.6; color: #333; background: #f5f5f5; padding: 20px; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        h1 { text-align: center; margin-bottom: 30px; color: #2c3e50; border-bottom: 2px solid #3498db; padding-bottom: 10px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 5px; font-weight: bold; color: #555; }
        input[type="text"], input[type="password"], input[type="email"] { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 16px; }
        .btn { background: #3498db; color: white; border: none; padding: 12px 20px; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; }
        .btn:hover { background: #2980b9; }
        .error { color: #e74c3c; background: #fadbd8; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .success { color: #27ae60; background: #d5f4e6; padding: 10px; border-radius: 4px; margin-bottom: 20px; }
        .section { margin-bottom: 30px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        .section-title { font-size: 18px; color: #2c3e50; margin-bottom: 15px; }
    </style>
</head>
<body>
    <div class="container">
        <h1>记账系统安装向导</h1>
        
        <?php if (isset($error)): ?>
            <div class="error">安装错误: <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <form method="POST">
            <div class="section">
                <div class="section-title">数据库配置</div>
                
                <div class="form-group">
                    <label for="db_host">数据库主机</label>
                    <input type="text" id="db_host" name="db_host" value="<?php echo htmlspecialchars($config['db_host']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="db_name">数据库名称</label>
                    <input type="text" id="db_name" name="db_name" value="<?php echo htmlspecialchars($config['db_name']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="db_user">数据库用户名</label>
                    <input type="text" id="db_user" name="db_user" value="root" required>
                </div>
                
                <div class="form-group">
                    <label for="db_pass">数据库密码</label>
                    <input type="password" id="db_pass" name="db_pass" value="">
                </div>
            </div>
            
            <div class="section">
                <div class="section-title">管理员账户</div>
                
                <div class="form-group">
                    <label for="admin_username">管理员用户名</label>
                    <input type="text" id="admin_username" name="admin_username" value="<?php echo htmlspecialchars($config['admin_username']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="admin_email">管理员邮箱</label>
                    <input type="email" id="admin_email" name="admin_email" value="<?php echo htmlspecialchars($config['admin_email']); ?>" required>
                </div>
                
                <div class="form-group">
                    <label for="admin_password">管理员密码</label>
                    <input type="password" id="admin_password" name="admin_password" value="<?php echo htmlspecialchars($config['admin_password']); ?>" required>
                </div>
            </div>
            
            <button type="submit" class="btn">开始安装</button>
        </form>
        
        <div style="margin-top: 30px; padding: 15px; background: #f8f9fa; border-radius: 4px; font-size: 14px;">
            <strong>安装说明：</strong>
            <ul style="margin-top: 10px; padding-left: 20px;">
                <li>请确保MySQL服务器正在运行</li>
                <li>安装程序将自动创建数据库和所有表结构</li>
                <li>安装完成后会自动创建管理员账户和示例数据</li>
                <li>安装完成后请立即删除 install.php 文件</li>
            </ul>
        </div>
    </div>
</body>
</html>
