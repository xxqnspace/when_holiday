using System.ComponentModel;
using System.Windows;
using System.Windows.Controls;
using System.Windows.Input;
using System.Windows.Media;
using System.Windows.Threading;
using DesktopCountdown.Helpers;
using DesktopCountdown.Models;
using DesktopCountdown.Services;

namespace DesktopCountdown;

/// <summary>
/// 课程提示窗口：显示当前是第几节课、距下课/上课还有多久。
/// 可吸附在倒计时窗口正下方，也可独立摆放。
/// </summary>
public partial class LessonWindow : Window
{
    private const double BaseWidth = 300.0;
    private const double BaseHeight = 84.0;
    private const double FollowGap = 8.0;

    private readonly App _app;
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(1) };
    private string _lastPrimary = "";
    private string _lastSecondary = "";

    public LessonWindow(App app)
    {
        _app = app;

        InitializeComponent();

        // 同 WidgetWindow：统一使用 layered 窗口，形状由每像素 alpha 决定，
        // 圆角可跟随配置任意设置且带抗锯齿；亚克力模糊可在运行时开/关。
        SourceInitialized += OnSourceInitialized;

        ContextMenu = BuildContextMenu();
        _timer.Tick += OnTimerTick;
        _timer.Start();
    }

    public void ApplyConfig()
    {
        var config = _app.Config;
        var schedule = config.Schedule;

        if (!schedule.FollowWidget)
        {
            Width = Math.Max(schedule.Window.Width, MinWidth);
            Height = Math.Max(schedule.Window.Height, MinHeight);
            Left = schedule.Window.Left;
            Top = schedule.Window.Top;
        }

        Topmost = schedule.Window.Topmost || config.Window.Topmost;
        FontFamily = new System.Windows.Media.FontFamily(config.Appearance.FontFamily);
        Foreground = ColorHelper.BrushFrom(schedule.TextColor, System.Windows.Media.Brushes.Black);

        var cornerRadius = config.Appearance.CornerRadius;
        RootBorder.CornerRadius = new CornerRadius(cornerRadius);
        RootBorder.BorderBrush = config.Appearance.BorderEnabled
            ? config.Appearance.AeroGlassEffect
                ? AppearanceBrushFactory.CreateAeroGlowBorder(config.Appearance.AeroGlassIntensity)
                : ColorHelper.BrushFrom(config.Appearance.BorderColor, System.Windows.Media.Brushes.Black)
            : new SolidColorBrush(System.Windows.Media.Color.FromArgb(60, 0, 0, 0));
        RootBorder.Background = BuildBackdropBrush(config);
        ApplyAeroLayers(config.Appearance, cornerRadius);
        // Keep the layered window's bounds equal to the rounded card. A WPF
        // shadow effect expands those bounds and leaves a rectangular halo.
        RootBorder.Effect = null;

        // layered 窗口可以整体半透明，直接用用户设置的透明度。
        Opacity = config.Appearance.Opacity;

        UpdateAcrylicBlur();
        Refresh();
        UpdateFontSizes();
    }

    /// <summary>应用经典 Aero 玻璃质感（顶部高光 + 斜向反射）。关闭时清空叠加层。</summary>
    private void ApplyAeroLayers(AppearanceConfig appearance, double cornerRadius)
    {
        // 叠加层用与 RootBorder 完全相同的圆角，避免四角露出 1px 的边。
        var inner = new CornerRadius(Math.Max(0, cornerRadius));
        AeroHighlightBorder.CornerRadius = inner;
        AeroSheenBorder.CornerRadius = inner;

        if (appearance.AeroGlassEffect)
        {
            AeroHighlightBorder.Background = AppearanceBrushFactory.CreateAeroHighlight(appearance.AeroGlassIntensity);
            AeroSheenBorder.Background = AppearanceBrushFactory.CreateAeroSheen(appearance.AeroGlassIntensity);
        }
        else
        {
            AeroHighlightBorder.Background = null;
            AeroSheenBorder.Background = null;
        }
    }

    /// <summary>吸附到倒计时窗口正下方，并与其同宽。</summary>
    public void FollowTo(WidgetWindow widget)
    {
        Left = widget.Left;
        Top = widget.Top + widget.ActualHeight + FollowGap;

        var targetWidth = Math.Max(widget.ActualWidth, MinWidth);
        if (Math.Abs(Width - targetWidth) > 0.5)
        {
            Width = targetWidth;
            UpdateFontSizes();
        }
    }

    private void OnSourceInitialized(object? sender, EventArgs e)
    {
        UpdateAcrylicBlur();
    }

    /// <summary>
    /// 按当前配置开/关窗口背后的亚克力模糊。从亚克力模式切到其他模式时必须显式关闭，
    /// 否则 accent 策略会残留。
    /// </summary>
    private void UpdateAcrylicBlur()
    {
        // DWM Acrylic paints the whole layered window rectangle. Clear any
        // previous accent and use the clipped WPF glass brush instead.
        WindowBackdropHelper.DisableAcrylicBlur(this);
    }

    private void OnTimerTick(object? sender, EventArgs e)
    {
        Refresh();
    }

    protected override void OnClosed(EventArgs e)
    {
        // DispatcherTimer 会被 Dispatcher 强引用，不停止会导致已关闭的窗口永久驻留内存。
        _timer.Stop();
        _timer.Tick -= OnTimerTick;
        base.OnClosed(e);
    }

    /// <summary>
    /// 课程窗口背景：亚克力模式下叠加一层浅色着色（保证黑字可读），
    /// 其余模式复用外观配置。上限压到 0x66，避免与 accent 策略的同色着色叠加过白。
    /// </summary>
    private System.Windows.Media.Brush BuildBackdropBrush(AppConfig config)
    {
        // Match the countdown card's material selection. DWM itself remains
        // disabled for the layered window, but the Acrylic tint brush is still
        // rendered inside the rounded card so both cards stay in sync.
        return AppearanceBrushFactory.Create(
            config.Appearance,
            WindowBackdropHelper.ShouldUseAcrylicBlur(config),
            config.Appearance.Opacity);
    }

    private void Refresh()
    {
        var state = ScheduleService.Evaluate(_app.Config.Schedule, DateTime.Now);

        if (!string.Equals(state.Primary, _lastPrimary, StringComparison.Ordinal))
        {
            PrimaryText.Text = state.Primary;
            _lastPrimary = state.Primary;
        }

        if (!string.Equals(state.Secondary, _lastSecondary, StringComparison.Ordinal))
        {
            SecondaryText.Text = state.Secondary;
            _lastSecondary = state.Secondary;
        }
    }

    private void UpdateFontSizes()
    {
        var scaleX = ActualWidth / BaseWidth;
        var scaleY = ActualHeight / BaseHeight;
        var scale = Math.Max(0.6, Math.Min(3.0, Math.Min(scaleX, scaleY)));

        PrimaryText.FontSize = Math.Max(10, 15 * scale);
        SecondaryText.FontSize = Math.Max(13, 24 * scale);
    }

    private ContextMenu BuildContextMenu()
    {
        var menu = new ContextMenu();

        var openSettings = new MenuItem { Header = "打开设置" };
        openSettings.Click += (_, _) => _app.ShowSettings();
        menu.Items.Add(openSettings);

        var hideLesson = new MenuItem { Header = "隐藏课程窗口" };
        hideLesson.Click += (_, _) => _app.HideLesson();
        menu.Items.Add(hideLesson);

        menu.Items.Add(new Separator());

        var exit = new MenuItem { Header = "退出应用" };
        exit.Click += (_, _) => _app.ExitApplication();
        menu.Items.Add(exit);
        return menu;
    }

    private void Window_MouseLeftButtonDown(object sender, MouseButtonEventArgs e)
    {
        if (!_app.Config.Window.Locked && !_app.Config.Schedule.FollowWidget)
        {
            DragMove();
        }
    }

    private void Window_LocationChanged(object? sender, EventArgs e)
    {
        if (_app.Config.Schedule.FollowWidget)
        {
            return;
        }

        _app.Config.Schedule.Window.Left = Left;
        _app.Config.Schedule.Window.Top = Top;
        _app.RequestConfigSave();
    }

    private void Window_SizeChanged(object sender, SizeChangedEventArgs e)
    {
        UpdateFontSizes();

        if (_app.Config.Schedule.FollowWidget)
        {
            return;
        }

        _app.Config.Schedule.Window.Width = Width;
        _app.Config.Schedule.Window.Height = Height;
        _app.RequestConfigSave();
    }

    private void Window_Closing(object sender, CancelEventArgs e)
    {
        if (_app.IsExiting)
        {
            return;
        }

        e.Cancel = true;
        _app.HideLesson();
    }
}
