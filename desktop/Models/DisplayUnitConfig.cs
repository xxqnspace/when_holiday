using System.Text.Json.Serialization;

namespace DesktopCountdown.Models;

public sealed class DisplayUnitConfig
{
    public bool ShowDays { get; set; } = true;
    public bool ShowHours { get; set; } = true;
    public bool ShowMinutes { get; set; } = true;
    public bool ShowSeconds { get; set; } = true;
    public DayRounding DayRounding { get; set; } = DayRounding.Floor;
}

[JsonConverter(typeof(JsonStringEnumConverter<DayRounding>))]
public enum DayRounding
{
    Floor,
    Ceiling
}
