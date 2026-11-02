using System.IO;
using System.Windows;
using Forms = System.Windows.Forms;

namespace DesktopCountdown.Services;

/// <summary>
/// 托盘图标与右键菜单。
/// <para>菜单只创建一次，状态变化时仅同步文本与勾选状态。
/// 这样既不会像过去那样每次刷新都泄漏一个 <see cref="Forms.ContextMenuStrip"/>，
/// 也不会出现「在菜单项事件回调里把菜单本身替换掉」的未定义行为。</para>
/// </summary>
public sealed class TrayService : IDisposable
{
    private readonly Forms.NotifyIcon _notifyIcon;
    private readonly Forms.ContextMenuStrip _menu;
    private readonly Forms.ToolStripMenuItem _toggleWidgetItem;
    private readonly Forms.ToolStripMenuItem _lessonItem;
    private readonly Forms.ToolStripMenuItem _topmostItem;
    private readonly Forms.ToolStripMenuItem _lockedItem;
    private readonly Forms.ToolStripMenuItem _autoStartItem;

    private System.Drawing.Icon? _icon;
    private Stream? _iconStream;
    private bool _syncing;
    private bool _disposed;

    public event Action? ToggleWidgetRequested;
    public event Action? ShowSettingsRequested;
    public event Action? ToggleLessonRequested;
    public event Action<bool>? TopmostChanged;
    public event Action<bool>? LockedChanged;
    public event Action<bool>? AutoStartChanged;
    public event Action? ExitRequested;

    public TrayService()
    {
        _icon = LoadIcon();
        _notifyIcon = new Forms.NotifyIcon
        {
            Text = "桌面倒计时",
            Icon = _icon ?? System.Drawing.SystemIcons.Application,
            Visible = true
        };
        _notifyIcon.DoubleClick += (_, _) => ShowSettingsRequested?.Invoke();

        _menu = new Forms.ContextMenuStrip();
        // 每次弹出前同步一次，保证托盘勾选状态与配置一致。
        _menu.Opening += (_, _) => SyncRequested?.Invoke();

        _toggleWidgetItem = new Forms.ToolStripMenuItem("隐藏悬浮窗");
        _toggleWidgetItem.Click += (_, _) => ToggleWidgetRequested?.Invoke();
        _menu.Items.Add(_toggleWidgetItem);

        var openSettingsItem = new Forms.ToolStripMenuItem("打开设置");
        openSettingsItem.Click += (_, _) => ShowSettingsRequested?.Invoke();
        _menu.Items.Add(openSettingsItem);

        _lessonItem = new Forms.ToolStripMenuItem("课程提示窗口") { CheckOnClick = true };
        _lessonItem.CheckedChanged += (_, _) => RaiseIfUserAction(ToggleLessonRequested);
        _menu.Items.Add(_lessonItem);

        _topmostItem = new Forms.ToolStripMenuItem("始终置顶") { CheckOnClick = true };
        _topmostItem.CheckedChanged += (_, _) => RaiseIfUserAction(TopmostChanged, _topmostItem.Checked);
        _menu.Items.Add(_topmostItem);

        _lockedItem = new Forms.ToolStripMenuItem("锁定位置") { CheckOnClick = true };
        _lockedItem.CheckedChanged += (_, _) => RaiseIfUserAction(LockedChanged, _lockedItem.Checked);
        _menu.Items.Add(_lockedItem);

        _autoStartItem = new Forms.ToolStripMenuItem("开机自启") { CheckOnClick = true };
        _autoStartItem.CheckedChanged += (_, _) => RaiseIfUserAction(AutoStartChanged, _autoStartItem.Checked);
        _menu.Items.Add(_autoStartItem);

        _menu.Items.Add(new Forms.ToolStripSeparator());

        var exitItem = new Forms.ToolStripMenuItem("退出");
        exitItem.Click += (_, _) => ExitRequested?.Invoke();
        _menu.Items.Add(exitItem);

        _notifyIcon.ContextMenuStrip = _menu;
    }

    /// <summary>菜单即将弹出时触发，宿主应在此同步一次状态。</summary>
    public event Action? SyncRequested;

    /// <summary>同步菜单显示状态（仅改属性，不重建控件）。</summary>
    public void UpdateState(bool widgetVisible, bool lessonEnabled, bool topmost, bool locked, bool autoStart)
    {
        if (_disposed)
        {
            return;
        }

        _syncing = true;
        try
        {
            _toggleWidgetItem.Text = widgetVisible ? "隐藏悬浮窗" : "显示悬浮窗";
            _lessonItem.Checked = lessonEnabled;
            _lessonItem.Enabled = widgetVisible;
            _topmostItem.Checked = topmost;
            _lockedItem.Checked = locked;
            _autoStartItem.Checked = autoStart;
        }
        finally
        {
            _syncing = false;
        }
    }

    /// <summary>把某一项回退到指定状态（用于开机自启设置失败时）。</summary>
    public void RevertAutoStart(bool value)
    {
        _syncing = true;
        try
        {
            _autoStartItem.Checked = value;
        }
        finally
        {
            _syncing = false;
        }
    }

    public void ShowError(string message)
    {
        Forms.MessageBox.Show(message, "Desktop Countdown", Forms.MessageBoxButtons.OK, Forms.MessageBoxIcon.Warning);
    }

    private void RaiseIfUserAction(Action? handler)
    {
        if (_syncing)
        {
            return;
        }

        handler?.Invoke();
    }

    private void RaiseIfUserAction<T>(Action<T>? handler, T value)
    {
        if (_syncing)
        {
            return;
        }

        handler?.Invoke(value);
    }

    /// <summary>
    /// 优先从程序集资源读取多尺寸 .ico（图标质量最好）；失败时退回 EXE 自身嵌入的图标。
    /// 不再依赖发布目录下的 Assets 文件夹，因此发布后无需手工复制任何文件。
    /// </summary>
    private System.Drawing.Icon? LoadIcon()
    {
        try
        {
            var uri = new Uri("pack://application:,,,/Assets/app.ico");
            var stream = System.Windows.Application.GetResourceStream(uri)?.Stream;
            if (stream is not null)
            {
                // Icon(Stream) 要求流在图标生命周期内保持打开，因此这里不能释放。
                _iconStream = stream;
                return new System.Drawing.Icon(stream);
            }
        }
        catch
        {
            // 忽略，继续尝试从 EXE 提取。
        }

        try
        {
            var exePath = Environment.ProcessPath;
            return string.IsNullOrEmpty(exePath) ? null : System.Drawing.Icon.ExtractAssociatedIcon(exePath);
        }
        catch
        {
            return null;
        }
    }

    public void Dispose()
    {
        if (_disposed)
        {
            return;
        }

        _disposed = true;
        _notifyIcon.Visible = false;
        _notifyIcon.Dispose();
        _menu.Dispose();

        // SystemIcons.Application 是共享图标，不能释放；只有自建图标需要释放。
        _icon?.Dispose();
        _icon = null;

        _iconStream?.Dispose();
        _iconStream = null;
    }
}
