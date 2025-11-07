<?php
// complete_repair.php - 完整数据库修复脚本（修复约束版本）
try {
    $pdo = new PDO("mysql:host=localhost", "root", "");
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 创建数据库（如果不存在）- 使用固定名称便于移植
    $db_name = "jizhangxitong";
    $pdo->exec("CREATE DATABASE IF NOT EXISTS $db_name CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
    $pdo->exec("USE $db_name");
    
    echo "<h2>个人记账系统 - 数据库初始化</h2>";
    echo "<p>正在创建数据库: <strong>$db_name</strong></p>";
    
    // 删除所有现有表（从头开始重建）
    $tables = ['account_transactions', 'accounts', 'friend_transactions', 'friends', 'budgets', 'transactions', 'users'];
    foreach ($tables as $table) {
        try {
            $pdo->exec("DROP TABLE IF EXISTS $table");
            echo "清理表: $table <span style='color:green'>✓</span><br>";
        } catch (Exception $e) {
            echo "清理表 $table: <span style='color:orange'>跳过</span><br>";
        }
    }
    
    echo "<hr>";
    
    // 创建用户表
    $sql = "CREATE TABLE users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        username VARCHAR(50) UNIQUE NOT NULL,
        email VARCHAR(100) UNIQUE NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "创建用户表 <span style='color:green'>✓</span><br>";
    
    // 创建账户表 - 修复约束问题
    $sql = "CREATE TABLE accounts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        account_name VARCHAR(100) NOT NULL,
        account_type VARCHAR(50) NOT NULL,
        balance DECIMAL(10, 2) DEFAULT 0.00,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_account_name (user_id, account_name)
    )";
    $pdo->exec($sql);
    echo "创建账户表 <span style='color:green'>✓</span><br>";
    
    // 创建交易记录表
    $sql = "CREATE TABLE transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        account_id INT,
        type ENUM('income', 'expense') NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        category VARCHAR(50) NOT NULL,
        transaction_date DATETIME NOT NULL,
        description TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "创建交易记录表 <span style='color:green'>✓</span><br>";
    
    // 创建预算表
    $sql = "CREATE TABLE budgets (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        category VARCHAR(50) NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        month_year DATE NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )";
    $pdo->exec($sql);
    echo "创建预算表 <span style='color:green'>✓</span><br>";
    
    // 创建朋友表
    $sql = "CREATE TABLE friends (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        phone VARCHAR(20),
        note TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_friend (user_id, name)
    )";
    $pdo->exec($sql);
    echo "创建朋友表 <span style='color:green'>✓</span><br>";
    
    // 创建借贷关系表
    $sql = "CREATE TABLE friend_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        account_id INT,
        friend_id INT NOT NULL,
        type ENUM('lend', 'repay', 'borrow', 'return') NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        description TEXT,
        transaction_date DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (friend_id) REFERENCES friends(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "创建借贷关系表 <span style='color:green'>✓</span><br>";
    
    // 创建账户流水表
    $sql = "CREATE TABLE account_transactions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        account_id INT NOT NULL,
        transaction_type ENUM('income', 'expense', 'transfer', 'lend', 'borrow', 'repay', 'return') NOT NULL,
        amount DECIMAL(10, 2) NOT NULL,
        related_table VARCHAR(50) NOT NULL,
        related_id INT NOT NULL,
        description TEXT,
        transaction_date DATETIME NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE CASCADE
    )";
    $pdo->exec($sql);
    echo "创建账户流水表 <span style='color:green'>✓</span><br>";
    
    // 创建测试用户
    $password_hash = password_hash('123456', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO users (username, email, password_hash) VALUES 
        ('testuser', 'test@example.com', '$password_hash')");
    echo "创建测试用户 <span style='color:green'>✓</span><br>";
    
    echo "<hr>";
    echo "<h3 style='color: green;'>✅ 数据库初始化完成！</h3>";
    echo "<div style='background: #f0f8ff; padding: 20px; border-radius: 5px; margin: 20px 0;'>";
    echo "<p><strong>数据库信息：</strong></p>";
    echo "<ul>";
    echo "<li>数据库名称: <strong>$db_name</strong></li>";
    echo "<li>测试用户名: <strong>testuser</strong></li>";
    echo "<li>测试密码: <strong>123456</strong></li>";
    echo "</ul>";
    echo "<p><strong>注意：</strong> 登录后请在'账户管理'页面手动创建您的账户。</p>";
    echo "</div>";
    
    echo "<p>现在您可以：</p>";
    echo "<ul>";
    echo "<li><a href='index.php' style='color: blue;'>前往登录页面</a></li>";
    echo "<li><a href='register.php' style='color: blue;'>注册新用户</a></li>";
    echo "</ul>";
    
} catch (PDOException $e) {
    echo "<h3 style='color: red;'>❌ 数据库初始化失败</h3>";
    echo "<div style='background: #ffe6e6; padding: 15px; border-radius: 5px;'>";
    echo "<p><strong>错误信息：</strong> " . $e->getMessage() . "</p>";
    echo "<p><strong>请检查：</strong></p>";
    echo "<ul>";
    echo "<li>MySQL服务是否启动</li>";
    echo "<li>数据库用户root的密码是否正确（当前使用空密码）</li>";
    echo "<li>是否有足够的数据库权限</li>";
    echo "</ul>";
    echo "</div>";
}
?>