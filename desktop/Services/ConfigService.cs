using System.IO;
using System.Text.Json;
using DesktopCountdown.Models;

namespace DesktopCountdown.Services;

public sealed class ConfigService
{
    private readonly JsonSerializerOptions _jsonOptions = new()
    {
        WriteIndented = true,
        PropertyNameCaseInsensitive = true
    };

    public string ConfigPath { get; }

    public ConfigService()
    {
        ConfigPath = ResolveConfigPath();
    }

    public AppConfig Load()
    {
        AppConfig config;

        if (!File.Exists(ConfigPath))
        {
            config = new AppConfig();
        }
        else
        {
            try
            {
                var json = File.ReadAllText(ConfigPath);
                config = JsonSerializer.Deserialize<AppConfig>(json, _jsonOptions) ?? new AppConfig();
            }
            catch
            {
                TryBackupBrokenConfig();
                config = new AppConfig();
            }
        }

        // 手改 config.json 很容易写出显式 null / 越界数值，加载后统一兜底，避免启动即崩溃。
        config.Normalize();
        return config;
    }

    public void Save(AppConfig config)
    {
        Directory.CreateDirectory(Path.GetDirectoryName(ConfigPath)!);
        var json = JsonSerializer.Serialize(config, _jsonOptions);
        var tempPath = ConfigPath + ".tmp";
        File.WriteAllText(tempPath, json);
        File.Move(tempPath, ConfigPath, true);
    }

    /// <summary>
    /// 备份当前配置文件，返回备份路径（失败时返回 null）。
    /// </summary>
    public string? Backup(string tag)
    {
        if (!File.Exists(ConfigPath))
        {
            return null;
        }

        try
        {
            var backupPath = Path.Combine(
                Path.GetDirectoryName(ConfigPath)!,
                $"config.{tag}.{DateTime.Now:yyyyMMdd-HHmmss}.json");
            File.Copy(ConfigPath, backupPath, true);
            return backupPath;
        }
        catch
        {
            return null;
        }
    }

    /// <summary>
    /// 优先把配置放在程序目录（便于便携使用）；目录不可写时退回 AppData。
    /// <para>用 <see cref="FileOptions.DeleteOnClose"/> 做写入探针，文件句柄关闭即自动删除，
    /// 不会像过去那样在程序目录留下临时文件，也不会在异常退出时残留。</para>
    /// </summary>
    private static string ResolveConfigPath()
    {
        var appDirectory = AppContext.BaseDirectory;
        if (IsDirectoryWritable(appDirectory))
        {
            return Path.Combine(appDirectory, "config.json");
        }

        var appData = Environment.GetFolderPath(Environment.SpecialFolder.ApplicationData);
        return Path.Combine(appData, "DesktopCountdown", "config.json");
    }

    private static bool IsDirectoryWritable(string directory)
    {
        try
        {
            Directory.CreateDirectory(directory);
            var probe = Path.Combine(directory, $".write-test-{Guid.NewGuid():N}");
            using var stream = new FileStream(
                probe,
                FileMode.CreateNew,
                FileAccess.Write,
                FileShare.None,
                bufferSize: 1,
                FileOptions.DeleteOnClose);
            stream.WriteByte(0);
            return true;
        }
        catch
        {
            return false;
        }
    }

    private void TryBackupBrokenConfig()
    {
        Backup("broken");
    }
}
