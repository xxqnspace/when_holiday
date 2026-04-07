<?php
// countdown.php
if (!isset($config)) {
    $config = include 'config.php';
}

// 将配置转化为时间戳和数组，供前端JS使用
$initData = [
    'serverNow' => time() * 1000, // 服务器当前毫秒时间戳
    'start'     => strtotime($config['start_date']) * 1000,
    'end'       => strtotime($config['end_date']) * 1000,
    'holidays'  => $config['holidays'], // Y-m-d 格式数组
    'workdays'  => $config['workdays']  // Y-m-d 格式数组
];

// 如果是 API 调用（虽然现在不需要轮询，但保留接口以备不时之需）
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode($initData);
    exit;
}
?>
