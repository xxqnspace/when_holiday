<?php
$config = include 'config.php';
include 'countdown.php'; // 获取 $initData
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>什么时候放假啊o(╥﹏╥)o</title>
    <style>
        :root {
            --bg-color: #f7f9fc;
            --text-color: #333;
            --primary-color: #5c6bc0;
            --card-bg: #ffffff;
            --success-green: #e8f5e9;
            --info-blue: #e3f2fd;
            --border-radius: 12px;
            --shadow: 0 4px 20px rgba(0,0,0,0.05);
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background-color: var(--bg-color);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            text-align: center;
        }

        .container { max-width: 1000px; margin: 0 auto; }

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
        }
        .date-info span { display: block; color: #666; font-size: 0.9rem; margin: 5px 0; }
        .current-time { font-size: 1.2rem; font-weight: bold; color: var(--primary-color); font-variant-numeric: tabular-nums;}

        /* 进度条 */
        .progress-section {
            background: var(--card-bg);
            padding: 20px 30px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
            margin-bottom: 20px;
        }
        .progress-container {
            background-color: #ecf0f1;
            border-radius: 20px;
            height: 35px;
            width: 100%;
            overflow: hidden;
            margin-bottom: 10px;
            margin-top: 15px;
        }
        .progress-bar {
            background: linear-gradient(90deg, #7ed957, #2e8b57);
            height: 100%;
            width: 0%;
            transition: width 0.1s linear; /* 减少过渡时间以适应高频刷新 */
        }
        
        /* 优化的百分比样式 */
        .percentage-display {
            display: flex;
            justify-content: center;
            align-items: baseline;
            margin-top: 20px;
            font-size: 2.5rem;
            font-weight: 650;
            line-height: 1;
            color: #5c6bc0;
            text-shadow: 0 4px 8px rgba(46, 125, 50, 0.2);
            font-variant-numeric: tabular-nums; /* 防止数字跳动 */
            letter-spacing: -2px;
        }
        .percentage-symbol {
            font-size: 1.5rem;
            font-weight: 600;
            color: #5c6bc0;
            margin-left: 8px;
            transform: translateY(-5px);
        }

        /* 倒计时板块 */
        .countdown-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        @media (max-width: 768px) { .countdown-grid { grid-template-columns: 1fr; } }
        
        .countdown-card {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        .countdown-card h3 { margin-top: 0; color: #78909c; font-weight: normal; }
        
        .timer-display {
            font-size: 1.8rem;
            font-weight: bold;
            color: #455a64;
            display: flex;
            justify-content: center;
            gap: 10px;
            font-variant-numeric: tabular-nums;
        }
        .timer-unit span { font-size: 0.8rem; display: block; font-weight: normal; color: #999; }

        /* 日历样式引用 */
        .calendar-section {
            background: var(--card-bg);
            padding: 20px;
            border-radius: var(--border-radius);
            box-shadow: var(--shadow);
        }
        .calendar-container { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 20px; }
        .month-card { border: 1px solid #eee; border-radius: 8px; padding: 10px; background: #fff; }
        .month-title { font-weight: bold; margin-bottom: 10px; color: var(--primary-color); }
        table { width: 100%; border-collapse: collapse; font-size: 0.85rem; }
        th, td { padding: 5px; text-align: center; border-radius: 4px; }
        th.weekend { color: #ffab91; }
        .week-col { color: #aaa; font-size: 0.75rem; border-right: 1px solid #f0f0f0; }
        .day-cell { border: 1px solid transparent; }
        .past { background-color: var(--success-green); color: #2e7d32; }
        .rest { background-color: var(--info-blue); color: #1565c0; }
        .work { background-color: #ffffff; border: 1px solid #eee; }
        .today { border: 2px solid #ff9800 !important; font-weight: bold; }
        .out-of-range { opacity: 0.3; }

    </style>
</head>
<body>

<div class="container">
    <header>
        <div class="date-info" style="text-align:left;">
            <span>开学：<?php echo $config['start_date']; ?></span>
            <span>结束：<?php echo $config['end_date']; ?></span>
        </div>
        <div class="current-time" id="server-time">Loading...</div>
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
    // 1. 获取 PHP 预处理好的配置数据
    const config = <?php echo json_encode($initData); ?>;
    
    // 修正服务器与客户端的时间差
    const clientNow = Date.now();
    const timeOffset = config.serverNow - clientNow;

    function getCurrentTime() {
        return new Date(Date.now() + timeOffset);
    }

    function formatNumber(num) {
        return String(Math.floor(num)).padStart(2, '0');
    }

    // 格式化 YYYY-MM-DD，用于比对假期数组
    function formatDateStr(dateObj) {
        const y = dateObj.getFullYear();
        const m = String(dateObj.getMonth() + 1).padStart(2, '0');
        const d = String(dateObj.getDate()).padStart(2, '0');
        return `${y}-${m}-${d}`;
    }

    // 将毫秒转换为 天:时:分:秒 HTML结构
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

    // 判断某一天是否为工作日
    function isWorkDay(dateObj) {
        const dateStr = formatDateStr(dateObj);
        const day = dateObj.getDay(); // 0 is Sunday, 6 is Saturday
        
        // 如果是特殊工作日（补班），通过
        if (config.workdays.includes(dateStr)) return true;
        
        // 如果是特殊假期，不通过
        if (config.holidays.includes(dateStr)) return false;
        
        // 如果是周末，不通过
        if (day === 0 || day === 6) return false;
        
        // 默认为工作日
        return true;
    }

    // 主更新函数
    function update() {
        const now = getCurrentTime();
        const nowTs = now.getTime();

        // 1. 更新顶部时间显示
        const timeStr = now.getFullYear() + '年' + 
                       String(now.getMonth()+1).padStart(2,'0') + '月' + 
                       String(now.getDate()).padStart(2,'0') + '日 ' +
                       String(now.getHours()).padStart(2,'0') + ':' +
                       String(now.getMinutes()).padStart(2,'0') + ':' +
                       String(now.getSeconds()).padStart(2,'0');
        document.getElementById('server-time').innerText = timeStr;

        // 2. 学期总倒计时和进度百分比
        const totalDuration = config.end - config.start;
        const passedDuration = nowTs - config.start;
        
        let percent = 0;
        if (passedDuration >= totalDuration) percent = 100;
        else if (passedDuration > 0) percent = (passedDuration / totalDuration) * 100;

        // 更新进度条
        document.getElementById('progress-bar').style.width = percent + '%';
        document.getElementById('percent-text').innerText = percent.toFixed(6);

        // 更新总倒计时
        const remainingTotal = config.end - nowTs;
        document.getElementById('total-timer').innerHTML = renderTimer(remainingTotal);

        // 3. 计算工作日倒计时 (Core Logic)
        // 策略：我们不每次都循环整个学期，而是快速估算
        // 为了精确到秒，我们遍历从今天开始到结束日期的每一天
        
        let remainingWorkSeconds = 0;
        
        if (nowTs < config.end) {
            // 复制一个当前时间对象用于计算
            let cursor = new Date(nowTs);
            // 结束时间对象
            let endDate = new Date(config.end);
            let loopLimit = 1000; // 防止死循环保险丝

            // 循环遍历每一天
            // 这种方式虽然看起来笨，但JS执行100-200次循环仅需微秒级，完全可以支持高频刷新
            while (cursor < endDate && loopLimit > 0) {
                // 计算cursor当天的结束时间（今晚24:00 或 学期结束时间）
                let dayEnd = new Date(cursor);
                dayEnd.setHours(24, 0, 0, 0); 
                
                // 如果当天的结束时间超过了学期结束时间，截断
                if (dayEnd > endDate) {
                    dayEnd = new Date(endDate);
                }
                
                // 判断当前 Cursor 所在的这一天是否工作日
                if (isWorkDay(cursor)) {
                     // 累加这天的有效时间差 (毫秒)
                     remainingWorkSeconds += (dayEnd - cursor);
                }

                // 将 cursor 移到下一天 00:00
                cursor.setDate(cursor.getDate() + 1);
                cursor.setHours(0, 0, 0, 0);
                loopLimit--;
            }
        }
        
        // 更新工作日倒计时 DOM
        document.getElementById('work-timer').innerHTML = renderTimer(remainingWorkSeconds);
    }

    // 启动定时器：250ms 刷新一次 (40ms时为每秒25帧，视觉极度流畅)
    setInterval(update, 250);
    update(); // 立即执行一次

</script>
</body>
</html>
