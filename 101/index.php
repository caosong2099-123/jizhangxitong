<?php
require_once 'includes/config.php';
require_once 'includes/auth.php';
require_once 'includes/functions.php'; // 添加这一行以包含 functions.php

// 如果用户已登录，重定向到仪表盘
if (isLoggedIn()) {
    header("Location: dashboard.php");
    exit();
}

// 处理登录表单提交
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    // 验证输入
    if (empty($username) || empty($password)) {
        $error = "请填写用户名和密码";
    } else {
        // 查询用户
        $stmt = $pdo->prepare("SELECT id, username, password_hash FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($user && verifyPassword($password, $user['password_hash'])) {
            // 登录成功
            loginUser($user['id'], $user['username']);
            setMessage("登录成功！", "success");
            header("Location: dashboard.php");
            exit();
        } else {
            $error = "用户名或密码错误";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>登录 - 个人记账系统</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
    <div class="login-container">
        <h2>个人记账系统</h2>
        
        <?php if (isset($error)): ?>
        <div class="alert alert-error"><?php echo safeOutput($error); ?></div>
        <?php endif; ?>
        
        <form method="POST" action="">
            <div class="form-group">
                <label for="username">用户名</label>
                <input type="text" id="username" name="username" required value="<?php echo isset($_POST['username']) ? safeOutput($_POST['username']) : ''; ?>">
            </div>
            
            <div class="form-group">
                <label for="password">密码</label>
                <input type="password" id="password" name="password" required>
            </div>
            
            <button type="submit">登录</button>
        </form>
        
        <p style="text-align: center; margin-top: 20px;">
            还没有账户？<a href="register.php">立即注册</a>
        </p>
        
        <!-- 添加数据库修复链接，方便调试 -->
        <p style="text-align: center; margin-top: 10px; font-size: 12px; color: #666;">
            <a href="upgrade_database.php" style="color: #666;">数据库问题？点击修复</a>
        </p>
    </div>
</body>
</html>