using Microsoft.Win32;

namespace DesktopCountdown.Services;

public sealed class AutoStartService
{
    private const string RunKeyPath = @"Software\Microsoft\Windows\CurrentVersion\Run";
    private const string AppName = "DesktopCountdown";

    public bool IsEnabled()
    {
        using var key = Registry.CurrentUser.OpenSubKey(RunKeyPath, false);
        return key?.GetValue(AppName) is string value && value.Contains(AppContext.BaseDirectory, StringComparison.OrdinalIgnoreCase);
    }

    public void SetEnabled(bool enabled)
    {
        using var key = Registry.CurrentUser.OpenSubKey(RunKeyPath, true)
            ?? Registry.CurrentUser.CreateSubKey(RunKeyPath, true);

        if (enabled)
        {
            var exePath = Environment.ProcessPath ?? string.Empty;
            key.SetValue(AppName, $"\"{exePath}\" --autostart");
        }
        else
        {
            key.DeleteValue(AppName, false);
        }
    }
}
