using System.IO;
using System.Windows.Media;
using System.Windows.Media.Imaging;
using DesktopCountdown.Models;

// UseWindowsForms 会隐式引入 System.Drawing，以下别名用于消除 Color / Brush 的二义性。
using Color = System.Windows.Media.Color;
using Brush = System.Windows.Media.Brush;

namespace DesktopCountdown.Helpers;

/// <summary>
/// 统一生成悬浮窗背景画刷，倒计时窗口与课程窗口共用。
/// </summary>
public static class AppearanceBrushFactory
{
    /// <summary>
    /// 卡片自身着色层的上限。accent 亚克力策略已经用同一个混合色画了一层着色，
    /// 卡片再叠一层同色会把玻璃压得过暗，所以这里限得更低。
    /// </summary>
    private const byte MaxCardTintAlpha = 0x40;

    /// <summary>
    /// 创建内容背景画刷。
    /// </summary>
    /// <param name="appearance">外观配置。</param>
    /// <param name="acrylicBlur">是否开启了亚克力模糊（此时只画一层着色，不遮挡背后的模糊层）。</param>
    /// <param name="windowOpacity">
    /// 用户设置的透明度。亚克力模式下窗口本身不再叠加着色层，这个值折算进着色层的 alpha，
    /// 让「透明度」滑块在亚克力模式下同样有效。
    /// </param>
    public static Brush Create(AppearanceConfig appearance, bool acrylicBlur, double windowOpacity = 1.0)
    {
        if (acrylicBlur)
        {
            return CreateAcrylicTint(appearance, windowOpacity);
        }

        if (appearance.BackgroundMode == BackgroundMode.Image &&
            !string.IsNullOrWhiteSpace(appearance.BackgroundImagePath) &&
            File.Exists(appearance.BackgroundImagePath))
        {
            var image = new BitmapImage();
            image.BeginInit();
            image.CacheOption = BitmapCacheOption.OnLoad;
            image.UriSource = new Uri(appearance.BackgroundImagePath);
            image.EndInit();
            return new ImageBrush(image)
            {
                Stretch = appearance.ImageStretch switch
                {
                    ImageStretchMode.Fill => Stretch.Fill,
                    ImageStretchMode.Uniform => Stretch.Uniform,
                    _ => Stretch.UniformToFill
                }
            };
        }

        // 图片模式但文件缺失时退化为纯色（而不是静默变成液态玻璃），并给调用方留下提示依据。
        if (appearance.BackgroundMode == BackgroundMode.Image)
        {
            return ColorHelper.BrushFrom(appearance.BackgroundColor, new SolidColorBrush(Color.FromArgb(210, 255, 255, 255)));
        }

        if (appearance.BackgroundMode == BackgroundMode.Solid)
        {
            return ColorHelper.BrushFrom(appearance.BackgroundColor, new SolidColorBrush(Color.FromArgb(210, 255, 255, 255)));
        }

        if (appearance.BackgroundMode == BackgroundMode.Gradient)
        {
            return new LinearGradientBrush(
                ColorHelper.ColorFrom(appearance.BackgroundColor, Color.FromArgb(210, 255, 255, 255)),
                ColorHelper.ColorFrom(appearance.AccentColor, Color.FromArgb(180, 124, 183, 255)),
                35);
        }

        // 模拟玻璃（液态玻璃）：三段式渐变，兼容 Windows 10 与纯透明窗口场景。
        var brush = new LinearGradientBrush { StartPoint = new System.Windows.Point(0, 0), EndPoint = new System.Windows.Point(1, 1) };
        brush.GradientStops.Add(new GradientStop(Color.FromArgb(210, 255, 255, 255), 0));
        brush.GradientStops.Add(new GradientStop(Color.FromArgb(120, 124, 183, 255), 0.48));
        brush.GradientStops.Add(new GradientStop(Color.FromArgb(170, 255, 255, 255), 1));
        return brush;
    }

    /// <summary>图片模式下背景图缺失（未选择或文件已被移动/删除）。</summary>
    public static bool IsBackgroundImageMissing(AppearanceConfig appearance)
    {
        return appearance.BackgroundMode == BackgroundMode.Image &&
               (string.IsNullOrWhiteSpace(appearance.BackgroundImagePath) ||
                !File.Exists(appearance.BackgroundImagePath));
    }

    /// <summary>
    /// 亚克力模糊模式下卡片自身叠加的着色层。
    /// <para>accent 策略（<c>SetWindowCompositionAttribute</c>）已经用配置里的混合色画了一层
    /// 模糊 + 着色，卡片这里只再补一层较淡的同色：一是把窗口的每像素 alpha 撑起来
    /// （全透明区域不会显示模糊），二是让「透明度」滑块仍然有效。</para>
    /// <para>上限取 <see cref="MaxCardTintAlpha"/>，避免两层同色叠加把玻璃压得过暗。</para>
    /// </summary>
    public static Brush CreateAcrylicTint(AppearanceConfig appearance, double windowOpacity = 1.0)
    {
        var color = ColorHelper.ColorFrom(appearance.AcrylicTintColor, Color.FromArgb(0x66, 17, 24, 32));
        color.A = ScaleAlpha(Math.Min(color.A, MaxCardTintAlpha), windowOpacity, MaxCardTintAlpha);
        return new SolidColorBrush(color);
    }

    /// <summary>把基础 alpha 按用户透明度缩放，并限制在指定上限内。</summary>
    public static byte ScaleAlpha(byte baseAlpha, double windowOpacity, byte maxAlpha)
    {
        var opacity = Math.Clamp(windowOpacity, 0.2, 1.0);
        var scaled = Math.Round(baseAlpha * opacity);
        return (byte)Math.Clamp(scaled, 0, maxAlpha);
    }

    #region 经典 Aero 玻璃质感

    /// <summary>
    /// Aero 顶部高光：上半部分亮、约 45% 处衰减到透明，底部再回升一道淡光（模拟玻璃厚度）。
    /// </summary>
    public static Brush CreateAeroHighlight(double intensity)
    {
        var s = Math.Clamp(intensity, 0, 1);
        var brush = new LinearGradientBrush
        {
            StartPoint = new System.Windows.Point(0, 0),
            EndPoint = new System.Windows.Point(0, 1)
        };
        brush.GradientStops.Add(new GradientStop(White(0.42 * s), 0.00));
        brush.GradientStops.Add(new GradientStop(White(0.26 * s), 0.06));
        brush.GradientStops.Add(new GradientStop(White(0.08 * s), 0.24));
        brush.GradientStops.Add(new GradientStop(White(0.00), 0.48));
        brush.GradientStops.Add(new GradientStop(White(0.00), 0.62));
        brush.GradientStops.Add(new GradientStop(White(0.05 * s), 0.86));
        brush.GradientStops.Add(new GradientStop(White(0.13 * s), 1.00));
        return brush;
    }

    /// <summary>Aero 斜向反射：左上到右下的玻璃反光，很淡，用来制造「玻璃面」的立体感。</summary>
    public static Brush CreateAeroSheen(double intensity)
    {
        var s = Math.Clamp(intensity, 0, 1);
        var brush = new LinearGradientBrush
        {
            StartPoint = new System.Windows.Point(0, 0),
            EndPoint = new System.Windows.Point(1, 1)
        };
        brush.GradientStops.Add(new GradientStop(White(0.18 * s), 0.00));
        brush.GradientStops.Add(new GradientStop(White(0.06 * s), 0.20));
        brush.GradientStops.Add(new GradientStop(White(0.00), 0.44));
        brush.GradientStops.Add(new GradientStop(White(0.00), 0.76));
        brush.GradientStops.Add(new GradientStop(White(0.07 * s), 1.00));
        return brush;
    }

    /// <summary>Aero 边框发光：顶部最亮、两侧渐暗、底部略回升，模拟玻璃边缘的受光。</summary>
    public static Brush CreateAeroGlowBorder(double intensity)
    {
        var s = Math.Clamp(intensity, 0, 1);
        var brush = new LinearGradientBrush
        {
            StartPoint = new System.Windows.Point(0, 0),
            EndPoint = new System.Windows.Point(0, 1)
        };
        brush.GradientStops.Add(new GradientStop(White(0.85 * s), 0.00));
        brush.GradientStops.Add(new GradientStop(White(0.42 * s), 0.16));
        brush.GradientStops.Add(new GradientStop(White(0.20 * s), 0.55));
        brush.GradientStops.Add(new GradientStop(White(0.34 * s), 0.86));
        brush.GradientStops.Add(new GradientStop(White(0.58 * s), 1.00));
        return brush;
    }

    private static Color White(double alpha)
    {
        return Color.FromArgb((byte)Math.Clamp(alpha * 255.0, 0, 255), 255, 255, 255);
    }

    #endregion
}
