<?php
/**
 * 管理面板（JSON 存储版）
 * 需要登录才能访问
 */

require_once 'storage.php';

session_start();

// 生成 CSRF token（写操作防护）
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrfToken = $_SESSION['csrf_token'];

// 如果已登录，直接显示面板；否则显示登录页
$loggedIn = !empty($_SESSION['user_id']);
$isAdmin = ($_SESSION['role'] ?? '') === 'admin';

// 处理登录表单提交
$loginError = '';
if (!$loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    // CSRF 校验
    $submittedToken = $_POST['csrf_token'] ?? '';
    if (!hash_equals($csrfToken, $submittedToken)) {
        $loginError = '安全校验失败，请刷新页面重试';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        if ($username && $password) {
            $data = json_load();
            $user = json_find_user($data, $username);
            if ($user && password_verify($password, $user['password'])) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['username'] = $user['username'];
                $_SESSION['role'] = $user['role'];
                $loggedIn = true;
                $isAdmin = ($user['role'] === 'admin');
                // 刷新页面避免 resubmit
                header('Location: admin.php');
                exit;
            } else {
                $loginError = '用户名或密码错误';
            }
        } else {
            $loginError = '请输入用户名和密码';
        }
    }
}

// 未登录：显示登录页
if (!$loggedIn):
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="icons/icon-96.png" type="image/png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <title>管理面板 - 登录</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }
        .login-card {
            background: #fff;
            border-radius: 16px;
            padding: 40px;
            width: 380px;
            max-width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.15);
        }
        .login-card h1 { text-align: center; color: #333; margin-bottom: 8px; font-size: 1.5rem; }
        .login-card .subtitle { text-align: center; color: #999; margin-bottom: 28px; font-size: 0.9rem; }
        .form-group { margin-bottom: 18px; }
        .form-group label { display: block; color: #555; margin-bottom: 6px; font-size: 0.9rem; }
        .form-group input {
            width: 100%; padding: 12px 14px;
            border: 1px solid #ddd; border-radius: 8px;
            font-size: 0.95rem; transition: border-color 0.2s;
            outline: none;
        }
        .form-group input:focus { border-color: #5c6bc0; box-shadow: 0 0 0 3px rgba(92,107,192,0.1); }
        .btn-login {
            width: 100%; padding: 12px;
            background: #5c6bc0; color: #fff; border: none;
            border-radius: 8px; font-size: 1rem; cursor: pointer;
            transition: background 0.2s; font-weight: 500;
        }
        .btn-login:hover { background: #4a55b0; }
        .error { background: #ffebee; color: #c62828; padding: 10px; border-radius: 8px; margin-bottom: 16px; font-size: 0.9rem; text-align: center; }
        .back-link { text-align: center; margin-top: 16px; }
        .back-link a { color: #5c6bc0; text-decoration: none; font-size: 0.9rem; }

        /* 移动端：输入框字号≥16px，避免 iOS 聚焦自动放大页面 */
        @media (max-width: 768px) {
            .form-group input { font-size: 16px; }
        }
    </style>
</head>
<body>
<div class="login-card">
    <h1><i class="fa-solid fa-lock"></i> 管理面板</h1>
    <p class="subtitle">学期倒计时系统</p>
    <?php if ($loginError): ?>
        <div class="error"><?php echo htmlspecialchars($loginError); ?></div>
    <?php endif; ?>
    <form method="post">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken); ?>">
        <div class="form-group">
            <label>用户名</label>
            <input type="text" name="username" placeholder="请输入用户名" required autofocus>
        </div>
        <div class="form-group">
            <label>密码</label>
            <input type="password" name="password" placeholder="请输入密码" required>
        </div>
        <button type="submit" name="login" class="btn-login">登 录</button>
    </form>
    <div class="back-link"><a href="index.php"><i class="fa-solid fa-arrow-left"></i> 返回首页</a></div>
</div>
</body>
</html>
<?php
exit;
endif; // 登录页结束
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="icon" href="icons/icon-96.png" type="image/png">
    <link rel="manifest" href="manifest.json">
    <link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <meta name="theme-color" content="#1e293b">
    <title>管理面板 - 学期倒计时</title>
    <style>
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: #f0f2f5;
            display: flex;
            min-height: 100vh;
            color: #333;
        }

        /* 侧边栏 */
        .sidebar {
            width: 220px;
            background: #1e293b;
            color: #cbd5e1;
            display: flex;
            flex-direction: column;
            position: fixed;
            top: 0; left: 0; bottom: 0;
            z-index: 100;
            overflow-y: auto;
        }
        .sidebar-logo {
            padding: 24px 20px 20px;
            border-bottom: 1px solid #334155;
        }
        .sidebar-logo h2 { color: #fff; font-size: 1.1rem; font-weight: 600; }
        .sidebar-logo .ver { color: #64748b; font-size: 0.75rem; margin-top: 4px; }
        .sidebar-toggle {
            display: none;
            align-items: center;
            justify-content: center;
            padding: 12px;
            cursor: pointer;
            color: #94a3b8;
            font-size: 1.2rem;
            border-bottom: 1px solid #334155;
        }
        .sidebar-toggle:hover { color: #e2e8f0; }
        .sidebar-nav { flex: 1; padding: 12px 0; }
        .sidebar-nav .nav-section { color: #64748b; font-size: 0.7rem; text-transform: uppercase; letter-spacing: 1px; padding: 12px 20px 6px; }
        .sidebar-nav a {
            display: flex; align-items: center; gap: 10px;
            padding: 10px 20px; color: #cbd5e1; text-decoration: none;
            font-size: 0.9rem; transition: all 0.15s; border-left: 3px solid transparent;
            cursor: pointer;
        }
        .sidebar-nav a:hover { background: #334155; color: #e2e8f0; }
        .sidebar-nav a.active { background: #334155; color: #fff; border-left-color: #818cf8; }
        .sidebar-nav a .icon { font-size: 1.1rem; width: 22px; text-align: center; }
        .sidebar-footer { padding: 16px 20px; border-top: 1px solid #334155; font-size: 0.8rem; color: #64748b; }
        .sidebar-footer .user-info { margin-bottom: 8px; }
        .sidebar-footer .user-info strong { color: #e2e8f0; display: block; }
        .sidebar-footer .role-badge {
            display: inline-block; padding: 2px 8px; border-radius: 10px;
            font-size: 0.7rem; margin-top: 4px;
        }
        .role-badge.admin { background: #fef3c7; color: #92400e; }
        .role-badge.user { background: #dbeafe; color: #1e40af; }

        /* 主区域 */
        .main {
            margin-left: 220px;
            flex: 1;
            padding: 28px 32px;
            min-height: 100vh;
        }
        .main-header { margin-bottom: 24px; }
        .main-header h1 { font-size: 1.4rem; font-weight: 600; color: #1e293b; margin-bottom: 4px; }
        .main-header p { color: #94a3b8; font-size: 0.85rem; }

        /* 卡片 */
        .card {
            background: #fff;
            border-radius: 12px;
            padding: 24px;
            margin-bottom: 20px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.06);
        }
        .card-title { font-size: 1.05rem; font-weight: 600; color: #1e293b; margin-bottom: 18px; padding-bottom: 12px; border-bottom: 1px solid #f1f5f9; }

        /* 表单 */
        .form-row { display: flex; gap: 12px; margin-bottom: 14px; align-items: flex-end; flex-wrap: wrap; }
        .form-group { flex: 1; min-width: 180px; }
        .form-group label { display: block; color: #64748b; margin-bottom: 5px; font-size: 0.82rem; font-weight: 500; }
        .form-group input, .form-group select, .form-group textarea {
            width: 100%; padding: 9px 12px;
            border: 1px solid #e2e8f0; border-radius: 8px;
            font-size: 0.9rem; outline: none; transition: border-color 0.2s;
            background: #fafbfc;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: #818cf8; background: #fff; box-shadow: 0 0 0 3px rgba(129,140,248,0.1);
        }
        .form-group textarea { resize: vertical; min-height: 80px; }
        .form-group .hint { color: #94a3b8; font-size: 0.75rem; margin-top: 4px; }
        .form-group input[type="color"] {
            width: 48px; height: 38px; padding: 2px; cursor: pointer;
        }
        .color-picker-row { display: flex; align-items: center; gap: 10px; }
        .color-picker-row input[type="text"] { flex: 1; }

        /* 按钮 */
        .btn {
            padding: 9px 18px; border: none; border-radius: 8px;
            font-size: 0.9rem; cursor: pointer; font-weight: 500;
            transition: all 0.15s; display: inline-flex; align-items: center; gap: 6px;
        }
        .btn-primary { background: #5c6bc0; color: #fff; }
        .btn-primary:hover { background: #4a55b0; }
        .btn-success { background: #10b981; color: #fff; }
        .btn-success:hover { background: #059669; }
        .btn-danger { background: #ef4444; color: #fff; }
        .btn-danger:hover { background: #dc2626; }
        .btn-outline { background: #fff; color: #64748b; border: 1px solid #e2e8f0; }
        .btn-outline:hover { background: #f8fafc; }
        .btn-sm { padding: 5px 12px; font-size: 0.8rem; }

        /* 表格 */
        .table-wrap { overflow-x: auto; }
        table { width: 100%; border-collapse: collapse; font-size: 0.88rem; }
        th { background: #f8fafc; color: #64748b; font-weight: 600; padding: 10px 14px; text-align: left; border-bottom: 2px solid #e2e8f0; }
        td { padding: 10px 14px; border-bottom: 1px solid #f1f5f9; }
        tr:hover td { background: #fafbfc; }

        /* Toast */
        .toast {
            position: fixed; top: 20px; right: 20px; z-index: 9999;
            padding: 12px 20px; border-radius: 10px; color: #fff; font-size: 0.9rem;
            max-width: 360px; box-shadow: 0 8px 24px rgba(0,0,0,0.15);
            animation: slideIn 0.25s ease;
            display: none;
        }
        .toast.success { background: #10b981; }
        .toast.error { background: #ef4444; }
        @keyframes slideIn { from { opacity: 0; transform: translateX(40px); } to { opacity: 1; transform: translateX(0); } }

        /* Tabs 内容区 */
        .tab-content { display: none; }
        .tab-content.active { display: block; }

        /* 预览块 */
        .preview-box {
            border: 1px solid #e2e8f0; border-radius: 12px; padding: 20px;
            background: #fafbfc; margin-top: 16px;
        }
        .preview-bar {
            height: 20px; border-radius: 10px;
            background: linear-gradient(90deg, var(--preview-start, #7ed957), var(--preview-end, #2e8b57));
            width: 65%;
        }

        /* 特殊日期标签 */
        .tag-list { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
        .tag {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 4px 10px; background: #f1f5f9; border-radius: 6px;
            font-size: 0.82rem; color: #475569;
        }
        .tag .remove-tag { cursor: pointer; color: #94a3b8; font-weight: bold; }
        .tag .remove-tag:hover { color: #ef4444; }

        /* 响应式 */
        @media (max-width: 768px) {
            .sidebar { width: 60px; transition: width 0.25s ease; }
            .sidebar.expanded { width: 220px; }
            .sidebar-logo h2, .sidebar-logo .ver, .sidebar-nav a span:not(.icon), .sidebar-nav .nav-section, .sidebar-footer { display: none; }
            .sidebar.expanded .sidebar-logo h2,
            .sidebar.expanded .sidebar-logo .ver,
            .sidebar.expanded .sidebar-nav a span:not(.icon),
            .sidebar.expanded .sidebar-nav .nav-section,
            .sidebar.expanded .sidebar-footer { display: block; }
            .sidebar-nav a { justify-content: center; padding: 12px; }
            .sidebar.expanded .sidebar-nav a { justify-content: flex-start; padding: 10px 20px; }
            .sidebar-nav a .icon { margin: 0; font-size: 1.3rem; }
            .sidebar.expanded .sidebar-nav a .icon { margin: 0; font-size: 1.1rem; }
            .main { margin-left: 60px; padding: 20px; transition: margin-left 0.25s ease; }
            .sidebar.expanded + .main { margin-left: 220px; }
            /* 桌面切换按钮在移动端显示 */
            .sidebar-toggle { display: flex; }
            /* 输入框字号≥16px，避免 iOS 聚焦自动放大页面 */
            .form-group input, .form-group select, .form-group textarea { font-size: 16px; }
        }
        @media (min-width: 769px) {
            .sidebar-toggle { display: none; }
        }
    </style>
</head>
<body>

<!-- 侧边栏 -->
<div class="sidebar">
    <div class="sidebar-logo">
        <h2><i class="fa-solid fa-chart-simple"></i> 管理面板</h2>
        <div class="ver">学期倒计时 v2.0</div>
    </div>
    <div class="sidebar-toggle" onclick="toggleSidebar()"><i class="fa-solid fa-bars"></i></div>
    <div class="sidebar-nav">
        <?php if ($isAdmin): ?>
        <div class="nav-section">系统管理</div>
        <a class="active" data-tab="tab-users"><span class="icon"><i class="fa-solid fa-users"></i></span> <span>用户管理</span></a>
        <a data-tab="tab-semester"><span class="icon"><i class="fa-solid fa-calendar-days"></i></span> <span>学期配置</span></a>
        <a data-tab="tab-appearance"><span class="icon"><i class="fa-solid fa-palette"></i></span> <span>界面配色</span></a>
        <a data-tab="tab-background"><span class="icon"><i class="fa-solid fa-image"></i></span> <span>背景设置</span></a>
        <a data-tab="tab-announcement"><span class="icon"><i class="fa-solid fa-bullhorn"></i></span> <span>公告管理</span></a>
        <?php endif; ?>
        <a data-tab="tab-password"><span class="icon"><i class="fa-solid fa-key"></i></span> <span>修改密码</span></a>
    </div>
    <div class="sidebar-footer">
        <div class="user-info">
            <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
            <span class="role-badge <?php echo $isAdmin ? 'admin' : 'user'; ?>"><?php echo $isAdmin ? '管理员' : '普通用户'; ?></span>
        </div>
        <a href="api.php?action=logout" style="color:#94a3b8;text-decoration:none;">退出登录</a>
        &nbsp;|&nbsp;
        <a href="index.php" style="color:#94a3b8;text-decoration:none;">首页</a>
    </div>
</div>

<!-- 主区域 -->
<div class="main">
    <div class="main-header">
        <h1 id="tab-title"><?php echo $isAdmin ? '用户管理' : '修改密码'; ?></h1>
        <p id="tab-desc"><?php echo $isAdmin ? '管理系统用户账号和权限' : '修改当前账号的登录密码'; ?></p>
    </div>

    <div id="toast" class="toast"></div>

    <!-- ==================== 用户管理 Tab ==================== -->
    <?php if ($isAdmin): ?>
    <div class="tab-content active" id="tab-users">
        <div class="card">
            <div class="card-title">新建用户</div>
            <div class="form-row">
                <div class="form-group"><label>用户名</label><input type="text" id="new-username" placeholder="用户名"></div>
                <div class="form-group"><label>密码</label><input type="password" id="new-password" placeholder="至少6位"></div>
                <div class="form-group"><label>角色</label>
                    <select id="new-role"><option value="user">普通用户</option><option value="admin">管理员</option></select>
                </div>
                <div class="form-group" style="flex:0 0 auto;"><label>&nbsp;</label><button class="btn btn-primary" onclick="createUser()">创建用户</button></div>
            </div>
        </div>
        <div class="card">
            <div class="card-title">用户列表</div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>用户名</th><th>角色</th><th>创建时间</th><th>操作</th></tr></thead>
                    <tbody id="user-table-body"><tr><td colspan="5" style="text-align:center;color:#94a3b8;">加载中...</td></tr></tbody>
                </table>
            </div>
        </div>
        <!-- 编辑用户弹窗 -->
        <div class="card" id="edit-user-panel" style="display:none;">
            <div class="card-title">编辑用户</div>
            <div class="form-row">
                <input type="hidden" id="edit-user-id">
                <div class="form-group"><label>用户名</label><input type="text" id="edit-username" disabled></div>
                <div class="form-group"><label>新密码（留空不修改）</label><input type="password" id="edit-password" placeholder="留空则不修改"></div>
                <div class="form-group"><label>角色</label><select id="edit-role"><option value="user">普通用户</option><option value="admin">管理员</option></select></div>
                <div class="form-group" style="flex:0 0 auto;"><label>&nbsp;</label>
                    <button class="btn btn-primary" onclick="updateUser()">保存</button>
                    <button class="btn btn-outline" onclick="document.getElementById('edit-user-panel').style.display='none'">取消</button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <!-- ==================== 学期配置 Tab ==================== -->
    <div class="tab-content" id="tab-semester">
        <div class="card">
            <div class="card-title">学期时间</div>
            <div class="form-row">
                <div class="form-group"><label>学期开始</label><input type="datetime-local" id="sem-start"></div>
                <div class="form-group"><label>学期结束</label><input type="datetime-local" id="sem-end"></div>
            </div>
        </div>
        <div class="card">
            <div class="card-title">特殊假期</div>
            <div class="form-row">
                <div class="form-group"><label>添加日期</label><input type="date" id="holiday-input"></div>
                <div class="form-group" style="flex:0 0 auto;"><label>&nbsp;</label><button class="btn btn-outline" onclick="addTag('holiday')">添加</button></div>
            </div>
            <div class="tag-list" id="holiday-tags"></div>
        </div>
        <div class="card">
            <div class="card-title">特殊工作日（补班）</div>
            <div class="form-row">
                <div class="form-group"><label>添加日期</label><input type="date" id="workday-input"></div>
                <div class="form-group" style="flex:0 0 auto;"><label>&nbsp;</label><button class="btn btn-outline" onclick="addTag('workday')">添加</button></div>
            </div>
            <div class="tag-list" id="workday-tags"></div>
        </div>
        <div style="margin-top:12px;">
            <button class="btn btn-success" onclick="saveSemester()"><i class="fa-solid fa-floppy-disk"></i> 保存学期配置</button>
        </div>
    </div>

    <!-- ==================== 界面配色 Tab ==================== -->
    <div class="tab-content" id="tab-appearance">
        <div class="card">
            <div class="card-title">页面标题</div>
            <div class="form-group"><input type="text" id="cfg-title" placeholder="页面标题"></div>
        </div>
        <div class="card">
            <div class="card-title">主题配色</div>
            <?php
            $colorFields = [
                'primary_color' => '主题色',
                'font_color' => '字体颜色',
                'page_bg' => '页面背景',
                'card_bg' => '卡片背景',
                'progress_bar_start' => '进度条起始色',
                'progress_bar_end' => '进度条结束色',
                'progress_percent_color' => '百分比颜色',
            ];
            foreach ($colorFields as $key => $label):
            ?>
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label><?php echo $label; ?></label>
                    <div class="color-picker-row">
                        <input type="color" id="cfg-<?php echo $key; ?>" value="#333333">
                        <input type="text" id="cfg-<?php echo $key; ?>-hex" placeholder="#000000" maxlength="7">
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="card">
            <div class="card-title">进度条预览</div>
            <div class="preview-box">
                <div class="preview-bar" id="preview-bar"></div>
                <p style="margin-top:12px;font-size:2rem;font-weight:650;color:var(--preview-percent);" id="preview-percent">65.432100%</p>
            </div>
        </div>
        <button class="btn btn-success" onclick="saveAppearance()"><i class="fa-solid fa-floppy-disk"></i> 保存界面配置</button>
    </div>

    <!-- ==================== 背景设置 Tab ==================== -->
    <div class="tab-content" id="tab-background">
        <div class="card">
            <div class="card-title">背景类型</div>
            <div class="form-row">
                <div class="form-group">
                    <label>背景模式</label>
                    <select id="bg-type" onchange="toggleBgMode()">
                        <option value="solid">纯色背景</option>
                        <option value="image">图片背景</option>
                    </select>
                </div>
            </div>
        </div>
        <div class="card" id="bg-solid-panel">
            <div class="card-title">纯色背景</div>
            <div class="form-row">
                <div class="form-group">
                    <label>背景颜色</label>
                    <div class="color-picker-row">
                        <input type="color" id="bg-color" value="#f7f9fc">
                        <input type="text" id="bg-color-hex" placeholder="#f7f9fc" maxlength="7">
                    </div>
                </div>
            </div>
        </div>
        <div class="card" id="bg-image-panel" style="display:none;">
            <div class="card-title">图片背景</div>
            <div class="form-group"><label>图片 URL</label><input type="text" id="bg-image-url" placeholder="https://example.com/bg.jpg"></div>
            <div class="form-row">
                <div class="form-group">
                    <label>图片透明度</label>
                    <input type="range" id="bg-image-opacity" min="0.1" max="1" step="0.05" value="0.3" oninput="document.getElementById('bg-image-opacity-val').textContent=(this.value*100).toFixed(0)+'%'">
                    <span id="bg-image-opacity-val" style="font-size:0.85rem;color:#64748b;">30%</span>
                </div>
                <div class="form-group">
                    <label>毛玻璃透明度</label>
                    <input type="range" id="glass-opacity" min="0.1" max="1" step="0.05" value="0.7" oninput="document.getElementById('glass-opacity-val').textContent=(this.value*100).toFixed(0)+'%'">
                    <span id="glass-opacity-val" style="font-size:0.85rem;color:#64748b;">70%</span>
                </div>
            </div>
            <p class="hint">毛玻璃透明度越高，卡片越不透明。图片透明度越高，背景图越清晰。</p>
        </div>
        <button class="btn btn-success" onclick="saveBackground()"><i class="fa-solid fa-floppy-disk"></i> 保存背景配置</button>
    </div>

    <!-- ==================== 公告管理 Tab ==================== -->
    <div class="tab-content" id="tab-announcement">
        <div class="card">
            <div class="card-title">编辑公告</div>
            <div class="form-group"><label>公告内容（支持 Markdown 语法）</label><textarea id="ann-content" rows="5" placeholder="输入公告内容..."></textarea></div>
            <div class="form-row">
                <div class="form-group"><label>开始展示</label><input type="datetime-local" id="ann-show-from"></div>
                <div class="form-group"><label>结束展示</label><input type="datetime-local" id="ann-show-until"></div>
                <div class="form-group">
                    <label>状态</label>
                    <select id="ann-active"><option value="1">启用</option><option value="0">停用</option></select>
                </div>
            </div>
            <p class="hint">留空时间表示不限。公告以顶部消息条展示，支持 **粗体**、*斜体*、`代码`、[链接](url) 等 Markdown 语法。</p>
            <div style="margin-top:12px;">
                <button class="btn btn-primary" onclick="saveAnnouncement()"><i class="fa-solid fa-floppy-disk"></i> 保存公告</button>
            </div>
        </div>
        <div class="card">
            <div class="card-title">公告自动关闭</div>
            <div class="form-row">
                <div class="form-group">
                    <label>自动关闭时间（秒）</label>
                    <input type="number" id="ann-auto-close" min="1" max="60" value="5">
                    <p class="hint">公告展示后自动关闭的秒数，默认 5 秒。设为 0 表示不自动关闭。</p>
                </div>
            </div>
            <button class="btn btn-primary" onclick="saveAnnouncementAutoClose()"><i class="fa-solid fa-floppy-disk"></i> 保存设置</button>
        </div>
        <div class="card">
            <div class="card-title">公告列表</div>
            <div class="table-wrap">
                <table>
                    <thead><tr><th>ID</th><th>内容</th><th>开始</th><th>结束</th><th>状态</th><th>创建时间</th><th>操作</th></tr></thead>
                    <tbody id="ann-table-body"><tr><td colspan="7" style="text-align:center;color:#94a3b8;">加载中...</td></tr></tbody>
                </table>
            </div>
        </div>
    </div>

    <?php endif; ?>

    <!-- ==================== 修改密码 Tab ==================== -->
    <div class="tab-content" id="tab-password">
        <div class="card">
            <div class="card-title">修改密码</div>
            <div class="form-group"><label>原密码</label><input type="password" id="pw-old"></div>
            <div class="form-group"><label>新密码</label><input type="password" id="pw-new" placeholder="至少6位"></div>
            <button class="btn btn-primary" onclick="changePassword()">修改密码</button>
        </div>
    </div>

</div>

<script>
// ==================== 全局配置 ====================
window._csrfToken = <?php echo json_encode($csrfToken); ?>;

// ==================== 工具函数 ====================
function showToast(msg, type) {
    const t = document.getElementById('toast');
    t.textContent = msg;
    t.className = 'toast ' + (type || 'success');
    t.style.display = 'block';
    setTimeout(() => { t.style.display = 'none'; }, 3000);
}

async function api(action, data) {
    const formData = new FormData();
    formData.append('action', action);
    formData.append('csrf_token', window._csrfToken || '');
    if (data) {
        Object.entries(data).forEach(([k, v]) => formData.append(k, v));
    }
    const resp = await fetch('api.php', { method: 'POST', body: formData });
    return resp.json();
}

function formatDateTime(phpStr) {
    if (!phpStr) return '-';
    return phpStr.replace(' ', 'T').substring(0, 16);
}

// ==================== Tab 切换 ====================
const tabTitles = {
    'tab-users': ['用户管理', '管理系统用户账号和权限'],
    'tab-semester': ['学期配置', '设置学期起止时间、假期和补班日'],
    'tab-appearance': ['界面配色', '自定义页面主题颜色和进度条样式'],
    'tab-background': ['背景设置', '配置页面背景（纯色或图片）'],
    'tab-announcement': ['公告管理', '编辑和管理首页公告弹窗'],
    'tab-password': ['修改密码', '修改当前账号的登录密码'],
};

document.querySelectorAll('.sidebar-nav a').forEach(link => {
    link.addEventListener('click', function(e) {
        e.preventDefault();
        const tabId = this.dataset.tab;
        // 更新导航 active
        document.querySelectorAll('.sidebar-nav a').forEach(a => a.classList.remove('active'));
        this.classList.add('active');
        // 更新内容区
        document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
        document.getElementById(tabId).classList.add('active');
        // 更新标题
        const [title, desc] = tabTitles[tabId] || [tabId, ''];
        document.getElementById('tab-title').textContent = title;
        document.getElementById('tab-desc').textContent = desc;
        // 触发各 tab 的数据加载
        if (tabId === 'tab-users') loadUsers();
        if (tabId === 'tab-semester') loadSemester();
        if (tabId === 'tab-appearance') loadAppearance();
        if (tabId === 'tab-background') loadBackground();
        if (tabId === 'tab-announcement') loadAnnouncements();
    });
});

// 默认激活第一个可见 tab
document.addEventListener('DOMContentLoaded', () => {
    const firstTab = document.querySelector('.sidebar-nav a.active') || document.querySelector('.sidebar-nav a');
    if (firstTab) firstTab.click();
});

// ==================== 用户管理 ====================
async function loadUsers() {
    <?php if (!$isAdmin): echo 'return;'; endif; ?>
    try {
        const json = await api('get_users');
        const tbody = document.getElementById('user-table-body');
        if (json.success) {
            tbody.innerHTML = json.data.map(u => `
                <tr>
                    <td>${u.id}</td>
                    <td>${escapeHtml(u.username)}</td>
                    <td><span class="role-badge ${u.role}">${u.role === 'admin' ? '管理员' : '普通用户'}</span></td>
                    <td>${u.created_at || '-'}</td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick="editUser(${u.id}, '${escapeHtml(u.username)}', '${u.role}')">编辑</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteUser(${u.id}, '${escapeHtml(u.username)}')">删除</button>
                    </td>
                </tr>
            `).join('');
        }
    } catch (e) { showToast('加载用户失败', 'error'); }
}

function editUser(id, username, role) {
    document.getElementById('edit-user-id').value = id;
    document.getElementById('edit-username').value = username;
    document.getElementById('edit-role').value = role;
    document.getElementById('edit-password').value = '';
    document.getElementById('edit-user-panel').style.display = 'block';
}

async function createUser() {
    const username = document.getElementById('new-username').value.trim();
    const password = document.getElementById('new-password').value;
    const role = document.getElementById('new-role').value;
    if (!username || password.length < 6) return showToast('用户名不能为空，密码至少6位', 'error');
    const json = await api('create_user', { username, password, role });
    showToast(json.message, json.success ? 'success' : 'error');
    if (json.success) { document.getElementById('new-username').value = ''; document.getElementById('new-password').value = ''; loadUsers(); }
}

async function updateUser() {
    const id = document.getElementById('edit-user-id').value;
    const role = document.getElementById('edit-role').value;
    const pw = document.getElementById('edit-password').value;
    const data = { user_id: id, role };
    if (pw) data.new_password = pw;
    const json = await api('update_user', data);
    showToast(json.message, json.success ? 'success' : 'error');
    if (json.success) { document.getElementById('edit-user-panel').style.display = 'none'; loadUsers(); }
}

async function deleteUser(id, username) {
    if (!confirm(`确定删除用户 "${username}" 吗？此操作不可撤销。`)) return;
    const json = await api('delete_user', { user_id: id });
    showToast(json.message, json.success ? 'success' : 'error');
    if (json.success) loadUsers();
}

// ==================== 学期配置 ====================
let semesterHolidays = [];
let semesterWorkdays = [];

async function loadSemester() {
    try {
        const json = await api('get_settings');
        if (json.success) {
            const s = json.data;
            document.getElementById('sem-start').value = (s.start_date || '').replace(' ', 'T').substring(0, 16);
            document.getElementById('sem-end').value = (s.end_date || '').replace(' ', 'T').substring(0, 16);
            semesterHolidays = s.holidays ? (typeof s.holidays === 'string' ? JSON.parse(s.holidays) : s.holidays) : [];
            semesterWorkdays = s.workdays ? (typeof s.workdays === 'string' ? JSON.parse(s.workdays) : s.workdays) : [];
            renderTags('holiday-tags', semesterHolidays, 'holiday');
            renderTags('workday-tags', semesterWorkdays, 'workday');
        }
    } catch (e) { showToast('加载配置失败', 'error'); }
}

function addTag(type) {
    const inputId = type === 'holiday' ? 'holiday-input' : 'workday-input';
    const input = document.getElementById(inputId);
    const date = input.value;
    if (!date) return showToast('请选择日期', 'error');
    const list = type === 'holiday' ? semesterHolidays : semesterWorkdays;
    if (list.includes(date)) return showToast('日期已存在', 'error');
    list.push(date);
    renderTags(type === 'holiday' ? 'holiday-tags' : 'workday-tags', list, type);
    input.value = '';
}

function removeTag(type, index) {
    const list = type === 'holiday' ? semesterHolidays : semesterWorkdays;
    list.splice(index, 1);
    renderTags(type === 'holiday' ? 'holiday-tags' : 'workday-tags', list, type);
}

function renderTags(containerId, list, type) {
    document.getElementById(containerId).innerHTML = list.map((d, i) =>
        `<span class="tag">${d} <span class="remove-tag" onclick="removeTag('${type}', ${i})">×</span></span>`
    ).join('');
}

async function saveSemester() {
    const startVal = document.getElementById('sem-start').value;
    const endVal = document.getElementById('sem-end').value;
    if (!startVal || !endVal) {
        return showToast('请先填写学期开始和结束时间', 'error');
    }
    const startDate = startVal.replace('T', ' ') + ':00';
    const endDate = endVal.replace('T', ' ') + ':00';
    const json = await api('save_settings', {
        settings: JSON.stringify({
            start_date: startDate,
            end_date: endDate,
            holidays: JSON.stringify(semesterHolidays),
            workdays: JSON.stringify(semesterWorkdays),
        })
    });
    showToast(json.message, json.success ? 'success' : 'error');
}

// ==================== 界面配色 ====================
async function loadAppearance() {
    try {
        const json = await api('get_settings');
        if (json.success) {
            const s = json.data;
            setColorField('primary_color', s.primary_color || '#5c6bc0');
            setColorField('font_color', s.font_color || '#333333');
            setColorField('page_bg', s.page_bg || '#f7f9fc');
            setColorField('card_bg', s.card_bg || '#ffffff');
            setColorField('progress_bar_start', s.progress_bar_start || '#7ed957');
            setColorField('progress_bar_end', s.progress_bar_end || '#2e8b57');
            setColorField('progress_percent_color', s.progress_percent_color || '#5c6bc0');
            document.getElementById('cfg-title').value = s.title || '';
            updatePreview();
        }
    } catch (e) { showToast('加载配置失败', 'error'); }
}

function setColorField(key, value) {
    const colorInput = document.getElementById('cfg-' + key);
    const hexInput = document.getElementById('cfg-' + key + '-hex');
    if (colorInput) colorInput.value = value;
    if (hexInput) hexInput.value = value;
}

// 双向同步颜色选择器和文本框
document.addEventListener('input', function(e) {
    if (e.target.id && e.target.id.startsWith('cfg-')) {
        const id = e.target.id;
        if (id.endsWith('-hex')) {
            const baseId = id.replace('-hex', '');
            const colorInput = document.getElementById(baseId);
            if (colorInput && /^#[0-9a-fA-F]{6}$/.test(e.target.value)) {
                colorInput.value = e.target.value;
            }
        } else {
            const hexInput = document.getElementById(id + '-hex');
            if (hexInput) hexInput.value = e.target.value;
        }
        updatePreview();
    }
});

function updatePreview() {
    const start = document.getElementById('cfg-progress_bar_start')?.value || '#7ed957';
    const end = document.getElementById('cfg-progress_bar_end')?.value || '#2e8b57';
    const percentColor = document.getElementById('cfg-progress_percent_color')?.value || '#5c6bc0';
    document.getElementById('preview-bar').style.background = `linear-gradient(90deg, ${start}, ${end})`;
    document.getElementById('preview-percent').style.color = percentColor;
}

async function saveAppearance() {
    const settings = {
        title: document.getElementById('cfg-title').value,
        primary_color: document.getElementById('cfg-primary_color').value,
        font_color: document.getElementById('cfg-font_color').value,
        page_bg: document.getElementById('cfg-page_bg').value,
        card_bg: document.getElementById('cfg-card_bg').value,
        progress_bar_start: document.getElementById('cfg-progress_bar_start').value,
        progress_bar_end: document.getElementById('cfg-progress_bar_end').value,
        progress_percent_color: document.getElementById('cfg-progress_percent_color').value,
    };
    const json = await api('save_settings', { settings: JSON.stringify(settings) });
    showToast(json.message, json.success ? 'success' : 'error');
}

// ==================== 背景设置 ====================
async function loadBackground() {
    try {
        const json = await api('get_settings');
        if (json.success) {
            const s = json.data;
            document.getElementById('bg-type').value = s.bg_type || 'solid';
            document.getElementById('bg-color').value = s.bg_color || '#f7f9fc';
            document.getElementById('bg-color-hex').value = s.bg_color || '#f7f9fc';
            document.getElementById('bg-image-url').value = s.bg_image_url || '';
            document.getElementById('bg-image-opacity').value = s.bg_image_opacity || '0.3';
            document.getElementById('glass-opacity').value = s.glass_opacity || '0.7';
            document.getElementById('bg-image-opacity-val').textContent = ((parseFloat(s.bg_image_opacity) || 0.3) * 100).toFixed(0) + '%';
            document.getElementById('glass-opacity-val').textContent = ((parseFloat(s.glass_opacity) || 0.7) * 100).toFixed(0) + '%';
            toggleBgMode();
        }
    } catch (e) {}
}

function toggleBgMode() {
    const mode = document.getElementById('bg-type').value;
    document.getElementById('bg-solid-panel').style.display = mode === 'solid' ? 'block' : 'none';
    document.getElementById('bg-image-panel').style.display = mode === 'image' ? 'block' : 'none';
}

// 颜色双向同步
document.getElementById('bg-color')?.addEventListener('input', function() {
    document.getElementById('bg-color-hex').value = this.value;
});
document.getElementById('bg-color-hex')?.addEventListener('input', function() {
    if (/^#[0-9a-fA-F]{6}$/.test(this.value)) document.getElementById('bg-color').value = this.value;
});

async function saveBackground() {
    const settings = {
        bg_type: document.getElementById('bg-type').value,
        bg_color: document.getElementById('bg-color').value,
        bg_image_url: document.getElementById('bg-image-url').value,
        bg_image_opacity: document.getElementById('bg-image-opacity').value,
        glass_opacity: document.getElementById('glass-opacity').value,
    };
    const json = await api('save_settings', { settings: JSON.stringify(settings) });
    showToast(json.message, json.success ? 'success' : 'error');
}

// ==================== 公告管理 ====================
async function loadAnnouncements() {
    try {
        const json = await api('get_announcements');
        const tbody = document.getElementById('ann-table-body');
        if (json.success) {
            tbody.innerHTML = json.data.map(a => `
                <tr>
                    <td>${a.id}</td>
                    <td style="max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">${escapeHtml(a.content || '')}</td>
                    <td>${a.show_from || '-'}</td>
                    <td>${a.show_until || '-'}</td>
                    <td>${a.is_active == 1 ? '<i class="fa-solid fa-circle-check" style="color:#10b981;"></i> 启用' : '<i class="fa-solid fa-circle-pause" style="color:#94a3b8;"></i> 停用'}</td>
                    <td>${a.created_at || '-'}</td>
                    <td>
                        <button class="btn btn-outline btn-sm" onclick="editAnnouncement(${a.id})">编辑</button>
                        <button class="btn btn-danger btn-sm" onclick="deleteAnnouncement(${a.id})">删除</button>
                    </td>
                </tr>
            `).join('');
        }
        // 加载自动关闭时间
        const sJson = await api('get_settings');
        if (sJson.success && sJson.data) {
            document.getElementById('ann-auto-close').value = sJson.data.announcement_auto_close || '5';
        }
    } catch (e) {}
}

async function saveAnnouncement() {
    const fromVal = document.getElementById('ann-show-from').value;
    const untilVal = document.getElementById('ann-show-until').value;
    const data = {
        content: document.getElementById('ann-content').value,
        // 为空时传空字符串，后端会自动转为 null（表示不限时间）
        show_from: fromVal ? fromVal.replace('T', ' ') + ':00' : '',
        show_until: untilVal ? untilVal.replace('T', ' ') + ':00' : '',
        is_active: document.getElementById('ann-active').value,
    };
    // 检查是否有正在编辑的公告 ID
    if (window._editingAnnId) {
        data.id = window._editingAnnId;
        window._editingAnnId = null;
    }
    const json = await api('save_announcement', data);
    showToast(json.message, json.success ? 'success' : 'error');
    if (json.success) {
        document.getElementById('ann-content').value = '';
        document.getElementById('ann-show-from').value = '';
        document.getElementById('ann-show-until').value = '';
        loadAnnouncements();
    }
}

async function editAnnouncement(id) {
    try {
        const json = await api('get_announcements');
        if (json.success) {
            const ann = json.data.find(a => a.id == id);
            if (ann) {
                document.getElementById('ann-content').value = ann.content || '';
                document.getElementById('ann-show-from').value = formatDateTime(ann.show_from);
                document.getElementById('ann-show-until').value = formatDateTime(ann.show_until);
                document.getElementById('ann-active').value = ann.is_active;
            }
        }
    } catch (e) {}
}

async function deleteAnnouncement(id) {
    if (!confirm('确定删除此公告吗？')) return;
    const json = await api('delete_announcement', { id });
    showToast(json.message, json.success ? 'success' : 'error');
    if (json.success) loadAnnouncements();
}

async function saveAnnouncementAutoClose() {
    const seconds = parseInt(document.getElementById('ann-auto-close').value) || 5;
    const json = await api('save_settings', { settings: JSON.stringify({ announcement_auto_close: String(seconds) }) });
    showToast(json.message, json.success ? 'success' : 'error');
}

// ==================== 手机端侧边栏切换 ====================
function toggleSidebar() {
    document.querySelector('.sidebar').classList.toggle('expanded');
    // 点击其他地方关闭
}

document.addEventListener('click', function(e) {
    var sidebar = document.querySelector('.sidebar');
    if (window.innerWidth <= 768 && sidebar.classList.contains('expanded')) {
        if (!sidebar.contains(e.target)) {
            sidebar.classList.remove('expanded');
        }
    }
});

// ==================== 工具 ====================
function escapeHtml(str) {
    const div = document.createElement('div');
    div.textContent = str;
    return div.innerHTML;
}
</script>
</body>
</html>
