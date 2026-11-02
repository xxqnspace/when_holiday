using DesktopCountdown.Models;

namespace DesktopCountdown.Services;

public static class CountdownFormatter
{
    /// <summary>
    /// 把剩余时间拆分为「数值 + 单位」的展示段。
    /// <para>本方法为纯函数：不修改传入的 <paramref name="units"/>。
    /// 全部单位都未勾选时，仅在本次计算中按「天」显示，不会回写到配置。</para>
    /// </summary>
    public static List<CountdownSegment> Format(DateTimeOffset target, DisplayUnitConfig units)
    {
        var remaining = target - DateTimeOffset.Now;
        if (remaining < TimeSpan.Zero)
        {
            remaining = TimeSpan.Zero;
        }

        var showDays = units.ShowDays;
        var showHours = units.ShowHours;
        var showMinutes = units.ShowMinutes;
        var showSeconds = units.ShowSeconds;

        if (!showDays && !showHours && !showMinutes && !showSeconds)
        {
            showDays = true;
        }

        var result = new List<CountdownSegment>();

        if (showDays && !showHours && !showMinutes && !showSeconds)
        {
            var value = units.DayRounding == DayRounding.Ceiling
                ? Math.Ceiling(remaining.TotalDays)
                : Math.Floor(remaining.TotalDays);
            result.Add(new CountdownSegment(value.ToString("0"), "天"));
            return result;
        }

        if (!showDays && showHours && !showMinutes && !showSeconds)
        {
            result.Add(new CountdownSegment(Math.Floor(remaining.TotalHours).ToString("0"), "时"));
            return result;
        }

        if (!showDays && !showHours && showMinutes && !showSeconds)
        {
            result.Add(new CountdownSegment(Math.Floor(remaining.TotalMinutes).ToString("0"), "分"));
            return result;
        }

        if (!showDays && !showHours && !showMinutes && showSeconds)
        {
            result.Add(new CountdownSegment(Math.Floor(remaining.TotalSeconds).ToString("0"), "秒"));
            return result;
        }

        var totalSeconds = (long)Math.Floor(remaining.TotalSeconds);
        var highest = showDays ? 0 : showHours ? 1 : 2;

        if (showDays)
        {
            var days = totalSeconds / 86400;
            totalSeconds %= 86400;
            result.Add(new CountdownSegment(days.ToString(), "天"));
        }

        if (showHours)
        {
            var hours = totalSeconds / 3600;
            totalSeconds %= 3600;
            result.Add(new CountdownSegment(hours.ToString(highest == 1 ? "0" : "00"), "时"));
        }

        if (showMinutes)
        {
            var minutes = totalSeconds / 60;
            totalSeconds %= 60;
            result.Add(new CountdownSegment(minutes.ToString(highest == 2 ? "0" : "00"), "分"));
        }

        if (showSeconds)
        {
            result.Add(new CountdownSegment(totalSeconds.ToString("00"), "秒"));
        }

        return result;
    }
}
