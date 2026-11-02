namespace DesktopCountdown.Models;

public sealed class AppConfig
{
    /// <summary>当前程序支持的配置结构版本。</summary>
    public const int CurrentSchemaVersion = 2;

    public int SchemaVersion { get; set; } = 1;
    public bool IsFirstRun { get; set; } = true;
    public CountdownConfig Countdown { get; set; } = new();
    public DisplayUnitConfig DisplayUnits { get; set; } = new();
    public AppearanceConfig Appearance { get; set; } = new();
    public WindowStateConfig Window { get; set; } = new();
    public BehaviorConfig Behavior { get; set; } = new();
    public ScheduleConfig Schedule { get; set; } = new();

    /// <summary>
    /// 反序列化后的兜底修复。
    /// <para>System.Text.Json 遇到 JSON 里显式的 <c>null</c> 会把属性覆盖为 null（默认初始化器不再生效），
    /// 而本项目鼓励直接手改 config.json，因此加载后必须做一次空值与数值范围的归一化，
    /// 否则会在启动阶段直接抛 NullReferenceException。</para>
    /// </summary>
    public void Normalize()
    {
        Countdown ??= new CountdownConfig();
        DisplayUnits ??= new DisplayUnitConfig();
        Appearance ??= new AppearanceConfig();
        Window ??= new WindowStateConfig();
        Behavior ??= new BehaviorConfig();
        Schedule ??= new ScheduleConfig();

        Schedule.Window ??= new WindowStateConfig
        {
            Left = 1200,
            Top = 240,
            Width = 300,
            Height = 84,
            Topmost = true
        };
        Schedule.Lessons ??= ScheduleConfig.CreateDefaultLessons();
        Schedule.Lessons.RemoveAll(lesson => lesson is null);
        foreach (var lesson in Schedule.Lessons)
        {
            lesson.Name ??= string.Empty;
            lesson.StartText ??= string.Empty;
            lesson.EndText ??= string.Empty;
        }

        // 字符串字段：显式 null 会让后续的 .Trim() / ColorConverter 直接抛异常。
        Countdown.Title ??= "重要倒计时";
        Countdown.EndText ??= "已结束";

        Appearance.BackgroundColor ??= "#CCFFFFFF";
        Appearance.AccentColor ??= "#7CB7FF";
        Appearance.BorderColor ??= "#66FFFFFF";
        Appearance.TextColor ??= "#FFFFFFFF";
        Appearance.AcrylicTintColor ??= "#66111820";
        Appearance.FontFamily = string.IsNullOrWhiteSpace(Appearance.FontFamily) ? "Segoe UI" : Appearance.FontFamily.Trim();

        Schedule.IdleText ??= "放学啦，注意休息";
        Schedule.TextColor ??= "#FF000000";
        Schedule.BackgroundTint ??= "#CCFFFFFF";

        // 数值范围：负数圆角会让 CornerRadius 抛异常，超出范围的透明度会让窗口彻底消失。
        Appearance.Opacity = Math.Clamp(Appearance.Opacity, 0.2, 1.0);
        Appearance.CornerRadius = Math.Clamp(Appearance.CornerRadius, 0, 200);
        Appearance.BlurRadius = Math.Clamp(Appearance.BlurRadius, 0, 200);
        Appearance.AeroGlassIntensity = Math.Clamp(Appearance.AeroGlassIntensity, 0, 1);

        Window.Width = Math.Clamp(Window.Width, 1, 10000);
        Window.Height = Math.Clamp(Window.Height, 1, 10000);
        Schedule.Window.Width = Math.Clamp(Schedule.Window.Width, 1, 10000);
        Schedule.Window.Height = Math.Clamp(Schedule.Window.Height, 1, 10000);
    }
}
