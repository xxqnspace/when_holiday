<?php
/**
 * 默认配置（兜底值）
 * 当数据库不可用或处于本地模式时使用这些默认值
 */

date_default_timezone_set('Asia/Shanghai');

return [
    // ======== 学期时间 ========
    'start_date' => '2026-03-05 06:30:00',
    'end_date'   => '2026-07-03 17:00:00',

    // 特殊假期（YYYY-MM-DD）
    'holidays' => [
        '2026-04-06',
        '2026-05-01', '2026-05-04', '2026-05-05',
        '2026-06-19',
    ],

    // 特殊工作日（原本周末但需补班）
    'workdays' => [
        '2026-05-09',
    ],

    // ======== 运行模式 ========
    'mode' => 'online', // online | local

    // ======== 背景配置 ========
    'bg_type'           => 'solid',   // solid | image
    'bg_color'          => '#f7f9fc',
    'bg_image_url'      => '',
    'bg_image_opacity'  => '0.3',
    'glass_opacity'     => '0.7',

    // ======== 界面配色 ========
    'primary_color'          => '#5c6bc0',
    'font_color'             => '#333333',
    'card_bg'                => '#ffffff',
    'page_bg'                => '#f7f9fc',
    'progress_bar_start'     => '#7ed957',
    'progress_bar_end'       => '#2e8b57',
    'progress_percent_color' => '#5c6bc0',

    // ======== 页面标题 ========
    'title' => '什么时候放假啊o(╥﹏╥)o',

    // ======== 公告设置 ========
    'announcement_auto_close' => '5',
];
