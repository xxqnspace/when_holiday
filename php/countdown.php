<?php
/**
 * 数据处理模块
 * 优先从 JSON 数据文件读取配置（在线模式），fallback 到 config.php
 */

if (!isset($config)) {
    $config = include 'config.php';
}

// 尝试从 JSON 文件加载配置
$dbLoaded = false;
try {
    require_once 'storage.php';
    $data = json_load();
    foreach ($data['settings'] as $key => $val) {
        $config[$key] = $val;
    }
    $dbLoaded = true;
} catch (Exception $e) {
    // 数据文件不可用时静默 fallback 到 config.php
}

// 将配置转化为时间戳和数组，供前端 JS 使用
$initData = [
    'serverNow'           => time() * 1000,
    'start'               => strtotime($config['start_date']) * 1000,
    'end'                 => strtotime($config['end_date']) * 1000,
    'holidays'            => $config['holidays'],
    'workdays'            => $config['workdays'],
    'dbLoaded'            => $dbLoaded,
    // 传递 UI 配置
    'mode'                => $config['mode'] ?? 'online',
    'bg_type'             => $config['bg_type'] ?? 'solid',
    'bg_color'            => $config['bg_color'] ?? '#f7f9fc',
    'bg_image_url'        => $config['bg_image_url'] ?? '',
    'bg_image_opacity'    => $config['bg_image_opacity'] ?? '0.3',
    'glass_opacity'       => $config['glass_opacity'] ?? '0.7',
    'primary_color'       => $config['primary_color'] ?? '#5c6bc0',
    'font_color'          => $config['font_color'] ?? '#333333',
    'card_bg'             => $config['card_bg'] ?? '#ffffff',
    'page_bg'             => $config['page_bg'] ?? '#f7f9fc',
    'progress_bar_start'  => $config['progress_bar_start'] ?? '#7ed957',
    'progress_bar_end'    => $config['progress_bar_end'] ?? '#2e8b57',
    'progress_percent_color' => $config['progress_percent_color'] ?? '#5c6bc0',
    'title'               => $config['title'] ?? '什么时候放假啊o(╥﹏╥)o',
    'announcement_auto_close' => $config['announcement_auto_close'] ?? '5',
];

// 如果是 AJAX 请求，返回 JSON
if (isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    echo json_encode($initData);
    exit;
}
