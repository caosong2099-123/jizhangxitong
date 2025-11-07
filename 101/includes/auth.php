<?php
session_start();

// 检查用户是否已登录
function isLoggedIn() {
    return isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
}

// 重定向到登录页面
function redirectToLogin() {
    header("Location: index.php");
    exit();
}

// 检查登录状态
function requireLogin() {
    if (!isLoggedIn()) {
        redirectToLogin();
    }
}

// 密码哈希
function hashPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

// 验证密码
function verifyPassword($password, $hash) {
    return password_verify($password, $hash);
}

// 登录用户
function loginUser($user_id, $username) {
    $_SESSION['user_id'] = $user_id;
    $_SESSION['username'] = $username;
}

// 注销用户
function logoutUser() {
    session_unset();
    session_destroy();
    header("Location: index.php");
    exit();
}

// 获取当前用户ID
function getCurrentUserId() {
    return isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;
}

// 设置消息
function setMessage($message, $type = 'success') {
    $_SESSION['message'] = $message;
    $_SESSION['message_type'] = $type;
}

// 获取消息
function getMessage() {
    if (isset($_SESSION['message'])) {
        $message = $_SESSION['message'];
        $type = $_SESSION['message_type'];
        unset($_SESSION['message']);
        unset($_SESSION['message_type']);
        return ['message' => $message, 'type' => $type];
    }
    return null;
}
?>