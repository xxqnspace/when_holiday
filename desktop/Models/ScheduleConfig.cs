using System.Globalization;

namespace DesktopCountdown.Models;

/// <summary>一节课的时间段。时间以文本存储（HH:mm），便于配置文件直接阅读与编辑。</summary>
public sealed class LessonPeriod
{
    public string Name { get; set; } = "第一节";
    public string StartText { get; set; } = "08:00";
    public string EndText { get; set; } = "08:45";

    public TimeSpan? StartTime => ParseTime(StartText);
    public TimeSpan? EndTime => ParseTime(EndText);

    public LessonPeriod Clone() => new()
    {
        Name = Name,
        StartText = StartText,
        EndText = EndText
    };

    public static TimeSpan? ParseTime(string? text)
    {
        if (string.IsNullOrWhiteSpace(text))
        {
            return null;
        }

        var value = text.Trim().Replace('：', ':');
        if (TimeSpan.TryParseExact(value,
                new[] { @"h\:mm", @"hh\:mm", @"h\:mm\:ss", @"hh\:mm\:ss" },
                CultureInfo.InvariantCulture,
                out var exact))
        {
            return exact;
        }

        return TimeSpan.TryParse(value, CultureInfo.InvariantCulture, out var loose) ? loose : null;
    }
}

/// <summary>课程提示窗口配置。</summary>
public sealed class ScheduleConfig
{
    /// <summary>是否启用课程提示窗口。</summary>
    public bool Enabled { get; set; } = true;

    /// <summary>是否吸附在倒计时窗口正下方（跟随移动与宽度）。</summary>
    public bool FollowWidget { get; set; } = true;

    /// <summary>全部课程结束后的副标题文案。</summary>
    public string IdleText { get; set; } = "放学啦，注意休息";

    /// <summary>课程窗口文字颜色（与倒计时窗口独立，默认黑色）。</summary>
    public string TextColor { get; set; } = "#FF000000";

    /// <summary>系统亚克力模式下课程窗口的着色层，浅色底保证黑字可读。</summary>
    public string BackgroundTint { get; set; } = "#CCFFFFFF";

    /// <summary>节次时间表。</summary>
    public List<LessonPeriod> Lessons { get; set; } = CreateDefaultLessons();

    /// <summary>课程窗口位置（仅在未吸附时生效）。</summary>
    public WindowStateConfig Window { get; set; } = new()
    {
        Left = 1200,
        Top = 240,
        Width = 300,
        Height = 84,
        Topmost = true
    };

    /// <summary>默认作息：上午四节、下午三节，每节 45 分钟。</summary>
    public static List<LessonPeriod> CreateDefaultLessons() => new()
    {
        new LessonPeriod { Name = "第一节", StartText = "08:00", EndText = "08:45" },
        new LessonPeriod { Name = "第二节", StartText = "08:55", EndText = "09:40" },
        new LessonPeriod { Name = "第三节", StartText = "10:00", EndText = "10:45" },
        new LessonPeriod { Name = "第四节", StartText = "10:55", EndText = "11:40" },
        new LessonPeriod { Name = "第五节", StartText = "14:00", EndText = "14:45" },
        new LessonPeriod { Name = "第六节", StartText = "14:55", EndText = "15:40" },
        new LessonPeriod { Name = "第七节", StartText = "15:50", EndText = "16:35" }
    };
}
