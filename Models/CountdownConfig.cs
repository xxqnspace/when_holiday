namespace DesktopCountdown.Models;

public sealed class CountdownConfig
{
    public string Title { get; set; } = "重要倒计时";
    public DateTimeOffset TargetDateTime { get; set; } = DateTimeOffset.Now.AddDays(1);
    public string EndText { get; set; } = "已结束";
    public bool NotifyOnEnd { get; set; }
}
