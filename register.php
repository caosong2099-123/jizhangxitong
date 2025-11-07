<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php'; // 添加这一行以包含 functions.php

// 如果用户已登录，重定向到仪表盘
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// 处理注册表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    
    // 验证输入
    $errors = [];
    
    if (empty($username) || strlen($username) < 3) {
        $errors[] = "用户名至少需要3个字符";
    }
    
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = "请输入有效的邮箱地址";
    }
    
    if (empty($password) || strlen($password) < 6) {
        $errors[] = "密码至少需要6个字符";
    }
    
    if ($password !== $confirm_password) {
        $errors[] = "两次输入的密码不一致";
    }
    
    // 检查用户名和邮箱是否已存在
    if (empty($errors)) {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $email]);
        
        if ($stmt->fetch()) {
            $errors[] = "用户名或邮箱已存在";
        }
    }
    
    // 如果没有错误，创建用户
    if (empty($errors)) {
        $password_hash = hashPassword($password);
        
        $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)");
        
        if ($stmt->execute([$username, $email, $password_hash])) {
            setMessage("注册成功，请登录", "success");
            header("Location: index.php");
            exit();
        } else {
            $errors[] = "注册失败，请稍后重试";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>注册 - 个人记账系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <h2>用户注册</h2>
        
        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <?php foreach ($errors as $error): ?>
            <p><?php echo safeOutput($error); ?></p>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required value="<?php echo isset($_POST['username']) ? safeOutput($_POST['username']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="email">邮箱</label>
                <input type="email" id="email" name="email" required value="<?php echo isset($_POST['email']) ? safeOutput($_POST['email']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <div class="form-group">
                <label for="confirm_password">确认密码</label>
                <input type="password" id="confirm_password" name="confirm_password" required>
            </div>
            
            <button type="submit">注册</button>
        </form>
        
        <p style="text-align: center; margin-top: 20px;">
            已有账户？<a href="index.php">立即登录</a>
        </p>
    </div>
</body>
</html>