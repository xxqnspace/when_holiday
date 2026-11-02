# when_holiday · 什么时候放假啊

一个**学期倒计时与进度追踪**项目。同一套产品有三个实现，分别面向不同场景，统一收在本仓库里：

| 目录 | 形态 | 技术栈 | 部署位置 |
|------|------|--------|----------|
| 仓库根目录 | 网页 / PWA | PHP 7.4+ + JSON 文件存储 | 宝塔等传统主机 |
| [`cloudflare/`](./cloudflare/README.md) | 网页 / PWA（v3.0 主线） | Cloudflare Pages Functions + D1 | Cloudflare Pages |
| [`desktop/`](./desktop/README.md) | Windows 桌面悬浮组件 | .NET 8 + WPF | 本机运行（单文件 EXE） |

网页版的两个实现功能一致：实时显示学期已过百分比、剩余天数、工作日倒计时，附带学期日历视图和管理后台。桌面版则是常驻桌面的无边框倒计时小组件。

> 用于在枯燥的生活中给予我们前进的力量与希望。

---

## 一、PHP 版（仓库根目录）

最轻量的一版，上传即用，不需要数据库，也不依赖 Cloudflare 账号。

### 技术栈

| 层面 | 技术 |
|------|------|
| 后端 | PHP（纯原生，无框架） |
| 存储 | JSON 文件（`data/db.json.php`，无需数据库） |
| 前端 | 原生 HTML + CSS + JavaScript + Font Awesome（国内 CDN） |
| PWA | Service Worker + Manifest |
| 部署 | 可依托宝塔面板（无需配置数据库） |

### 文件说明

```
/
├── index.php          # 首页（倒计时 + 日历展示）
├── admin.php          # 管理面板（登录 + 后台管理）
├── api.php            # JSON API 接口
├── config.php         # 默认配置（兜底值）
├── countdown.php      # 数据处理模块（JSON → 前端配置转换）
├── date.php           # 日历生成模块
├── storage.php        # JSON 文件存储层（原子写入 + flock）
├── data/              # 数据目录（自动创建）
│   ├── .htaccess      # 禁止 Web 直接访问（Apache）
│   └── db.json.php    # 数据文件（运行时生成，伪装为 PHP，不入库）
├── manifest.json      # PWA 清单
├── sw.js              # Service Worker（离线缓存策略）
├── icons/             # PWA 图标（72×72 至 512×512）
├── cloudflare/        # Cloudflare Pages + D1 实现（不参与 PHP 部署）
└── desktop/           # Windows 桌面组件（.NET 8 + WPF）
```

> ⚠️ 用宝塔部署时，站点根目录指向**本目录**，但 `cloudflare/` 与 `desktop/` 可以不用上传 —— 它们是另外两个实现，传上去也无害，只是 PHP 版用不到。

### 功能

- **学期进度条** — 精确到 6 位小数的百分比进度
- **总倒计时** — 学期结束的 天:时:分:秒 实时倒计时
- **工作日倒计时** — 排除周末和自定义假期的有效天数
- **学期日历** — 按月展示学期范围内的日历，标注已过日期、节假日、工作日、当天，含周次列；大屏默认展开全部月份，手机小屏默认只显示当前月份
- **公告栏** — 支持 Markdown，可配置展示窗口和自动关闭秒数
- **PWA 支持** — 安装至手机桌面，支持离线缓存静态资源
- **可定制 UI** — 主题色、字体色、背景色、进度条颜色均可在管理面板调整；支持纯色或图片背景（毛玻璃效果）
- **管理面板** — 用户管理、学期配置、界面配色、背景设置、公告管理、修改密码

### 部署

1. 将 PHP 相关文件上传到网站根目录（PHP ≥ 7.4 即可，无需 MySQL）
2. 首次访问自动创建 `data/db.json.php`，请确保 `data/` 目录有写权限（755/775）
3. 默认管理员账号：`admin / admin123`，**登录后请立即修改密码**
4. 可选：如使用 Nginx 且希望更保险，可额外在站点配置加 `location ~ ^/data/ { deny all; }`（非必需）

### 数据存储说明

所有数据（用户、配置、公告）保存在 `data/db.json.php`，首次访问自动创建：

- **文件伪装**：数据文件首行含 `<?php exit; ?>`，浏览器直接访问 URL 只得到空白页
- **原子写入**：临时文件 + rename，防止写一半损坏
- **并发安全**：flock 文件锁，读-改-写一体
- **自动迁移**：兼容旧版 `data/db.json` 纯 JSON 格式

> ⚠️ `data/db.json.php` 含用户密码哈希等敏感信息，已通过 `.gitignore` 排除，不会提交到仓库。

---

## 二、Cloudflare 版（`cloudflare/`）

v3.0 起的主线：后端由 PHP 重写为 Pages Functions，存储换成 Cloudflare D1（SQLite），前端逻辑基本沿用 PHP 版。

```bash
cd cloudflare
npm install
npx wrangler d1 migrations apply holiday --local
npx wrangler pages dev
```

走 Git 集成部署时，**Root directory 必须填 `cloudflare`**，Build output directory 填 `public`。建库、绑定 `DB`、首次登录的完整步骤见 [`cloudflare/README.md`](./cloudflare/README.md)。

---

## 三、桌面组件（`desktop/`）

.NET 8 + WPF 的 Windows 桌面悬浮倒计时，无边框透明、置顶、可拖拽缩放，带托盘常驻与开机自启，发布为 self-contained 单文件 EXE。

```bash
cd desktop
dotnet build
dotnet publish -c Release -r win-x64 --self-contained -p:PublishSingleFile=true
```

细节见 [`desktop/README.md`](./desktop/README.md)。

---

## 更新日志

- **v3.1** — 仓库整合：`holiday-cf`（Cloudflare 版）与 `desktop-countdown`（桌面组件）并入本仓库的 `cloudflare/` 与 `desktop/` 目录，三个实现统一维护
- **v3.0** — 迁移到 Cloudflare Pages + D1；后端由 PHP 重写为 Pages Functions；密码算法换为 PBKDF2-SHA256；会话改存 D1；日历由服务端渲染改为前端渲染；管理后台拆分为 `admin.html` + `login.html`；修复「编辑公告」实际会新增公告的问题
- **v2.1** — 存储改为 JSON 文件（去 MySQL）；全站 CSRF 防护；管理界面图标换 Font Awesome（国内 CDN）；日历大屏默认展开全部月份；修复公告列表脱离 Tab、空时间保存为 `:00` 等 Bug
