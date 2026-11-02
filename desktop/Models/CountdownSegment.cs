using System.ComponentModel;
using System.Runtime.CompilerServices;

namespace DesktopCountdown.Models;

public sealed class CountdownSegment : INotifyPropertyChanged
{
    private string _value = "";
    private string _unit = "";

    public string Value
    {
        get => _value;
        set
        {
            if (_value != value)
            {
                _value = value;
                OnPropertyChanged();
            }
        }
    }

    public string Unit
    {
        get => _unit;
        set
        {
            if (_unit != value)
            {
                _unit = value;
                OnPropertyChanged();
            }
        }
    }

    public CountdownSegment(string value, string unit)
    {
        _value = value;
        _unit = unit;
    }

    public event PropertyChangedEventHandler? PropertyChanged;

    private void OnPropertyChanged([CallerMemberName] string propertyName = "")
    {
        PropertyChanged?.Invoke(this, new PropertyChangedEventArgs(propertyName));
    }
}
