# DesktopCountdown 项目概览

> 🌿 本分支（`desktop`）是 `when_holiday` 的 Windows 桌面组件实现，项目文件就在分支根目录。
> 同仓库的 `main` 分支是 PHP 版，`cloudflare` 分支是 Cloudflare Pages 版。

## 构建与运行

```bash
dotnet build

# 单文件免安装发布
dotnet publish -c Release -r win-x64 --self-contained -p:PublishSingleFile=true
```

要求 .NET 8 SDK。产物在 `bin/Release/net8.0-windows/win-x64/publish/`。

## 核心功能

- **桌面悬浮倒计时** — 无边框透明小组件，置顶显示，支持拖拽移动与自由缩放
- **灵活时间配置** — 自定义标题、目标日期时间、结束文案；支持天/时/分/秒任意组合显示
- **视觉定制** — 液态玻璃（默认）、纯色、渐变、图片四种背景模式，透明度可调
- **系统级集成** — 托盘图标常驻、用户级开机自启、单实例保护、配置自动持久化
- **自适应渲染** — 字体大小随窗口缩放动态调整；等宽字体 + 增量绑定更新，秒级刷新无抖动

## 技术栈

| 层级 | 技术 |
|---|---|
| 框架 | .NET 8 + WPF + Windows Forms (NotifyIcon) |
| 架构 | MVVM + Service 分层 |
| 发布 | self-contained 单文件 EXE，无需安装运行时 |

## 关键技术点

- **透明悬浮窗** — `WindowStyle="None"` + `AllowsTransparency="True"`，自绘液态玻璃背景（渐变 + 阴影）
- **单实例机制** — 命名互斥体 `Local\DesktopCountdownSingleInstance`
- **开机自启** — 当前用户注册表 `HKCU\...\Run`，带 `--autostart` 参数静默启动
- **配置安全写入** — 临时文件 + `File.Move` 原子替换，损坏时自动备份恢复
- **刷新抖动消除** — `ObservableCollection` + `INotifyPropertyChanged` 增量更新，仅数值变化时不重建视觉树
- **字体自适应** — 以基准尺寸 300×150 计算缩放比例，限制在 0.6~3.0 倍之间
- **等宽字体占位** — 倒计时数字使用 Consolas，配合 `MinWidth` 确保秒级变化时布局稳定
