<?php
/**
 * JSON 文件存储层
 * 替代 MySQL，适用于数据量小的场景（个人/小范围使用）
 *
 * 数据文件：data/db.json.php（首次访问自动创建）
 * 首次初始化自带默认管理员：admin / admin123（请尽快在管理面板修改密码）
 *
 * 安全设计（无需配置 Nginx/Apache）：
 *  - 数据文件伪装成 PHP 文件（db.json.php），首行含 "<?php exit; ?>"
 *  - 浏览器直接访问 URL 时，PHP 执行 exit 返回空白，数据不会泄露
 *  - PHP 内部读取时剥离伪装前缀再解析 JSON
 *  - data/ 目录仍附带 .htaccess 双保险（对 Apache 生效）
 *
 * 特性：
 *  - 原子写入（临时文件 + rename），防止写一半损坏
 *  - 读-改-写加文件锁（flock），防止并发写坏
 *  - 自动迁移旧版 data/db.json（纯 JSON）到新格式
 */

define('DATA_DIR', __DIR__ . '/data');
define('DATA_FILE', DATA_DIR . '/db.json.php');
define('DATA_PREFIX', "<?php exit; ?>\n");

date_default_timezone_set('Asia/Shanghai');

/**
 * 默认数据（首次安装时生成）
 */
function json_default_data(): array
{
    $config = include __DIR__ . '/config.php';
    $now = date('Y-m-d H:i:s');
    return [
        'users' => [[
            'id'          => 1,
            'username'    => 'admin',
            'password'    => password_hash('admin123', PASSWORD_BCRYPT),
            'role'        => 'admin',
            'created_at'  => $now,
        ]],
        'settings' => [
            'start_date'             => $config['start_date'],
            'end_date'               => $config['end_date'],
            'holidays'               => $config['holidays'],
            'workdays'               => $config['workdays'],
            'mode'                   => $config['mode'],
            'bg_type'                => $config['bg_type'],
            'bg_color'               => $config['bg_color'],
            'bg_image_url'           => $config['bg_image_url'],
            'bg_image_opacity'       => $config['bg_image_opacity'],
            'glass_opacity'          => $config['glass_opacity'],
            'primary_color'          => $config['primary_color'],
            'font_color'             => $config['font_color'],
            'card_bg'                => $config['card_bg'],
            'page_bg'                => $config['page_bg'],
            'progress_bar_start'     => $config['progress_bar_start'],
            'progress_bar_end'       => $config['progress_bar_end'],
            'progress_percent_color' => $config['progress_percent_color'],
            'title'                  => $config['title'],
            'announcement_auto_close' => $config['announcement_auto_close'],
        ],
        'announcements' => [],
        'next_user_id'  => 2,
        'next_ann_id'   => 1,
    ];
}

/**
 * 剥离数据文件的伪装前缀，返回纯 JSON 文本
 */
function json_strip_prefix(string $raw): string
{
    if (strncmp($raw, '<?php', 5) === 0) {
        $pos = strpos($raw, '?>');
        if ($pos !== false) {
            $raw = substr($raw, $pos + 2);
        }
    }
    return $raw;
}

/**
 * 读取全部数据；文件不存在时自动初始化
 * 兼容旧版 data/db.json（纯 JSON），首次读取时自动迁移
 */
function json_load(): array
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }

    $legacy = DATA_DIR . '/db.json';
    if (!file_exists(DATA_FILE) && file_exists($legacy)) {
        $old = @file_get_contents($legacy);
        $data = json_decode((string)$old, true);
        if (is_array($data)) {
            json_save($data);
            @unlink($legacy);
            return $data;
        }
    }

    if (!file_exists(DATA_FILE)) {
        $data = json_default_data();
        json_save($data);
        return $data;
    }
    $raw = @file_get_contents(DATA_FILE);
    $data = json_decode(json_strip_prefix((string)$raw), true);
    return is_array($data) ? $data : json_default_data();
}

/**
 * 原子写入：临时文件 + rename，避免写入一半损坏文件
 */
function json_save(array $data): bool
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    $tmp = DATA_FILE . '.tmp';
    $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        return false;
    }
    if (file_put_contents($tmp, DATA_PREFIX . $json) === false) {
        return false;
    }
    return rename($tmp, DATA_FILE);
}

/**
 * 读-改-写：加文件锁防止并发写坏
 * 用法：json_update(function (&$data) { $data['xxx'] = 'yyy'; });
 */
function json_update(callable $mutator): array
{
    if (!is_dir(DATA_DIR)) {
        @mkdir(DATA_DIR, 0755, true);
    }
    $fp = fopen(DATA_FILE, 'c+');
    if (!$fp) {
        return json_load();
    }
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = $raw ? json_decode(json_strip_prefix($raw), true) : null;
    if (!is_array($data)) {
        $data = json_default_data();
    }
    $mutator($data);
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, DATA_PREFIX . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data;
}

/**
 * 按用户名查找用户
 */
function json_find_user(array $data, string $username): ?array
{
    foreach ($data['users'] as $u) {
        if ($u['username'] === $username) {
            return $u;
        }
    }
    return null;
}

/**
 * 按 id 查找用户
 */
function json_find_user_by_id(array $data, int $id): ?array
{
    foreach ($data['users'] as $u) {
        if (intval($u['id']) === $id) {
            return $u;
        }
    }
    return null;
}
