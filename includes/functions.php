<?php
// includes/functions.php

// 安全输出函数
function safeOutput($str) {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}

// 格式化金额显示
function formatAmount($amount) {
    return '￥' . number_format($amount, 2);
}

// 获取分类选项
function getCategories($type) {
    $categories = [
        'income' => ['工资', '奖金', '投资回报', '兼职收入', '其他收入'],
        'expense' => ['食品', '住房', '交通', '娱乐', '医疗', '教育', '购物', '其他支出']
    ];
    
    return isset($categories[$type]) ? $categories[$type] : [];
}

// 验证日期格式
function isValidDate($date) {
    if (empty($date)) return false;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// 验证日期时间格式
function isValidDateTime($datetime) {
    if (empty($datetime)) return false;
    $d = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if ($d && $d->format('Y-m-d H:i:s') === $datetime) {
        return true;
    }
    // 也支持没有秒的格式
    $d = DateTime::createFromFormat('Y-m-d H:i', $datetime);
    return $d && $d->format('Y-m-d H:i') === $datetime;
}

// 格式化日期时间显示
function formatDateTime($datetime) {
    if (empty($datetime)) return '';
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if ($date) {
        return $date->format('Y-m-d H:i');
    }
    return $datetime;
}

// 格式化详细日期时间显示
function formatDateTimeDetailed($datetime) {
    if (empty($datetime)) return '';
    $date = DateTime::createFromFormat('Y-m-d H:i:s', $datetime);
    if ($date) {
        return $date->format('Y-m-d H:i:s');
    }
    return $datetime;
}

// 重定向
function redirect($url) {
    header("Location: $url");
    exit();
}
?>