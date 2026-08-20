<?php
/**
 * API 接口（JSON 存储版）
 * 所有接口返回 JSON 格式
 */

require_once 'storage.php';

header('Content-Type: application/json; charset=utf-8');
session_start();

// 生成/获取 CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

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

/**
 * CSRF 校验（写操作必须携带 token）
 */
function requireCsrf()
{
    $token = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    if (empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        jsonResponse(false, '安全校验失败，请刷新页面重试');
    }
}

// ==================== 认证接口 ====================

if ($action === 'login' && $method === 'POST') {
    requireCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        jsonResponse(false, '用户名和密码不能为空');
    }

    $data = json_load();
    $user = json_find_user($data, $username);
    if ($user && password_verify($password, $user['password'])) {
        session_regenerate_id(true);
        $_SESSION['user_id']    = $user['id'];
        $_SESSION['username']   = $user['username'];
        $_SESSION['role']       = $user['role'];
        jsonResponse(true, '登录成功', [
            'id'       => $user['id'],
            'username' => $user['username'],
            'role'     => $user['role'],
        ]);
    }
    jsonResponse(false, '用户名或密码错误');
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
    requireCsrf();
    $oldPassword = $_POST['old_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if (strlen($newPassword) < 6) {
        jsonResponse(false, '新密码至少6位');
    }

    $data = json_load();
    $user = json_find_user_by_id($data, intval($_SESSION['user_id']));
    if (!$user || !password_verify($oldPassword, $user['password'])) {
        jsonResponse(false, '原密码错误');
    }
    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    json_update(function (&$data) use ($newHash) {
        foreach ($data['users'] as &$u) {
            if ($u['id'] == $_SESSION['user_id']) {
                $u['password'] = $newHash;
                break;
            }
        }
        unset($u);
    });
    jsonResponse(true, '密码修改成功');
}

// ==================== 用户管理接口（管理员） ====================

if ($action === 'get_users') {
    requireAdmin();
    $data = json_load();
    $users = array_map(function ($u) {
        return [
            'id'         => $u['id'],
            'username'   => $u['username'],
            'role'       => $u['role'],
            'created_at' => $u['created_at'],
        ];
    }, $data['users']);
    jsonResponse(true, '', $users);
}

if ($action === 'create_user' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $role = $_POST['role'] ?? 'user';

    if ($username === '' || strlen($password) < 6) {
        jsonResponse(false, '用户名不能为空，密码至少6位');
    }
    if (!in_array($role, ['admin', 'user'])) {
        jsonResponse(false, '角色无效');
    }

    $data = json_load();
    if (json_find_user($data, $username)) {
        jsonResponse(false, '用户名已存在');
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    json_update(function (&$data) use ($username, $hash, $role) {
        $data['users'][] = [
            'id'         => $data['next_user_id']++,
            'username'   => $username,
            'password'   => $hash,
            'role'       => $role,
            'created_at' => date('Y-m-d H:i:s'),
        ];
    });
    jsonResponse(true, '用户创建成功');
}

if ($action === 'update_user' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $userId = intval($_POST['user_id'] ?? 0);
    $role = $_POST['role'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';

    if ($userId <= 0) {
        jsonResponse(false, '用户ID无效');
    }
    if (!in_array($role, ['admin', 'user'])) {
        jsonResponse(false, '角色无效');
    }
    if ($newPassword !== '' && strlen($newPassword) < 6) {
        jsonResponse(false, '新密码至少6位');
    }

    $newHash = $newPassword !== '' ? password_hash($newPassword, PASSWORD_BCRYPT) : null;
    json_update(function (&$data) use ($userId, $role, $newHash) {
        foreach ($data['users'] as &$u) {
            if ($u['id'] == $userId) {
                $u['role'] = $role;
                if ($newHash) {
                    $u['password'] = $newHash;
                }
                break;
            }
        }
        unset($u);
    });
    jsonResponse(true, '用户更新成功');
}

if ($action === 'delete_user' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $userId = intval($_POST['user_id'] ?? 0);
    if ($userId <= 0) {
        jsonResponse(false, '用户ID无效');
    }
    // 不能删除自己
    if ($userId == $_SESSION['user_id']) {
        jsonResponse(false, '不能删除自己的账号');
    }

    json_update(function (&$data) use ($userId) {
        $data['users'] = array_values(array_filter($data['users'], function ($u) use ($userId) {
            return $u['id'] != $userId;
        }));
    });
    jsonResponse(true, '用户已删除');
}

// ==================== 配置接口 ====================

if ($action === 'get_settings') {
    $data = json_load();
    jsonResponse(true, '', $data['settings']);
}

if ($action === 'save_settings' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $settings = $_POST['settings'] ?? null;

    // FormData 传过来的是 JSON 字符串，需解码
    if (is_string($settings)) {
        $settings = json_decode($settings, true);
    }

    if (!$settings || !is_array($settings)) {
        jsonResponse(false, '无效的配置数据');
    }

    json_update(function (&$data) use ($settings) {
        foreach ($settings as $key => $value) {
            // 防止注入非法key
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $key)) {
                continue;
            }
            // holidays/workdays 前端可能传 JSON 字符串，这里统一转为数组
            if (in_array($key, ['holidays', 'workdays']) && is_string($value)) {
                $decoded = json_decode($value, true);
                $value = is_array($decoded) ? $decoded : [];
            }
            $data['settings'][$key] = $value;
        }
    });
    jsonResponse(true, '配置保存成功');
}

if ($action === 'get_mode') {
    $data = json_load();
    jsonResponse(true, '', ['mode' => $data['settings']['mode'] ?? 'online']);
}

// ==================== 公告接口 ====================

if ($action === 'get_announcement') {
    $data = json_load();
    $now = date('Y-m-d H:i:s');
    $latest = null;
    foreach ($data['announcements'] as $a) {
        if ($a['is_active'] != 1) {
            continue;
        }
        if (!empty($a['show_from']) && $a['show_from'] > $now) {
            continue;
        }
        if (!empty($a['show_until']) && $a['show_until'] < $now) {
            continue;
        }
        // 取最新创建的有效公告
        if (!$latest || strtotime($a['created_at']) >= strtotime($latest['created_at'])) {
            $latest = $a;
        }
    }
    jsonResponse(true, '', $latest);
}

if ($action === 'get_announcements') {
    requireAdmin();
    $data = json_load();
    $list = $data['announcements'];
    usort($list, function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });
    jsonResponse(true, '', $list);
}

if ($action === 'save_announcement' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $id = $_POST['id'] ?? null;
    $content = $_POST['content'] ?? '';
    $showFrom = $_POST['show_from'] ?: null;
    $showUntil = $_POST['show_until'] ?: null;
    $isActive = intval($_POST['is_active'] ?? 1);

    json_update(function (&$data) use ($id, $content, $showFrom, $showUntil, $isActive) {
        if ($id) {
            foreach ($data['announcements'] as &$a) {
                if ($a['id'] == $id) {
                    $a['content'] = $content;
                    $a['show_from'] = $showFrom;
                    $a['show_until'] = $showUntil;
                    $a['is_active'] = $isActive;
                    break;
                }
            }
            unset($a);
        } else {
            $data['announcements'][] = [
                'id'         => $data['next_ann_id']++,
                'content'    => $content,
                'show_from'  => $showFrom,
                'show_until' => $showUntil,
                'is_active'  => $isActive,
                'created_at' => date('Y-m-d H:i:s'),
            ];
        }
    });
    jsonResponse(true, '公告保存成功');
}

if ($action === 'delete_announcement' && $method === 'POST') {
    requireAdmin();
    requireCsrf();
    $id = intval($_POST['id'] ?? 0);

    json_update(function (&$data) use ($id) {
        $data['announcements'] = array_values(array_filter($data['announcements'], function ($a) use ($id) {
            return $a['id'] != $id;
        }));
    });
    jsonResponse(true, '公告已删除');
}

// ==================== 未匹配的 action ====================

jsonResponse(false, '未知操作：' . $action);
