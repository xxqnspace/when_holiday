<?php
// date.php
if (!isset($config)) {
    $config = include 'config.php';
}

$start = new DateTime($config['start_date']);
$end = new DateTime($config['end_date']);
$now = new DateTime();

// 获取日历显示的起始月份和结束月份
$start_month = (clone $start)->modify('first day of this month');
$end_month = (clone $end)->modify('last day of this month');

$period = new DatePeriod(
    $start_month,
    new DateInterval('P1M'),
    $end_month->modify('+1 second') // 确保包含结束月
);

function getWeekNumber($dateObj, $semesterStart) {
    // 逻辑：计算当前日期所在周的周一，与开学日期所在周的周一，相差几周
    // 周一为一周开始
    $d1 = clone $dateObj;
    $d2 = clone $semesterStart;
    
    // 强制转为当周周一
    $d1->modify('this week monday'); 
    // 注意：如果学期开始那天是周日，php 'this week monday' 可能会跳到明天(下周一)，视php版本设置而定
    // 这里的逻辑：如果今天是周日(7)，'this week monday' 通常指甚至前6天的那个周一
    
    // 为了稳妥，用时间戳计算天数差 / 7
    $diff_days = ($d1->getTimestamp() - $d2->modify('this week monday')->getTimestamp()) / 86400;
    return floor($diff_days / 7) + 1;
}

?>

<div class="calendar-container">
    <?php foreach ($period as $dt): ?>
        <div class="month-card">
            <div class="month-title"><?php echo $dt->format('Y年m月'); ?></div>
            <table>
                <thead>
                    <tr>
                        <th>周次</th>
                        <th>一</th><th>二</th><th>三</th><th>四</th><th>五</th><th class="weekend">六</th><th class="weekend">日</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $month_start = (clone $dt)->modify('first day of this month');
                    $month_end = (clone $dt)->modify('last day of this month');
                    
                    // 获取这个月第一天是星期几 (1-7)
                    $first_day_of_week = $month_start->format('N');
                    
                    // 补全前面的空位
                    echo "<tr>";
                    
                    // 计算第一行的周次
                    $current_week_date = clone $month_start;
                    $week_num = getWeekNumber($current_week_date, $start);
                    if ($current_week_date < $start) $week_num = "-"; // 开学前
                    
                    echo "<td class='week-col'>{$week_num}</td>";

                    for ($i = 1; $i < $first_day_of_week; $i++) {
                        echo "<td></td>";
                    }

                    // 循环输出日期
                    $current_day = clone $month_start;
                    while ($current_day <= $month_end) {
                        // 如果是周一，且不是第一天（第一天已经开启了tr），开启新行
                        if ($current_day->format('N') == 1 && $current_day != $month_start) {
                            echo "</tr><tr>";
                            // 新的一周，计算周次
                            $wk = getWeekNumber($current_day, $start);
                             // 如果超出学期周次范围太多可处理，这里简化
                            if ($current_day > $end && $end->format('N')!=7) $wk = "-"; // 简化逻辑：学期结束后的周次
                            
                            echo "<td class='week-col'>{$wk}</td>";
                        }

                        $dateStr = $current_day->format('Y-m-d');
                        $class = "day-cell";
                        
                        // 1. 已过日期：浅绿色
                        if ($current_day < $now && $current_day->format('Y-m-d') != $now->format('Y-m-d')) {
                            $class .= " past";
                        } 
                        // 2. 未过（含今天）
                        else {
                            $is_weekend = ($current_day->format('N') >= 6);
                            $is_holiday = in_array($dateStr, $config['holidays']);
                            $is_special_work = in_array($dateStr, $config['workdays']);

                            if (($is_weekend && !$is_special_work) || $is_holiday) {
                                // 休息日：浅蓝色
                                $class .= " rest";
                            } else {
                                // 工作日：白色
                                $class .= " work";
                            }
                        }

                        // 高亮今天
                        if ($dateStr == $now->format('Y-m-d')) {
                            $class .= " today";
                        }
                        
                        // 不在学期范围内的淡化处理
                        if ($current_day < $start || $current_day > $end) {
                            $class .= " out-of-range";
                        }

                        echo "<td class='{$class}'>{$current_day->format('j')}</td>";
                        
                        $current_day->modify('+1 day');
                    }

                    // 补全尾部空位
                    $last_day_of_week = $month_end->format('N');
                    for ($i = $last_day_of_week; $i < 7; $i++) {
                        echo "<td></td>";
                    }
                    echo "</tr>";
                    ?>
                </tbody>
            </table>
        </div>
    <?php endforeach; ?>
</div>
