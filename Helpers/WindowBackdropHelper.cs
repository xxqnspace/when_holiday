using System;
using System.Runtime.InteropServices;
using System.Windows;
using System.Windows.Interop;
using DesktopCountdown.Models;

namespace DesktopCountdown.Helpers;

/// <summary>
/// 亚克力模糊（Acrylic）：通过未公开的 <c>SetWindowCompositionAttribute</c>
/// 让 DWM 在窗口背后绘制「模糊 + 噪点」的背景。
///
/// <para><b>为什么不用 <c>DWMWA_SYSTEMBACKDROP_TYPE</c>：</b>
/// 系统背景是按**窗口矩形**绘制的，圆角由 DWM 固定（约 8px）且无法自定义，
/// 也不受窗口区域（<c>SetWindowRgn</c>）约束 —— 结果是自绘的圆角卡片之外
/// 总会露出一圈方角背景，而且用窗口区域去裁还会因为二值掩码把圆角切成锯齿。</para>
///
/// <para><b>本方案：</b>窗口是 layered 窗口（<c>AllowsTransparency = true</c>），
/// 可见形状完全由每像素 alpha 决定，所以圆角可以跟随配置任意设置、由 WPF 正常抗锯齿渲染，
/// 也不会再有「背景大于卡片」的问题。</para>
///
/// <para><b>代价：</b>这是 Windows 10 时代的材质（模糊 + 噪点），
/// 不如 Windows 11 系统级亚克力精致。</para>
/// </summary>
public static class WindowBackdropHelper
{
    private const int WCA_ACCENT_POLICY = 19;
    private const int ACCENT_DISABLED = 0;
    private const int ACCENT_ENABLE_ACRYLICBLURBEHIND = 4;

    /// <summary>Windows 10 及以上支持 accent 亚克力。</summary>
    public static bool IsSupported => Environment.OSVersion.Version.Major >= 10;

    /// <summary>当前配置是否需要亚克力模糊。</summary>
    public static bool ShouldUseAcrylicBlur(AppConfig config)
    {
        return IsSupported && config.Appearance.BackgroundMode == BackgroundMode.Acrylic;
    }

    [DllImport("user32.dll")]
    private static extern int SetWindowCompositionAttribute(IntPtr hwnd, ref WindowCompositionAttributeData data);

    /// <summary>
    /// 为窗口开启亚克力模糊。建议在 <c>SourceInitialized</c> 之后调用。
    /// </summary>
    /// <param name="window">目标窗口，必须是 layered 窗口（<c>AllowsTransparency = true</c>）。</param>
    /// <param name="tintColor">
    /// 亚克力的混合色（ARGB）。alpha 既不能是 0（实测完全看不到效果），
    /// 也不能是 255（全不透明，同样看不到模糊），这里夹到 1~254。
    /// </param>
    /// <returns>是否应用成功。失败时窗口只显示卡片自身的着色层，不影响主流程。</returns>
    public static bool ApplyAcrylicBlur(Window window, string? tintColor)
    {
        var hwnd = new WindowInteropHelper(window).Handle;
        if (hwnd == IntPtr.Zero)
        {
            return false;
        }

        var color = ColorHelper.ColorFrom(tintColor, System.Windows.Media.Color.FromArgb(0x66, 0x11, 0x18, 0x20));
        var alpha = (byte)Math.Clamp((int)color.A, 1, 254);
        var abgr = (alpha << 24) | (color.B << 16) | (color.G << 8) | color.R;
        return SetAccent(window, ACCENT_ENABLE_ACRYLICBLURBEHIND, abgr);
    }

    /// <summary>
    /// 关闭亚克力模糊（从亚克力模式切到其他背景模式时必须调用，否则模糊会残留）。
    /// </summary>
    public static void DisableAcrylicBlur(Window window)
    {
        SetAccent(window, ACCENT_DISABLED, 0);
    }

    private static bool SetAccent(Window window, int accentState, int gradientColor)
    {
        var hwnd = new WindowInteropHelper(window).Handle;
        if (hwnd == IntPtr.Zero)
        {
            return false;
        }

        var accent = new AccentPolicy
        {
            AccentState = accentState,
            // 2 为本项目沿用的取值；不需要系统再画任何边框（边框由卡片自绘）。
            AccentFlags = 2,
            GradientColor = gradientColor,
            AnimationId = 0
        };

        var size = Marshal.SizeOf<AccentPolicy>();
        IntPtr buffer = IntPtr.Zero;
        try
        {
            buffer = Marshal.AllocHGlobal(size);
            Marshal.StructureToPtr(accent, buffer, false);
            var data = new WindowCompositionAttributeData
            {
                Attribute = WCA_ACCENT_POLICY,
                Data = buffer,
                SizeOfData = size
            };
            return SetWindowCompositionAttribute(hwnd, ref data) == 0;
        }
        catch
        {
            return false;
        }
        finally
        {
            if (buffer != IntPtr.Zero)
            {
                Marshal.FreeHGlobal(buffer);
            }
        }
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct WindowCompositionAttributeData
    {
        public int Attribute;
        public IntPtr Data;
        public int SizeOfData;
    }

    [StructLayout(LayoutKind.Sequential)]
    private struct AccentPolicy
    {
        public int AccentState;
        public int AccentFlags;
        public int GradientColor;
        public int AnimationId;
    }
}
