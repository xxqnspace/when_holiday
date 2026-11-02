# DesktopCountdown 项目概览

## 核心功能

- **桌面悬浮倒计时** — 无边框透明小组件，置顶显示，支持拖拽移动与自由缩放
- **灵活时间配置** — 自定义标题、目标日期时间、结束文案；支持天/时/分/秒任意组合显示
- **视觉定制** — 系统亚克力（默认）、液态玻璃、纯色、渐变、图片五种背景模式，透明度可调
- **课程提示窗口** — 悬浮窗下方的第二个小窗口：上课中显示「当前是第X节课 / 距下课还有XX分钟」，课间显示「下一节是第X节课 / 距上课还有X分钟」；作息表可在设置中增删改
- **系统级集成** — 托盘图标常驻、用户级开机自启、单实例保护、配置自动持久化
- **自适应渲染** — 字体大小随窗口缩放动态调整；等宽字体 + 增量绑定更新，秒级刷新无抖动

## 技术栈

| 层级 | 技术 |
|---|---|
| 框架 | .NET 8 + WPF + Windows Forms (NotifyIcon) |
| 架构 | MVVM + Service 分层 |
| 发布 | self-contained 单文件 EXE，无需安装运行时 |

## 关键技术点

- **真实 Win11 亚克力** — `DwmSetWindowAttribute(DWMWA_SYSTEMBACKDROP_TYPE=38)` 启用系统级模糊，并用 `DWMWA_WINDOW_CORNER_PREFERENCE` 取得系统圆角；窗口必须为普通窗口（`AllowsTransparency=false` + `Background=null`），所以在「系统亚克力 / 其他模式」间切换时会自动重建窗口；Windows 10 回退到 `SetWindowCompositionAttribute` accent 模糊，两者都失败时回退自绘背景，避免出现黑色窗口
- **透明悬浮窗** — 非亚克力模式下使用 `WindowStyle="None"` + `AllowsTransparency="True"`，自绘液态玻璃背景（渐变 + 阴影）
- **课程状态计算** — 节次按开始时间排序后逐级判断：上课中 / 课间 / 课前 / 放学；剩余分钟向上取整，不足 1 分钟单独提示，超过 1 小时拆分为「X 小时 Y 分钟」
- **窗口吸附** — 课程窗口默认跟随倒计时窗口的左边缘与宽度、置于其下方 8px；关闭吸附后可独立拖拽并单独记忆位置
- **单实例机制** — 命名互斥体 `Local\DesktopCountdownSingleInstance`
- **开机自启** — 当前用户注册表 `HKCU\...\Run`，带 `--autostart` 参数静默启动
- **配置安全写入** — 临时文件 + `File.Move` 原子替换，损坏时自动备份恢复
- **刷新抖动消除** — `ObservableCollection` + `INotifyPropertyChanged` 增量更新，仅数值变化时不重建视觉树
- **字体自适应** — 以基准尺寸 300×150 计算缩放比例，限制在 0.6~3.0 倍之间
- **等宽字体占位** — 倒计时数字使用 Consolas，配合 `MinWidth` 确保秒级变化时布局稳定

## 目录结构

| 文件 | 说明 |
|---|---|
| `App.xaml.cs` | 生命周期编排、托盘菜单、单实例、悬浮窗与课程窗口调度、配置迁移 |
| `WidgetWindow.xaml(.cs)` | 倒计时悬浮窗 |
| `LessonWindow.xaml(.cs)` | 课程提示窗口 |
| `MainWindow.xaml(.cs)` | 设置窗口（倒计时 / 显示单位 / 外观 / 课程提示 / 行为） |
| `Models/` | `AppConfig`、`CountdownConfig`、`DisplayUnitConfig`、`AppearanceConfig`、`ScheduleConfig`、`LessonPeriod`、`WindowStateConfig`、`BehaviorConfig`、`CountdownSegment` |
| `Services/` | `ConfigService`、`CountdownFormatter`、`ScheduleService`、`AutoStartService`、`SingleInstanceService` |
| `Helpers/` | `WindowBackdropHelper`（DWM 亚克力）、`AppearanceBrushFactory`、`ColorHelper`、`VisualTreeHelperExtensions` |

## 变更记录

### 2026-09-04　真实 Win11 亚克力 + 课程提示窗口

| 类型 | 内容 | 原因 | 影响 | 验证方法 |
|---|---|---|---|---|
| 新增 | `Helpers/WindowBackdropHelper.cs`（DWM 系统背景封装） | 原「液态玻璃」只是模拟渐变，不是真实亚克力 | 两个悬浮窗可启用系统级模糊与系统圆角；Win10 自动回退 accent 模糊 | 检测窗口扩展样式 `WS_EX_LAYERED`：新版为 false（普通窗口，DWM 背景可生效），旧版为 true |
| 新增 | `Models/ScheduleConfig.cs`、`Services/ScheduleService.cs` | 需要课程节次数据与上课状态计算 | 配置新增 `Schedule` 节点，旧配置缺失时取默认作息 | 10 组时间点算法测试，覆盖课前 / 上课中 / 课间 / 午休 / 放学 / 空课表 / 自定义课名 |
| 新增 | `LessonWindow.xaml(.cs)` | 需求：倒计时窗口下方增加课程提示窗口 | 新增独立悬浮窗，默认吸附于倒计时窗口下方 8px 并同宽 | 窗口枚举：尺寸 300×104，位置 = 倒计时窗口 top + height + 8 |
| 修改 | `AppearanceConfig`、`AppConfig` | 增加亚克力模式与着色配置 | 新增枚举 `Acrylic` 与 `AcrylicTintColor`；`SchemaVersion` 升至 2，旧配置自动迁移 | 编译 0 错误 0 警告；旧配置加载后背景模式升级为 Acrylic |
| 修改 | `WidgetWindow.xaml.cs` | 支持非 layered 模式、切换模式时重建窗口 | 亚克力模式下关闭自绘阴影，改用系统圆角与 tint 着色 | 切换背景模式后窗口自动重建，无崩溃、无黑窗 |
| 修改 | `App.xaml.cs` | 课程窗口生命周期、窗口重建、位置同步、托盘开关 | 托盘新增「课程提示窗口」勾选项 | 隐藏悬浮窗时课程窗口同步隐藏；重新显示时位置自动跟随 |
| 修改 | `MainWindow.xaml(.cs)` | 新增课程提示设置区与亚克力着色输入 | 设置窗口高度调整为 800 | 设置值加载与保存往返一致；时间格式错误行自动忽略并提示 |

### 2026-09-04（第二次）　课程窗口微调：去掉时钟、默认黑字

| 类型 | 内容 | 原因 | 影响 | 验证方法 |
|---|---|---|---|---|
| 修改 | `LessonWindow.xaml(.cs)` 移除底部时钟行 | 需求：第二个窗口不需要显示当前时间 | 窗口只显示「当前是第X节课」+「距下课还有XX分钟」两行，默认高度 104→84 | 编译 0 错误 0 警告 |
| 修改 | `ScheduleConfig` 新增独立 `TextColor`（默认黑 `#FF000000`）与 `BackgroundTint`（默认浅白 `#CCFFFFFF`） | 需求：第二个窗口默认黑色文字 | 课程窗口文字与倒计时窗口解耦；亚克力下用浅色着色保证黑字可读 | 设置界面新增「文字颜色 / 背景着色」输入行，加载保存往返一致 |
