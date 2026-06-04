<?php
/**
 * 数据库初始化脚本
 * 
 * 使用方法：
 * 1. 在宝塔面板创建数据库 semester_countdown
 * 2. 修改 db.php 中的数据库连接信息
 * 3. 浏览器访问 install.php 完成初始化
 * 4. 初始化成功后务必删除此文件！
 */

require_once 'db.php';

header('Content-Type: text/html; charset=utf-8');

try {
    $db = DB::getInstance();

    // 创建用户表
    $db->execute("CREATE TABLE IF NOT EXISTS `users` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `username` VARCHAR(50) NOT NULL UNIQUE,
        `password` VARCHAR(255) NOT NULL,
        `role` ENUM('admin', 'user') NOT NULL DEFAULT 'user',
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 创建设置表
    $db->execute("CREATE TABLE IF NOT EXISTS `settings` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `setting_key` VARCHAR(100) NOT NULL UNIQUE,
        `setting_value` TEXT,
        `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 创建公告表
    $db->execute("CREATE TABLE IF NOT EXISTS `announcements` (
        `id` INT AUTO_INCREMENT PRIMARY KEY,
        `content` TEXT,
        `show_from` DATETIME DEFAULT NULL,
        `show_until` DATETIME DEFAULT NULL,
        `is_active` TINYINT(1) DEFAULT 1,
        `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // 检查是否已有管理员
    $admin = $db->queryOne("SELECT id FROM users WHERE username = 'admin'");
    if (!$admin) {
        $hash = password_hash('admin123', PASSWORD_BCRYPT);
        $db->execute("INSERT INTO users (username, password, role) VALUES (?, ?, 'admin')", ['admin', $hash]);
        $adminCreated = true;
    } else {
        $adminCreated = false;
    }

    // 插入默认设置（不存在则插入）
    $defaultSettings = [
        'start_date'              => '2026-03-05 06:30:00',
        'end_date'                => '2026-07-03 17:00:00',
        'holidays'                => json_encode(['2026-04-06', '2026-05-01', '2026-05-04', '2026-05-05', '2026-06-19']),
        'workdays'                => json_encode(['2026-05-09']),
        'mode'                    => 'online',
        'bg_type'                 => 'solid',
        'bg_color'                => '#f7f9fc',
        'bg_image_url'            => '',
        'bg_image_opacity'        => '0.3',
        'glass_opacity'           => '0.7',
        'primary_color'           => '#5c6bc0',
        'font_color'              => '#333333',
        'card_bg'                 => '#ffffff',
        'page_bg'                 => '#f7f9fc',
        'progress_bar_start'      => '#7ed957',
        'progress_bar_end'        => '#2e8b57',
        'progress_percent_color'  => '#5c6bc0',
        'title'                   => '什么时候放假啊o(╥﹏╥)o',
        'announcement_auto_close' => '5',
    ];

    foreach ($defaultSettings as $key => $value) {
        $exists = $db->queryOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
        if (!$exists) {
            $db->execute("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
        }
    }

    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>安装完成</title>
    <style>
        body { font-family: -apple-system, sans-serif; background: #f0f4f8; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; }
        .card { background: #fff; border-radius: 16px; padding: 40px; box-shadow: 0 4px 24px rgba(0,0,0,0.08); max-width: 500px; text-align: center; }
        .success { color: #2e7d32; font-size: 48px; margin-bottom: 16px; }
        h1 { color: #333; margin-bottom: 8px; }
        p { color: #666; line-height: 1.8; }
        .warn { background: #fff3e0; border: 1px solid #ffcc80; border-radius: 8px; padding: 12px; color: #e65100; margin-top: 20px; font-size: 14px; }
        .info { background: #e3f2fd; border-radius: 8px; padding: 12px; color: #1565c0; margin-top: 12px; font-size: 13px; text-align: left; }
        a { color: #5c6bc0; }
    </style></head><body><div class="card">
        <div class="success">✓</div>
        <h1>数据库初始化成功！</h1>
        <p>数据表已创建' . ($adminCreated ? '，默认管理员已添加' : '，管理员账号已存在') . '</p>
        <div class="info">
            <strong>默认管理员账号：</strong><br>
            用户名：admin<br>
            密码：admin123<br>
            <strong style="color:#c62828;">请尽快登录管理面板修改密码！</strong>
        </div>
        <div class="warn">⚠ 请立即删除 install.php 文件，防止被他人利用！</div>
        <p style="margin-top:20px;"><a href="index.php">前往首页</a> | <a href="admin.php">前往管理面板</a></p>
    </div></body></html>';

} catch (Exception $e) {
    echo '<!DOCTYPE html><html lang="zh-CN"><head><meta charset="UTF-8"><title>安装失败</title>
    <style>body{font-family:-apple-system,sans-serif;display:flex;justify-content:center;align-items:center;min-height:100vh;background:#f0f4f8;}
    .card{background:#fff;border-radius:16px;padding:40px;box-shadow:0 4px 24px rgba(0,0,0,0.08);max-width:500px;}
    .fail{color:#c62828;font-size:48px;text-align:center;}h1{color:#333;}pre{background:#f5f5f5;padding:12px;border-radius:8px;overflow-x:auto;font-size:13px;}</style>
    </head><body><div class="card"><div class="fail">✗</div><h1>安装失败</h1>
    <p>请检查 <code>db.php</code> 中的数据库连接信息是否正确。</p>
    <p>确保你已在宝塔面板中创建了数据库 <strong>semester_countdown</strong>。</p>
    <pre>' . htmlspecialchars($e->getMessage()) . '</pre></div></body></html>';
}
