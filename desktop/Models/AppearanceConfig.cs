using System.Text.Json.Serialization;

namespace DesktopCountdown.Models;

public sealed class AppearanceConfig
{
    public BackgroundMode BackgroundMode { get; set; } = BackgroundMode.Acrylic;
    public string BackgroundColor { get; set; } = "#CCFFFFFF";
    public string AccentColor { get; set; } = "#7CB7FF";
    public double Opacity { get; set; } = 0.86;
    public double BlurRadius { get; set; } = 24;
    public double CornerRadius { get; set; } = 18;
    public bool BorderEnabled { get; set; } = true;
    public string BorderColor { get; set; } = "#66FFFFFF";
    public string? BackgroundImagePath { get; set; }
    public ImageStretchMode ImageStretch { get; set; } = ImageStretchMode.UniformToFill;
    public string FontFamily { get; set; } = "Segoe UI";
    public string TextColor { get; set; } = "#FFFFFFFF";

    /// <summary>系统亚克力之上的着色层（ARGB），用于在任意壁纸上保证文字可读。</summary>
    public string AcrylicTintColor { get; set; } = "#66111820";

    /// <summary>经典 Aero 玻璃质感：顶部高光 + 斜向反射 + 边框发光。</summary>
    public bool AeroGlassEffect { get; set; } = true;

    /// <summary>Aero 质感强度（0~1）。1 为默认；调低可减弱高光以免影响文字对比度。</summary>
    public double AeroGlassIntensity { get; set; } = 1.0;
}

[JsonConverter(typeof(JsonStringEnumConverter<BackgroundMode>))]
public enum BackgroundMode
{
    LiquidGlass,
    Solid,
    Gradient,
    Image,
    /// <summary>Windows 11 真实亚克力（系统级模糊），不可用时自动回退为 LiquidGlass。</summary>
    Acrylic
}

[JsonConverter(typeof(JsonStringEnumConverter<ImageStretchMode>))]
public enum ImageStretchMode
{
    UniformToFill,
    Uniform,
    Fill
}
