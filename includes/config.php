<?php
// 数据库配置
$db_host = 'localhost';
$db_user = 'root';
$db_pass = '';
$db_name = 'jizhangxitong';

// 创建数据库连接
try {
    $pdo = new PDO("mysql:host=$db_host;dbname=$db_name;charset=utf8mb4", $db_user, $db_pass);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch(PDOException $e) {
    // 显示友好的错误信息
    die("
        <div style='padding: 20px; background: #f8d7da; color: #721c24; border-radius: 5px;'>
            <h3>数据库连接失败</h3>
            <p>错误信息: " . $e->getMessage() . "</p>
            <p>请先运行 <a href='upgrade_accounts_jizhang.php'>数据库升级脚本</a> 来初始化账户功能。</p>
        </div>
    ");
}

// 设置时区
date_default_timezone_set('Asia/Shanghai');
?>
