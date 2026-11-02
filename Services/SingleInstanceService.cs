using System.Threading;

namespace DesktopCountdown.Services;

public sealed class SingleInstanceService : IDisposable
{
    private readonly Mutex _mutex;
    private bool _disposed;
    public bool IsFirstInstance { get; }

    public SingleInstanceService()
    {
        _mutex = new Mutex(true, @"Local\DesktopCountdownSingleInstance", out var createdNew);
        IsFirstInstance = createdNew;
    }

    public void Dispose()
    {
        if (_disposed)
        {
            return;
        }

        if (IsFirstInstance)
        {
            _mutex.ReleaseMutex();
        }

        _mutex.Dispose();
        _disposed = true;
    }
}
