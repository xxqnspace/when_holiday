using System.Collections.ObjectModel;
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

public partial class WidgetWindow : Window
{
    private readonly App _app;
    private readonly DispatcherTimer _timer = new() { Interval = TimeSpan.FromSeconds(1) };
    private bool _hasNotifiedEnd;
    private readonly ObservableCollection<CountdownSegment> _countdownSegments = new();
    private MenuItem? _topmostMenuItem;
    private MenuItem? _lockedMenuItem;

    public WidgetWindow(App app)
    {
        _app = app;

        InitializeComponent();

        // 所有背景模式都用 layered 窗口（XAML 里 AllowsTransparency=True）：
        // 可见形状完全由每像素 alpha 决定，因此圆角可以跟随配置任意设置、
        // 由 WPF 正常抗锯齿渲染，也不会出现「系统背景大于圆角卡片」的问题。
        // 亚克力模式额外让 DWM 在窗口背后画一层模糊（可在运行时开/关）。
        SourceInitialized += OnSourceInitialized;

        CountdownItems.ItemsSource = _countdownSegments;
        _timer.Tick += OnTimerTick;
        _timer.Start();

        ContextMenu = BuildContextMenu();
    }

    private const double BaseWidth = 300.0;
    private const double BaseHeight = 150.0;

    public void ApplyConfig()
    {
        var config = _app.Config;

        Width = Math.Max(config.Window.Width, MinWidth);
        Height = Math.Max(config.Window.Height, MinHeight);
        Left = config.Window.Left;
        Top = config.Window.Top;
        Topmost = config.Window.Topmost;

        TitleText.Text = config.Countdown.Title;
        TargetText.Text = config.Countdown.TargetDateTime.LocalDateTime.ToString("yyyy-MM-dd HH:mm:ss");
        FontFamily = new System.Windows.Media.FontFamily(config.Appearance.FontFamily);
        Foreground = ColorHelper.BrushFrom(config.Appearance.TextColor, System.Windows.Media.Brushes.White);

        var cornerRadius = config.Appearance.CornerRadius;
        RootBorder.CornerRadius = new CornerRadius(cornerRadius);
        RootBorder.BorderBrush = ResolveBorderBrush(config.Appearance);
        RootBorder.Background = AppearanceBrushFactory.Create(
            config.Appearance,
            WindowBackdropHelper.ShouldUseAcrylicBlur(config),
            config.Appearance.Opacity);
        ApplyAeroLayers(config.Appearance, cornerRadius);
        // A shadow effect expands the layered window's bounding rectangle and
        // produces the stray rectangular halo visible behind the rounded card.
        // The glass edge and border below provide the separation without an
        // un-clipped effect.
        RootBorder.Effect = null;

        // layered 窗口可以整体半透明，直接用用户设置的透明度。
        Opacity = config.Appearance.Opacity;

        UpdateAcrylicBlur();
        RefreshCountdown();
        UpdateFontSizes();
        RefreshContextMenuState();
    }

    /// <summary>
    /// 边框画刷：开启 Aero 质感时用「上亮下暗」的渐变模拟玻璃边缘受光，
    /// 否则退回用户指定的纯色边框。
    /// </summary>
    private static System.Windows.Media.Brush ResolveBorderBrush(AppearanceConfig appearance)
    {
        if (!appearance.BorderEnabled)
        {
            return System.Windows.Media.Brushes.Transparent;
        }

        return appearance.AeroGlassEffect
            ? AppearanceBrushFactory.CreateAeroGlowBorder(appearance.AeroGlassIntensity)
            : ColorHelper.BrushFrom(appearance.BorderColor, System.Windows.Media.Brushes.White);
    }

    /// <summary>应用经典 Aero 玻璃质感（顶部高光 + 斜向反射）。关闭时清空叠加层。</summary>
    private void ApplyAeroLayers(AppearanceConfig appearance, double cornerRadius)
    {
        // 叠加层用与 RootBorder 完全相同的圆角：Border 不会裁剪子元素，
        // 半径稍小就会在四角露出 1px 的边。
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

    private void OnSourceInitialized(object? sender, EventArgs e)
    {
        UpdateAcrylicBlur();
    }

    /// <summary>
    /// 按当前配置开/关窗口背后的亚克力模糊。
    /// <para>必须显式关闭：从亚克力模式切到其他模式时，accent 策略不会自己消失。
    /// 窗口在 <c>Show()</c> 之前没有句柄，所以 <see cref="ApplyConfig"/> 里的这次调用
    /// 在首次显示前是空操作，真正生效靠 <c>SourceInitialized</c>。</para>
    /// </summary>
    private void UpdateAcrylicBlur()
    {
        // AllowsTransparency creates a layered window. DWM Acrylic is painted
        // to the layered window's rectangular bounds, which is the source of
        // the extra rectangle behind the card. The card now uses the clipped
        // WPF glass brush for every mode, so explicitly clear any old accent.
        WindowBackdropHelper.DisableAcrylicBlur(this);
    }

    private void OnTimerTick(object? sender, EventArgs e)
    {
        RefreshCountdown();
    }

    protected override void OnClosed(EventArgs e)
    {
        // DispatcherTimer 会被 Dispatcher 强引用，不停止会导致已关闭的窗口永久驻留内存。
        _timer.Stop();
        _timer.Tick -= OnTimerTick;
        base.OnClosed(e);
    }

    private void RefreshCountdown()
    {
        var config = _app.Config;
        var remaining = config.Countdown.TargetDateTime - DateTimeOffset.Now;
        if (remaining <= TimeSpan.Zero)
        {
            UpdateSegments(new[] { new CountdownSegment("0", config.Countdown.EndText) });
            if (!_hasNotifiedEnd && config.Countdown.NotifyOnEnd)
            {
                _hasNotifiedEnd = true;
                System.Windows.Forms.MessageBox.Show(config.Countdown.EndText, "倒计时结束");
            }
        }
        else
        {
            _hasNotifiedEnd = false;
            UpdateSegments(CountdownFormatter.Format(config.Countdown.TargetDateTime, config.DisplayUnits));
        }
    }

    private void UpdateSegments(IReadOnlyList<CountdownSegment> newSegments)
    {
        if (newSegments.Count != _countdownSegments.Count)
        {
            ReplaceSegments(newSegments);
            return;
        }

        for (int i = 0; i < newSegments.Count; i++)
        {
            if (_countdownSegments[i].Unit != newSegments[i].Unit)
            {
                ReplaceSegments(newSegments);
                return;
            }
        }

        for (int i = 0; i < newSegments.Count; i++)
        {
            _countdownSegments[i].Value = newSegments[i].Value;
        }
    }

    private void ReplaceSegments(IReadOnlyList<CountdownSegment> newSegments)
    {
        _countdownSegments.Clear();
        foreach (var seg in newSegments)
        {
            _countdownSegments.Add(seg);
        }

        Dispatcher.BeginInvoke(UpdateFontSizes, DispatcherPriority.Render);
    }

    private ContextMenu BuildContextMenu()
    {
        var menu = new ContextMenu();

        var openSettings = new MenuItem { Header = "打开设置" };
        openSettings.Click += (_, _) => _app.ShowSettings();
        menu.Items.Add(openSettings);

        var hide = new MenuItem { Header = "隐藏悬浮窗" };
        hide.Click += (_, _) => _app.HideWidget();
        menu.Items.Add(hide);

        var topmost = new MenuItem { Header = "始终置顶", IsCheckable = true };
        topmost.Click += (_, _) =>
        {
            _app.Config.Window.Topmost = topmost.IsChecked;
            ApplyConfig();
            _app.SaveConfig();
        };
        _topmostMenuItem = topmost;
        menu.Items.Add(topmost);

        var locked = new MenuItem { Header = "锁定位置", IsCheckable = true };
        locked.Click += (_, _) =>
        {
            _app.Config.Window.Locked = locked.IsChecked;
            _app.SaveConfig();
        };
        _lockedMenuItem = locked;
        menu.Items.Add(locked);

        menu.Items.Add(new Separator());
        var exit = new MenuItem { Header = "退出应用" };
        exit.Click += (_, _) => _app.ExitApplication();
        menu.Items.Add(exit);

        // 弹出前按当前配置刷新勾选状态，避免与托盘菜单的状态不同步。
        menu.Opened += (_, _) => RefreshContextMenuState();
        return menu;
    }

    /// <summary>把右键菜单的勾选状态与配置对齐。</summary>
    private void RefreshContextMenuState()
    {
        if (_topmostMenuItem is not null)
        {
            _topmostMenuItem.IsChecked = _app.Config.Window.Topmost;
        }

        if (_lockedMenuItem is not null)
        {
            _lockedMenuItem.IsChecked = _app.Config.Window.Locked;
        }
    }

    private void Window_MouseLeftButtonDown(object sender, MouseButtonEventArgs e)
    {
        if (!_app.Config.Window.Locked)
        {
            DragMove();
        }
    }

    private void Window_LocationChanged(object? sender, EventArgs e)
    {
        _app.Config.Window.Left = Left;
        _app.Config.Window.Top = Top;
        _app.SyncLessonWindowPosition();
        _app.RequestConfigSave();
    }

    private void Window_SizeChanged(object sender, SizeChangedEventArgs e)
    {
        _app.Config.Window.Width = Width;
        _app.Config.Window.Height = Height;
        _app.SyncLessonWindowPosition();
        _app.RequestConfigSave();
        UpdateFontSizes();
    }

    private void UpdateFontSizes()
    {
        double scaleX = ActualWidth / BaseWidth;
        double scaleY = ActualHeight / BaseHeight;
        double scale = Math.Min(scaleX, scaleY);
        scale = Math.Max(scale, 0.6);
        scale = Math.Min(scale, 3.0);

        TitleText.FontSize = Math.Max(10, 15 * scale);
        TargetText.FontSize = Math.Max(8, 12 * scale);

        foreach (var tb in VisualTreeHelperExtensions.FindVisualChildren<TextBlock>(CountdownItems))
        {
            // 用 Tag 区分「数值」与「单位」，不再依赖 FontWeight（改样式就会失效）。
            tb.FontSize = tb.Tag as string == "value"
                ? Math.Max(16, 32 * scale)
                : Math.Max(8, 12 * scale);
        }
    }

    private void Window_Closing(object sender, CancelEventArgs e)
    {
        if (_app.IsExiting)
        {
            return;
        }

        e.Cancel = true;
        _app.HideWidget();
    }
}
