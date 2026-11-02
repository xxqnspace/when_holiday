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

// 转为数组以便计数
$months = iterator_to_array($period);
$monthCount = count($months);
$currentYearMonth = $now->format('Y-m');

function getWeekNumber($dateObj, $semesterStart) {
    $d1 = clone $dateObj;
    $d2 = clone $semesterStart;
    $d1->modify('this week monday'); 
    $diff_days = ($d1->getTimestamp() - $d2->modify('this week monday')->getTimestamp()) / 86400;
    return floor($diff_days / 7) + 1;
}

function getHolidayWeekNumber($dateObj, $semesterEnd) {
    $d1 = clone $dateObj;
    $d2 = clone $semesterEnd;
    $d1->modify('this week monday');
    $d2->modify('this week monday');
    $diff_days = ($d1->getTimestamp() - $d2->getTimestamp()) / 86400;
    return floor($diff_days / 7) + 1;
}

?>

<div class="calendar-container" id="calendar-container">
    <?php foreach ($months as $dt): 
        $isCurrentMonth = ($dt->format('Y-m') === $currentYearMonth);
    ?>
        <div class="month-card <?php echo $isCurrentMonth ? 'current-month' : 'extra-month'; ?>" data-month="<?php echo $dt->format('Y-m'); ?>">
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
                    if ($current_week_date < $start) {
                        $week_num = "-";
                    } elseif ($current_week_date > $end) {
                        $week_num = "放假" . getHolidayWeekNumber($current_week_date, $end) . "周";
                    } else {
                        $week_num = getWeekNumber($current_week_date, $start);
                    }
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
                            if ($current_day > $end) {
                                $wk = "放假" . getHolidayWeekNumber($current_day, $end) . "周";
                            } elseif ($current_day < $start) {
                                $wk = "-";
                            } else {
                                $wk = getWeekNumber($current_day, $start);
                            }
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

<?php if ($monthCount > 1): ?>
<div class="calendar-toggle-wrap">
    <button class="calendar-toggle-btn" id="calendar-toggle-btn" onclick="toggleCalendarMonths()">展开本学期全部月份</button>
</div>
<?php endif; ?>
