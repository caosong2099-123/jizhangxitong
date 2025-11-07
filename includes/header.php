<?php
require_once 'config.php';
require_once 'auth.php';
require_once 'functions.php';

// 设置字符集
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人记账系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
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
                <li><a href="dashboard.php" <?php echo basename($_SERVER['PHP_SELF']) == 'dashboard.php' ? 'class="active"' : ''; ?>>仪表盘</a></li>
                <li><a href="add_record.php" <?php echo basename($_SERVER['PHP_SELF']) == 'add_record.php' ? 'class="active"' : ''; ?>>添加记录</a></li>
                <li><a href="view_records.php" <?php echo basename($_SERVER['PHP_SELF']) == 'view_records.php' ? 'class="active"' : ''; ?>>查看记录</a></li>
                <li><a href="budget.php" <?php echo basename($_SERVER['PHP_SELF']) == 'budget.php' ? 'class="active"' : ''; ?>>预算管理</a></li>
                <li><a href="friends_management.php" <?php echo basename($_SERVER['PHP_SELF']) == 'friends_management.php' ? 'class="active"' : ''; ?>>朋友借贷</a></li>
                <li><a href="account_management.php" <?php echo basename($_SERVER['PHP_SELF']) == 'account_management.php' ? 'class="active"' : ''; ?>>账户管理</a></li>
                <li><a href="account_transactions.php" <?php echo basename($_SERVER['PHP_SELF']) == 'account_transactions.php' ? 'class="active"' : ''; ?>>账户流水</a></li>
                <li><a href="reports.php" <?php echo basename($_SERVER['PHP_SELF']) == 'reports.php' ? 'class="active"' : ''; ?>>统计报表</a></li>
                <li><a href="export.php" <?php echo basename($_SERVER['PHP_SELF']) == 'export.php' ? 'class="active"' : ''; ?>>数据导出</a></li>
                <li><a href="backup.php" <?php echo basename($_SERVER['PHP_SELF']) == 'backup.php' ? 'class="active"' : ''; ?>>数据备份</a></li>
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
