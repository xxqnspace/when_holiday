using System.Windows;
using System.Windows.Threading;
using DesktopCountdown.Models;
using DesktopCountdown.Services;
using Forms = System.Windows.Forms;

namespace DesktopCountdown;

public partial class App : System.Windows.Application
{
    /// <summary>拖动 / 缩放时配置落盘的防抖间隔。</summary>
    private static readonly TimeSpan SaveDebounceInterval = TimeSpan.FromMilliseconds(400);

    private SingleInstanceService? _singleInstance;
    private ConfigService _configService = null!;
    private AutoStartService _autoStartService = null!;
    private TrayService? _tray;
    private MainWindow? _settingsWindow;
    private WidgetWindow? _widgetWindow;
    private LessonWindow? _lessonWindow;
    private readonly DispatcherTimer _saveDebounce = new() { Interval = SaveDebounceInterval };
    private bool _saveErrorShown;

    public AppConfig Config { get; private set; } = new();
    public bool IsExiting { get; private set; }

    protected override void OnStartup(StartupEventArgs e)
    {
        base.OnStartup(e);

        ShutdownMode = ShutdownMode.OnExplicitShutdown;
        _singleInstance = new SingleInstanceService();
        if (!_singleInstance.IsFirstInstance)
        {
            Shutdown();
            return;
        }

        _configService = new ConfigService();
        _autoStartService = new AutoStartService();
        Config = _configService.Load();

        _saveDebounce.Tick += (_, _) =>
        {
            _saveDebounce.Stop();
            SaveConfig();
        };

        // 先建托盘，配置迁移过程中的异常提示才有地方显示。
        CreateTray();
        MigrateConfig(Config);
        Config.Behavior.AutoStart = _autoStartService.IsEnabled();
        UpdateTrayState();

        ShowWidget();

        var isAutoStart = e.Args.Any(arg => arg.Equals("--autostart", StringComparison.OrdinalIgnoreCase));
        if (Config.IsFirstRun || !isAutoStart)
        {
            ShowSettings();
        }
    }

    /// <summary>
    /// 配置升级：SchemaVersion 1 → 2 时，把旧的「液态玻璃」升级为真实的系统亚克力。
    /// 液态玻璃仍作为兼容性回退方案保留，可在设置中手动选择。
    /// </summary>
    private void MigrateConfig(AppConfig config)
    {
        // 配置比程序新（用户先跑过更高版本）时，本次运行会丢掉未知字段，
        // 因此先备份一份再继续，保证数据可恢复。
        if (config.SchemaVersion > AppConfig.CurrentSchemaVersion)
        {
            var backup = _configService.Backup("newer");
            _tray?.ShowError(
                $"配置文件由更高版本创建（SchemaVersion={config.SchemaVersion}），当前程序仅支持到 " +
                $"{AppConfig.CurrentSchemaVersion}。\n" +
                (backup is null ? "无法创建备份，请勿保存以免丢失配置。" : $"已自动备份到：\n{backup}"));
            return;
        }

        if (config.SchemaVersion >= AppConfig.CurrentSchemaVersion)
        {
            return;
        }

        if (config.Appearance.BackgroundMode == BackgroundMode.LiquidGlass)
        {
            config.Appearance.BackgroundMode = BackgroundMode.Acrylic;
        }

        config.SchemaVersion = AppConfig.CurrentSchemaVersion;
    }

    public void ShowSettings()
    {
        _settingsWindow ??= new MainWindow(this);
        _settingsWindow.ApplyConfigToControls();
        _settingsWindow.Show();
        _settingsWindow.Activate();
    }

    public void ShowWidget()
    {
        _widgetWindow ??= new WidgetWindow(this);
        _widgetWindow.ApplyConfig();
        if (Config.Window.Visible)
        {
            _widgetWindow.Show();
        }

        UpdateLessonWindowVisibility();
        SyncLessonWindowPosition();
    }

    public void HideWidget()
    {
        Config.Window.Visible = false;
        _widgetWindow?.Hide();
        _lessonWindow?.Hide();
        SaveConfig();
        UpdateTrayState();
    }

    public void ToggleWidgetVisibility()
    {
        Config.Window.Visible = !Config.Window.Visible;
        if (Config.Window.Visible)
        {
            ShowWidget();
        }
        else
        {
            _widgetWindow?.Hide();
            _lessonWindow?.Hide();
        }

        SaveConfig();
        UpdateTrayState();
    }

    /// <summary>隐藏课程提示窗口（关闭开关）。</summary>
    public void HideLesson()
    {
        Config.Schedule.Enabled = false;
        _lessonWindow?.Hide();
        SaveConfig();
        UpdateTrayState();
    }

    /// <summary>切换课程提示窗口显示状态。</summary>
    public void ToggleLesson()
    {
        Config.Schedule.Enabled = !Config.Schedule.Enabled;
        UpdateLessonWindowVisibility();
        SaveConfig();
        UpdateTrayState();
    }

    /// <summary>按配置显示或隐藏课程窗口（与悬浮窗可见性联动）。</summary>
    public void UpdateLessonWindowVisibility()
    {
        if (!Config.Schedule.Enabled || !Config.Window.Visible)
        {
            _lessonWindow?.Hide();
            return;
        }

        _lessonWindow ??= new LessonWindow(this);
        _lessonWindow.ApplyConfig();
        _lessonWindow.Show();
        SyncLessonWindowPosition();
    }

    /// <summary>吸附模式下让课程窗口跟随倒计时窗口的位置与宽度。</summary>
    public void SyncLessonWindowPosition()
    {
        if (_lessonWindow is null || _widgetWindow is null)
        {
            return;
        }

        if (!Config.Schedule.Enabled || !Config.Schedule.FollowWidget)
        {
            return;
        }

        if (!_lessonWindow.IsVisible || !_widgetWindow.IsVisible)
        {
            return;
        }

        _lessonWindow.FollowTo(_widgetWindow);
    }

    /// <summary>
    /// 应用配置变更。
    /// <para>背景模式切换不再需要重建窗口 —— 所有模式都使用 layered 窗口
    /// （<c>AllowsTransparency = true</c>，不可运行时修改），模式差异只体现在画刷与
    /// 是否开启 accent 模糊上，两者都能在运行时改。</para>
    /// </summary>
    public void ApplyConfigChanges()
    {
        Config.IsFirstRun = false;
        _widgetWindow?.ApplyConfig();
        // The lesson window is a separate layered window and must be refreshed
        // explicitly when it is already open. Visibility updates alone can
        // leave its old brush and corner settings on screen.
        _lessonWindow?.ApplyConfig();
        UpdateLessonWindowVisibility();
        SyncLessonWindowPosition();
        SaveConfig();
        UpdateTrayState();
    }

    public void SetAutoStart(bool enabled)
    {
        _autoStartService.SetEnabled(enabled);
        Config.Behavior.AutoStart = enabled;
        SaveConfig();
        UpdateTrayState();
    }

    /// <summary>
    /// 请求保存配置（防抖）。
    /// <para>拖动 / 缩放窗口时 <c>LocationChanged</c>、<c>SizeChanged</c> 会以鼠标频率触发，
    /// 每次都同步落盘会造成上百次/秒的磁盘写入，因此这里合并成一次延迟写入。</para>
    /// </summary>
    public void RequestConfigSave()
    {
        _saveDebounce.Stop();
        _saveDebounce.Start();
    }

    /// <summary>立即把挂起的防抖保存落盘。</summary>
    public void FlushPendingSave()
    {
        if (_saveDebounce.IsEnabled)
        {
            _saveDebounce.Stop();
            SaveConfig();
        }
    }

    public void SaveConfig()
    {
        try
        {
            _configService.Save(Config);
            _saveErrorShown = false;
        }
        catch (Exception ex)
        {
            // 目录只读或被杀软锁定时会持续失败，这里只提示一次，避免模态框连弹。
            if (_saveErrorShown)
            {
                return;
            }

            _saveErrorShown = true;
            Forms.MessageBox.Show($"配置保存失败：{ex.Message}", "Desktop Countdown", Forms.MessageBoxButtons.OK, Forms.MessageBoxIcon.Warning);
        }
    }

    public void ExitApplication()
    {
        IsExiting = true;
        if (_widgetWindow is not null)
        {
            Config.Window.Left = _widgetWindow.Left;
            Config.Window.Top = _widgetWindow.Top;
            Config.Window.Width = _widgetWindow.Width;
            Config.Window.Height = _widgetWindow.Height;
        }

        if (_lessonWindow is not null && !Config.Schedule.FollowWidget)
        {
            Config.Schedule.Window.Left = _lessonWindow.Left;
            Config.Schedule.Window.Top = _lessonWindow.Top;
            Config.Schedule.Window.Width = _lessonWindow.Width;
            Config.Schedule.Window.Height = _lessonWindow.Height;
        }

        _saveDebounce.Stop();
        SaveConfig();
        _tray?.Dispose();
        _tray = null;
        Shutdown();
    }

    protected override void OnExit(ExitEventArgs e)
    {
        FlushPendingSave();
        _tray?.Dispose();
        _tray = null;
        _singleInstance?.Dispose();
        base.OnExit(e);
    }

    private void CreateTray()
    {
        _tray = new TrayService();

        _tray.ShowSettingsRequested += () => Dispatcher.Invoke(ShowSettings);
        _tray.ToggleWidgetRequested += () => Dispatcher.Invoke(ToggleWidgetVisibility);
        _tray.ToggleLessonRequested += () => Dispatcher.Invoke(ToggleLesson);
        _tray.SyncRequested += () => Dispatcher.Invoke(UpdateTrayState);

        _tray.TopmostChanged += value => Dispatcher.Invoke(() =>
        {
            Config.Window.Topmost = value;
            ApplyConfigChanges();
        });

        _tray.LockedChanged += value => Dispatcher.Invoke(() =>
        {
            Config.Window.Locked = value;
            ApplyConfigChanges();
        });

        _tray.AutoStartChanged += value => Dispatcher.Invoke(() =>
        {
            try
            {
                SetAutoStart(value);
            }
            catch (Exception ex)
            {
                _tray?.RevertAutoStart(Config.Behavior.AutoStart);
                _tray?.ShowError($"开机自启设置失败：{ex.Message}");
            }
        });

        _tray.ExitRequested += () => Dispatcher.Invoke(ExitApplication);

        UpdateTrayState();
    }

    /// <summary>同步托盘菜单的文本与勾选状态（不重建菜单控件）。</summary>
    private void UpdateTrayState()
    {
        _tray?.UpdateState(
            Config.Window.Visible,
            Config.Schedule.Enabled,
            Config.Window.Topmost,
            Config.Window.Locked,
            Config.Behavior.AutoStart);
    }
}
