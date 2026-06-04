<?php
$config = include 'config.php';
include 'countdown.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <link rel="icon" href="icons/icon-96.png" type="image/png">
    <link rel="apple-touch-icon" href="icons/icon-192.png">
    <link rel="manifest" href="manifest.json">
    <meta name="theme-color" content="#5c6bc0">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <title><?php echo htmlspecialchars($initData['title']); ?></title>
    <style>
        :root {
            --primary-color: <?php echo $initData['primary_color']; ?>;
            --font-color: <?php echo $initData['font_color']; ?>;
            --card-bg: <?php echo $initData['card_bg']; ?>;
            --page-bg: <?php echo $initData['page_bg']; ?>;
            --progress-start: <?php echo $initData['progress_bar_start']; ?>;
            --progress-end: <?php echo $initData['progress_bar_end']; ?>;
            --percent-color: <?php echo $initData['progress_percent_color']; ?>;
            --bg-image: none;
            --bg-image-opacity: <?php echo $initData['bg_image_opacity']; ?>;
            --glass-opacity: <?php echo $initData['glass_opacity']; ?>;
            --shadow: 0 4px 20px rgba(0,0,0,0.05);
            --border-radius: 12px;
        }

        * { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--page-bg);
            color: var(--font-color);
            min-height: 100vh;
            padding: max(20px, env(safe-area-inset-top)) 20px 20px;
            text-align: center;
            transition: background-color 0.3s;
            position: relative;
        }

        /* 图片背景模式 */
        body.bg-image {
            background-color: var(--page-bg);
        }
        body.bg-image::before {
            content: '';
            position: fixed;
            top: 0; left: 0; right: 0; bottom: 0;
            background-image: var(--bg-image);
            background-size: cover;
            background-position: center;
            background-repeat: no-repeat;
            opacity: var(--bg-image-opacity);
            z-index: 0;
            pointer-events: none;
        }
        body.bg-image > .container {
            position: relative;
            z-index: 1;
        }

        .container { max-width: 1000px; margin: 0 auto; position: relative; }

        /* 头部 */
        header {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            transition: background 0.3s;
        }
        .date-info span { display: block; color: var(--font-color); font-size: 0.9rem; margin: 5px 0; }
        .current-time { font-size: 1.2rem; font-weight: bold; color: var(--primary-color); font-variant-numeric: tabular-nums; }

        /* 毛玻璃效果 - 图片背景时启用 */
        body.bg-image header,
        body.bg-image .progress-section,
        body.bg-image .countdown-card,
        body.bg-image .calendar-section,
        body.bg-image .month-card {
            background: rgba(255, 255, 255, var(--glass-opacity));
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
        }
        body.bg-image .month-card { border-color: transparent; }

        /* 进度条 */
        .progress-section {
            background: var(--card-bg);
            padding: 20px 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
            transition: background 0.3s;
        }
        .progress-title { font-size: 1.1rem; color: var(--font-color); margin-bottom: 5px; }
        .progress-container {
            background-color: #e8ecf1;
            border-radius: 20px;
            height: 35px;
            width: 100%;
            overflow: hidden;
            margin: 15px 0;
        }
        .progress-bar {
            background: linear-gradient(90deg, var(--progress-start), var(--progress-end));
            height: 100%;
            width: 0%;
            transition: width 0.1s linear;
            border-radius: 20px;
        }
        .percentage-display {
            display: flex;
            justify-content: center;
            align-items: baseline;
            margin-top: 10px;
            font-size: 2.5rem;
            font-weight: 650;
            line-height: 1;
            color: var(--percent-color);
            text-shadow: 0 4px 8px rgba(46, 125, 50, 0.2);
            font-variant-numeric: tabular-nums;
            letter-spacing: -2px;
        }
        .percentage-symbol {
            font-size: 1.5rem;
            font-weight: 600;
            color: var(--percent-color);
            margin-left: 8px;
            transform: translateY(-5px);
        }

        /* 倒计时 */
        .countdown-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) { .countdown-grid { grid-template-columns: 1fr; } }

        /* 手机端：管理按钮固定在头部卡片右上角 */
        @media (max-width: 768px) {
            header { position: relative; }
            .admin-link {
                position: absolute;
                top: 12px;
                right: 12px;
                margin-left: 0;
            }
        }
        .countdown-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            transition: background 0.3s;
        }
        .countdown-card h3 { margin-bottom: 10px; color: var(--font-color); font-weight: 500; font-size: 1rem; }
        .timer-display {
            font-size: 1.8rem;
            font-weight: bold;
            color: var(--font-color);
            display: flex;
            justify-content: center;
            gap: 6px;
            font-variant-numeric: tabular-nums;
        }
        .timer-unit span { font-size: 0.8rem; display: block; font-weight: normal; color: var(--font-color); }

        /* 日历 */
        .calendar-section {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            transition: background 0.3s;
        }
        .calendar-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .month-card { border: 1px solid #eee; border-radius: 8px; padding: 10px; background: #fff; }
        .month-title { font-weight: bold; margin-bottom: 10px; color: var(--primary-color); }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { padding: 5px; text-align: center; border-radius: 4px; }
        th.weekend { color: #ffab91; }
        .week-col { color: #aaa; font-size: 0.75rem; }
        .day-cell { border: 1px solid transparent; }
        .past { background-color: #e8f5e9; color: #2e7d32; }
        .rest { background-color: #e3f2fd; color: #1565c0; }
        .work { background-color: #ffffff; border: 1px solid #eee; }
        .today { border: 2px solid #ff9800 !important; font-weight: bold; }
        .out-of-range { opacity: 0.3; }

        /* 管理入口 - 头部卡片右上角 */
        .admin-link {
            display: inline-block;
            padding: 4px 12px;
            border: 1px solid var(--font-color);
            border-radius: 6px;
            color: var(--font-color);
            text-decoration: none;
            font-size: 0.8rem;
            margin-left: 12px;
            transition: opacity 0.2s;
            vertical-align: middle;
            line-height: 1.6;
        }
        .admin-link:hover { opacity: 0.55; }

        /* 公告消息条 */
        .announcement-bar {
            max-width: 1000px;
            margin: 0 auto 16px;
            background: var(--card-bg);
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            overflow: hidden;
            position: relative;
            animation: slideDown 0.3s ease;
        }
        .announcement-bar.hidden { display: none; }
        .ann-progress {
            height: 3px;
            background: var(--primary-color);
            width: 100%;
        }
        .ann-body {
            display: flex;
            align-items: flex-start;
            padding: 14px 20px;
            gap: 12px;
        }
        .ann-body .ann-content {
            flex: 1;
            color: var(--font-color);
            font-size: 0.92rem;
            line-height: 1.7;
            text-align: left;
        }
        .ann-body .ann-content p { margin: 4px 0; }
        .ann-body .ann-content a { color: var(--primary-color); }
        .ann-body .ann-content code {
            background: #f1f5f9;
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.85rem;
        }
        .ann-close-btn {
            background: none;
            border: none;
            font-size: 1.3rem;
            cursor: pointer;
            color: #999;
            line-height: 1;
            padding: 0 2px;
            flex-shrink: 0;
        }
        .ann-close-btn:hover { color: #333; }

        @keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
        @keyframes slideDown { from { opacity: 0; transform: translateY(-10px); } to { opacity: 1; transform: translateY(0); } }
    </style>
</head>
<body>

<!-- 公告消息条和内联样式已整合到上方容器中 -->

<div class="container">
    <!-- 公告消息条 -->
    <div class="announcement-bar hidden" id="announcement-bar">
        <div class="ann-progress" id="ann-progress"></div>
        <div class="ann-body">
            <div class="ann-content" id="announcement-content"></div>
            <button class="ann-close-btn" onclick="closeAnnouncement()">×</button>
        </div>
    </div>

    <header>
        <div class="date-info" style="text-align:left;">
            <span>开学：<?php echo $config['start_date']; ?></span>
            <span>结束：<?php echo $config['end_date']; ?></span>
        </div>
        <div>
            <span class="current-time" id="server-time">Loading...</span>
            <a href="admin.php" class="admin-link">管理</a>
        </div>
    </header>

    <div class="progress-section">
        <div class="progress-title">本学期进度</div>
        <div class="progress-container">
            <div class="progress-bar" id="progress-bar" style="width: 0%"></div>
        </div>
        <div class="percentage-display">
            <span id="percent-text">0.000000</span>
            <span class="percentage-symbol">%</span>
        </div>
    </div>

    <div class="countdown-grid">
        <div class="countdown-card">
            <h3>距离学期结束</h3>
            <div class="timer-display" id="total-timer">-- days --:--:--</div>
        </div>
        <div class="countdown-card">
            <h3>剩余工作时间</h3>
            <div class="timer-display" id="work-timer">-- days --:--:--</div>
        </div>
    </div>

    <div class="calendar-section">
        <?php include 'date.php'; ?>
    </div>
</div>

<script>
    // ==================== 核心配置 ====================
    const defaultConfig = <?php echo json_encode($initData); ?>;
    let config = Object.assign({}, defaultConfig);

    // 直接使用客户端时间（服务端时间仅作为配置来源，不用于计时同步）
    function getCurrentTime() {
        return new Date();
    }

    function formatNumber(num) {
        return String(Math.floor(num)).padStart(2, '0');
    }

    function formatDateStr(dateObj) {
        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, '0');
        const d = String(dateObj.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    function renderTimer(ms) {
        if (ms < 0) ms = 0;
        const seconds = Math.floor(ms / 1000);
        const d = Math.floor(seconds / 86400);
        const h = Math.floor((seconds % 86400) / 3600);
        const m = Math.floor((seconds % 3600) / 60);
        const s = seconds % 60;
        return `
            <div>${d}<span class="timer-unit"><span>天</span></span></div>:
            <div>${formatNumber(h)}<span class="timer-unit"><span>时</span></span></div>:
            <div>${formatNumber(m)}<span class="timer-unit"><span>分</span></span></div>:
            <div>${formatNumber(s)}<span class="timer-unit"><span>秒</span></span></div>
        `;
    }

    function isWorkDay(dateObj) {
        const dateStr = formatDateStr(dateObj);
        const day = dateObj.getDay();
        if (config.workdays.includes(dateStr)) return true;
        if (config.holidays.includes(dateStr)) return false;
        if (day === 0 || day === 6) return false;
        return true;
    }

    function update() {
        const now = getCurrentTime();
        const nowTs = now.getTime();

        // 更新时间显示
        const timeStr = now.getFullYear() + '年' +
                       String(now.getMonth()+1).padStart(2,'0') + '月' +
                       String(now.getDate()).padStart(2,'0') + '日 ' +
                       String(now.getHours()).padStart(2,'0') + ':' +
                       String(now.getMinutes()).padStart(2,'0') + ':' +
                       String(now.getSeconds()).padStart(2,'0');
        document.getElementById('server-time').innerText = timeStr;

        // 进度百分比
        const totalDuration = config.end - config.start;
        const passedDuration = nowTs - config.start;
        let percent = 0;
        if (passedDuration >= totalDuration) percent = 100;
        else if (passedDuration > 0) percent = (passedDuration / totalDuration) * 100;

        document.getElementById('progress-bar').style.width = percent + '%';
        document.getElementById('percent-text').innerText = percent.toFixed(6);

        // 总倒计时
        const remainingTotal = config.end - nowTs;
        document.getElementById('total-timer').innerHTML = renderTimer(remainingTotal);

        // 工作日倒计时
        let remainingWorkSeconds = 0;
        if (nowTs < config.end) {
            let cursor = new Date(nowTs);
            let endDate = new Date(config.end);
            let loopLimit = 1000;
            while (cursor < endDate && loopLimit > 0) {
                let dayEnd = new Date(cursor);
                dayEnd.setHours(24, 0, 0, 0);
                if (dayEnd > endDate) dayEnd = new Date(endDate);
                if (isWorkDay(cursor)) {
                    remainingWorkSeconds += (dayEnd - cursor);
                }
                cursor.setDate(cursor.getDate() + 1);
                cursor.setHours(0, 0, 0, 0);
                loopLimit--;
            }
        }
        document.getElementById('work-timer').innerHTML = renderTimer(remainingWorkSeconds);
    }

    // ==================== 应用配置到页面 ====================
    function applyConfig(cfg) {
        const root = document.documentElement;
        root.style.setProperty('--primary-color', cfg.primary_color);
        root.style.setProperty('--font-color', cfg.font_color);
        root.style.setProperty('--card-bg', cfg.card_bg);
        root.style.setProperty('--page-bg', cfg.page_bg);
        root.style.setProperty('--progress-start', cfg.progress_bar_start);
        root.style.setProperty('--progress-end', cfg.progress_bar_end);
        root.style.setProperty('--percent-color', cfg.progress_percent_color);
        root.style.setProperty('--bg-image-opacity', cfg.bg_image_opacity);
        root.style.setProperty('--glass-opacity', cfg.glass_opacity);

        // 背景模式
        if (cfg.bg_type === 'image' && cfg.bg_image_url) {
            document.body.classList.add('bg-image');
            root.style.setProperty('--bg-image', `url(${cfg.bg_image_url})`);
        } else {
            document.body.classList.remove('bg-image');
            root.style.setProperty('--bg-image', 'none');
        }

        // 纯色背景
        if (cfg.bg_type === 'solid' && cfg.bg_color) {
            root.style.setProperty('--page-bg', cfg.bg_color);
        }

        // 更新标题
        if (cfg.title) {
            document.title = cfg.title;
        }

        // 合并到运行时配置
        Object.assign(config, cfg);
    }

    function applyJSONConfig(jsonSettings) {
        const cfg = {
            primary_color: jsonSettings.primary_color || defaultConfig.primary_color,
            font_color: jsonSettings.font_color || defaultConfig.font_color,
            card_bg: jsonSettings.card_bg || defaultConfig.card_bg,
            page_bg: jsonSettings.page_bg || defaultConfig.page_bg,
            progress_bar_start: jsonSettings.progress_bar_start || defaultConfig.progress_bar_start,
            progress_bar_end: jsonSettings.progress_bar_end || defaultConfig.progress_bar_end,
            progress_percent_color: jsonSettings.progress_percent_color || defaultConfig.progress_percent_color,
            bg_type: jsonSettings.bg_type || defaultConfig.bg_type,
            bg_color: jsonSettings.bg_color || defaultConfig.bg_color,
            bg_image_url: jsonSettings.bg_image_url || defaultConfig.bg_image_url,
            bg_image_opacity: jsonSettings.bg_image_opacity || defaultConfig.bg_image_opacity,
            glass_opacity: jsonSettings.glass_opacity || defaultConfig.glass_opacity,
            title: jsonSettings.title || defaultConfig.title,
            start: new Date(jsonSettings.start_date || defaultConfig.start_date.replace(' ', 'T')).getTime(),
            end: new Date(jsonSettings.end_date || defaultConfig.end_date.replace(' ', 'T')).getTime(),
            holidays: jsonSettings.holidays ? (typeof jsonSettings.holidays === 'string' ? JSON.parse(jsonSettings.holidays) : jsonSettings.holidays) : defaultConfig.holidays,
            workdays: jsonSettings.workdays ? (typeof jsonSettings.workdays === 'string' ? JSON.parse(jsonSettings.workdays) : jsonSettings.workdays) : defaultConfig.workdays,
        };
        applyConfig(cfg);
    }

    // ==================== 初始化流程 ====================
    async function init() {
        // 1. 从服务端加载配置
        try {
            const resp = await fetch('api.php?action=get_settings');
            const json = await resp.json();
            if (json.success && json.data) {
                applyJSONConfig(json.data);
                // 合并额外配置字段（如 announcement_auto_close 等）
                Object.assign(config, json.data);
                // 同步到 localStorage（方便离线查看）
                localStorage.setItem('semester_config', JSON.stringify(json.data));
            }
        } catch (e) {
            // API 不可用，回退到 localStorage 或默认值
            loadLocalConfig();
        }

        // 2. 加载公告（公告始终从服务器获取）
        try {
            const resp = await fetch('api.php?action=get_announcement');
            const json = await resp.json();
            if (json.success && json.data && json.data.content) {
                showAnnouncement(json.data.content);
            }
        } catch (e) {}

        // 3. 启动计时器
        setInterval(update, 250);
        update();
    }

    function loadLocalConfig() {
        const saved = localStorage.getItem('semester_config');
        if (saved) {
            try {
                const jsonSettings = JSON.parse(saved);
                applyJSONConfig(jsonSettings);
                return;
            } catch (e) {}
        }
    }

    // ==================== Markdown 渲染 ====================
    function markdownToHtml(text) {
        return text
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
            .replace(/\*(.+?)\*/g, '<em>$1</em>')
            .replace(/`(.+?)`/g, '<code>$1</code>')
            .replace(/\[(.+?)\]\((.+?)\)/g, '<a href="$2" target="_blank" rel="noopener">$1</a>')
            .replace(/\n/g, '<br>');
    }

    // ==================== 公告消息条 ====================
    let annTimer = null;

    function showAnnouncement(content) {
        const bar = document.getElementById('announcement-bar');
        const contentDiv = document.getElementById('announcement-content');
        const progress = document.getElementById('ann-progress');

        contentDiv.innerHTML = markdownToHtml(content);
        bar.classList.remove('hidden');

        // 自动关闭进度条
        const closeSec = parseInt(config.announcement_auto_close) || 5;
        const closeMs = closeSec * 1000;
        progress.style.transition = 'none';
        progress.style.width = '100%';
        // 触发重排后开始过渡
        progress.offsetHeight;
        progress.style.transition = `width ${closeMs}ms linear`;
        progress.style.width = '0%';

        if (annTimer) clearTimeout(annTimer);
        annTimer = setTimeout(function() {
            closeAnnouncement();
        }, closeMs);
    }

    function closeAnnouncement() {
        document.getElementById('announcement-bar').classList.add('hidden');
        if (annTimer) { clearTimeout(annTimer); annTimer = null; }
    }

    // ==================== PWA / 注册 Service Worker ====================
    if ('serviceWorker' in navigator) {
        navigator.serviceWorker.register('sw.js').catch(function(err) {
            console.log('SW注册失败:', err);
        });
    }

    // ==================== 启动 ====================
    init();
</script>
</body>
</html>
