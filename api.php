<?php
/**
 * API 接口
 * 所有接口返回 JSON 格式
 */

require_once 'db.php';

header('Content-Type: application/json; charset=utf-8');
session_start();

// 获取请求参数
$action = $_GET['action'] ?? ($_POST['action'] ?? '');
$method = $_SERVER['REQUEST_METHOD'];

/**
 * 返回 JSON 响应
 */
function jsonResponse(bool $success, string $message = '', $data = null)
{
    echo json_encode([
        'success' => $success,
        'message' => $message,
        'data'    => $data,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 检查是否已登录
 */
function requireLogin()
{
    if (empty($_SESSION['user_id'])) {
        jsonResponse(false, '请先登录');
    }
}

/**
 * 检查是否为管理员
 */
function requireAdmin()
{
    requireLogin();
    if (($_SESSION['role'] ?? '') !== 'admin') {
        jsonResponse(false, '需要管理员权限');
    }
}

// ==================== 认证接口 ====================

if ($action === 'login' && $method === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        jsonResponse(false, '用户名和密码不能为空');
    }

    try {
        $db = DB::getInstance();
        $user = $db->queryOne("SELECT * FROM users WHERE username = ?", [$username]);
        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['role'] = $user['role'];
            jsonResponse(true, '登录成功', [
                'id'       => $user['id'],
                'username' => $user['username'],
                'role'     => $user['role'],
            ]);
        }
        jsonResponse(false, '用户名或密码错误');
    } catch (Exception $e) {
        jsonResponse(false, '登录失败：' . $e->getMessage());
    }
}

if ($action === 'logout') {
    session_destroy();
    jsonResponse(true, '已退出登录');
}

if ($action === 'check_auth') {
    if (!empty($_SESSION['user_id'])) {
        jsonResponse(true, '已登录', [
            'id'       => $_SESSION['user_id'],
            'username' => $_SESSION['username'],
            'role'     => $_SESSION['role'],
        ]);
    }
    jsonResponse(false, '未登录');
}

if ($action === 'change_password' && $method === 'POST') {
    requireLogin();
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (strlen($newPassword) < 6) {
        jsonResponse(false, '新密码至少6位');
    }

    try {
        $db = DB::getInstance();
        $user = $db->queryOne("SELECT * FROM users WHERE id = ?", [$_SESSION['user_id']]);
        if (!password_verify($oldPassword, $user['password'])) {
            jsonResponse(false, '原密码错误');
        }
        $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
        $db->execute("UPDATE users SET password = ? WHERE id = ?", [$newHash, $_SESSION['user_id']]);
        jsonResponse(true, '密码修改成功');
    } catch (Exception $e) {
        jsonResponse(false, '修改失败：' . $e->getMessage());
    }
}

// ==================== 用户管理接口（管理员） ====================

if ($action === 'get_users') {
    requireAdmin();
    try {
        $db = DB::getInstance();
        $users = $db->query("SELECT id, username, role, created_at FROM users ORDER BY id ASC");
        jsonResponse(true, '', $users);
    } catch (Exception $e) {
        jsonResponse(false, $e->getMessage());
    }
}

if ($action === 'create_user' && $method === 'POST') {
    requireAdmin();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';

    if ($username === '' || strlen($password) < 6) {
        jsonResponse(false, '用户名不能为空，密码至少6位');
    }
    if (!in_array($role, ['admin', 'user'])) {
        jsonResponse(false, '角色无效');
    }

    try {
        $db = DB::getInstance();
        $exists = $db->queryOne("SELECT id FROM users WHERE username = ?", [$username]);
        if ($exists) {
            jsonResponse(false, '用户名已存在');
        }
        $hash = password_hash($password, PASSWORD_BCRYPT);
        $db->execute("INSERT INTO users (username, password, role) VALUES (?, ?, ?)", [$username, $hash, $role]);
        jsonResponse(true, '用户创建成功');
    } catch (Exception $e) {
        jsonResponse(false, '创建失败：' . $e->getMessage());
    }
}

if ($action === 'update_user' && $method === 'POST') {
    requireAdmin();
    $userId = intval($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($userId <= 0) {
        jsonResponse(false, '用户ID无效');
    }
    if (!in_array($role, ['admin', 'user'])) {
        jsonResponse(false, '角色无效');
    }

    try {
        $db = DB::getInstance();
        if ($newPassword !== '') {
            if (strlen($newPassword) < 6) {
                jsonResponse(false, '新密码至少6位');
            }
            $hash = password_hash($newPassword, PASSWORD_BCRYPT);
            $db->execute("UPDATE users SET role = ?, password = ? WHERE id = ?", [$role, $hash, $userId]);
        } else {
            $db->execute("UPDATE users SET role = ? WHERE id = ?", [$role, $userId]);
        }
        jsonResponse(true, '用户更新成功');
    } catch (Exception $e) {
        jsonResponse(false, '更新失败：' . $e->getMessage());
    }
}

if ($action === 'delete_user' && $method === 'POST') {
    requireAdmin();
    $userId = intval($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonResponse(false, '用户ID无效');
    }
    // 不能删除自己
    if ($userId == $_SESSION['user_id']) {
        jsonResponse(false, '不能删除自己的账号');
    }

    try {
        $db = DB::getInstance();
        $db->execute("DELETE FROM users WHERE id = ?", [$userId]);
        jsonResponse(true, '用户已删除');
    } catch (Exception $e) {
        jsonResponse(false, '删除失败：' . $e->getMessage());
    }
}

// ==================== 配置接口 ====================

if ($action === 'get_settings') {
    try {
        $db = DB::getInstance();
        $rows = $db->query("SELECT setting_key, setting_value FROM settings");
        $settings = [];
        foreach ($rows as $row) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        jsonResponse(true, '', $settings);
    } catch (Exception $e) {
        jsonResponse(false, $e->getMessage());
    }
}

if ($action === 'save_settings' && $method === 'POST') {
    requireAdmin();
    $settings = $_POST['settings'] ?? null;

    // FormData 传过来的是 JSON 字符串，需解码
    if (is_string($settings)) {
        $settings = json_decode($settings, true);
    }

    if (!$settings || !is_array($settings)) {
        jsonResponse(false, '无效的配置数据');
    }

    try {
        $db = DB::getInstance();
        foreach ($settings as $key => $value) {
            // 防止注入非法key
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }
            $exists = $db->queryOne("SELECT id FROM settings WHERE setting_key = ?", [$key]);
            if ($exists) {
                $db->execute("UPDATE settings SET setting_value = ? WHERE setting_key = ?", [$value, $key]);
            } else {
                $db->execute("INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)", [$key, $value]);
            }
        }
        jsonResponse(true, '配置保存成功');
    } catch (Exception $e) {
        jsonResponse(false, '保存失败：' . $e->getMessage());
    }
}

if ($action === 'get_mode') {
    try {
        $db = DB::getInstance();
        $row = $db->queryOne("SELECT setting_value FROM settings WHERE setting_key = 'mode'");
        $mode = $row['setting_value'] ?? 'online';
        jsonResponse(true, '', ['mode' => $mode]);
    } catch (Exception $e) {
        jsonResponse(true, '', ['mode' => 'online']);
    }
}

// ==================== 公告接口 ====================

if ($action === 'get_announcement') {
    try {
        $db = DB::getInstance();
        $now = date('Y-m-d H:i:s');
        $ann = $db->queryOne(
            "SELECT * FROM announcements 
             WHERE is_active = 1 
             AND (show_from IS NULL OR show_from <= ?) 
             AND (show_until IS NULL OR show_until >= ?)
             ORDER BY created_at DESC LIMIT 1",
            [$now, $now]
        );
        jsonResponse(true, '', $ann);
    } catch (Exception $e) {
        jsonResponse(false, $e->getMessage());
    }
}

if ($action === 'get_announcements') {
    requireAdmin();
    try {
        $db = DB::getInstance();
        $list = $db->query("SELECT * FROM announcements ORDER BY created_at DESC");
        jsonResponse(true, '', $list);
    } catch (Exception $e) {
        jsonResponse(false, $e->getMessage());
    }
}

if ($action === 'save_announcement' && $method === 'POST') {
    requireAdmin();
    $id = $_POST['id'] ?? null;
    $content = $_POST['content'] ?? '';
    $showFrom = $_POST['show_from'] ?: null;
    $showUntil = $_POST['show_until'] ?: null;
    $isActive = intval($_POST['is_active'] ?? 1);

    try {
        $db = DB::getInstance();
        if ($id) {
            $db->execute(
                "UPDATE announcements SET content=?, show_from=?, show_until=?, is_active=? WHERE id=?",
                [$content, $showFrom, $showUntil, $isActive, $id]
            );
        } else {
            $db->execute(
                "INSERT INTO announcements (content, show_from, show_until, is_active) VALUES (?, ?, ?, ?)",
                [$content, $showFrom, $showUntil, $isActive]
            );
        }
        jsonResponse(true, '公告保存成功');
    } catch (Exception $e) {
        jsonResponse(false, '保存失败：' . $e->getMessage());
    }
}

if ($action === 'delete_announcement' && $method === 'POST') {
    requireAdmin();
    $id = intval($_POST['id'] ?? 0);
    try {
        $db = DB::getInstance();
        $db->execute("DELETE FROM announcements WHERE id = ?", [$id]);
        jsonResponse(true, '公告已删除');
    } catch (Exception $e) {
        jsonResponse(false, '删除失败：' . $e->getMessage());
    }
}

// ==================== 未匹配的 action ====================

jsonResponse(false, '未知操作：' . $action);
