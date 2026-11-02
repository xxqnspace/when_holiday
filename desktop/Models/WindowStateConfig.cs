namespace DesktopCountdown.Models;

public sealed class WindowStateConfig
{
    public double Left { get; set; } = 1200;
    public double Top { get; set; } = 80;
    public double Width { get; set; } = 300;
    public double Height { get; set; } = 150;
    public bool Topmost { get; set; } = true;
    public bool Locked { get; set; }
    public bool Visible { get; set; } = true;
}
