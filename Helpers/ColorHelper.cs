namespace DesktopCountdown.Helpers;

public static class ColorHelper
{
    public static System.Windows.Media.SolidColorBrush BrushFrom(string? value, System.Windows.Media.Brush fallback)
    {
        return new System.Windows.Media.SolidColorBrush(ColorFrom(value, ((System.Windows.Media.SolidColorBrush)fallback).Color));
    }

    public static System.Windows.Media.Color ColorFrom(string? value, System.Windows.Media.Color fallback)
    {
        try
        {
            return (System.Windows.Media.Color)System.Windows.Media.ColorConverter.ConvertFromString(value);
        }
        catch
        {
            return fallback;
        }
    }
}
