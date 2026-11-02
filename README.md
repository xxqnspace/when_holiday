# when_holiday · 什么时候放假啊

一个**学期倒计时与进度追踪**项目。实时显示学期已过百分比、剩余天数、工作日倒计时，附带学期日历视图、公告栏和管理后台，可装成手机 PWA。另有 Windows 桌面悬浮小组件。

> 用于在枯燥的生活中给予我们前进的力量与希望。

## v4.0：Python + SQLite（当前主线）

`main` 分支根目录就是 **VPS 版**——把原先跑在 Cloudflare Pages + D1 上的后端，等价移植到
**Python 3 标准库 + 本地 SQLite**，零第三方依赖，一个进程就能跑完整站。

```bash
python3 app.py          # 默认监听 28417 端口
```

| 层面 | 技术 |
|------|------|
| 运行时 | Python 3.10+ 标准库（`ThreadingHTTPServer`），无第三方包 |
| 存储 | 本地 SQLite（WAL 模式），`data/holiday.db` |
| 前端 | 原生 HTML + CSS + JavaScript，静态直出（含 `nosniff`、目录穿越防护） |
| 认证 | PBKDF2-SHA256 **600000 次** + 会话表 + HttpOnly Cookie + CSRF |
| 部署 | systemd 常驻进程（`deploy/holiday.service`） |

### 相对 Cloudflare 版的差异

| 项目 | Cloudflare 版 | VPS 版 |
|------|---------------|--------|
| 运行时 | Pages Functions（Workers，CPU 10ms 上限） | 常驻 Python 进程 |
| 存储 | D1（远程 SQLite） | 本地 SQLite 文件 |
| 密码哈希 | PBKDF2-SHA256，3000 次（受免费版限制） | 同算法同格式，**600000 次**（实测 243ms） |
| 响应头 | Pages 的 `_headers` 文件 | 代码里等价实现 |
| og:image / og:url | 静态相对路径，需手改 | **按请求 Host 自动补全** |
| 静态服务 | CDN | 本进程直出 |

接口契约完全一致：路径、请求体字段、`{success,message,data}` 结构、
`holiday_session` / `holiday_csrf` Cookie、`X-CSRF-Token` 头、
PBKDF2 存储格式 `pbkdf2$<iter>$<salt>$<hash>` 都没变。

### 目录结构

```
/
├── app.py              # 全部后端 + 静态资源服务（单文件，零依赖）
├── static/             # 前端：index / admin / login / sw.js / manifest.json / icons
├── deploy/
│   ├── DEPLOY-VPS.md   # 部署、运维、备份、回滚说明
│   └── holiday.service # systemd 单元
├── data/               # 运行时生成（SQLite + 背景图缓存），已 gitignore
└── php/                # 旧 PHP 版（见下）
```

### 已知限制

1. **没有 HTTPS**：走 `http://IP:28417` 时密码明文传输，Cookie 不带 `Secure`。要安全需套域名 + TLS（Caddy / nginx + Let's Encrypt / Cloudflare Tunnel）。
2. **PWA 装不上**：非 HTTPS 下浏览器不注册 Service Worker，功能不受影响但无法「添加到主屏幕」。
3. 单进程 + SQLite，定位是个人使用。

完整部署与运维（端口、备份、回滚、磁盘占用、壁纸缓存机制）见 [`deploy/DEPLOY-VPS.md`](./deploy/DEPLOY-VPS.md)。

---

## 其他实现

| 位置 | 形态 | 技术栈 | 部署位置 |
|------|------|--------|----------|
| `php/`（本分支） | 网页 / PWA（旧版 v2.x） | PHP 7.4+ + JSON 文件存储 | 宝塔等传统主机 |
| [`cloudflare` 分支](https://github.com/xxqnspace/when_holiday/tree/cloudflare) | 网页 / PWA（v3.0） | Cloudflare Pages Functions + D1 | Cloudflare Pages |
| [`desktop` 分支](https://github.com/xxqnspace/when_holiday/tree/desktop) | Windows 桌面悬浮组件 | .NET 8 + WPF | 本机运行（单文件 EXE） |

### PHP 版（`php/`）

最轻量的一版，上传即用，不需要数据库，也不依赖 Cloudflare 账号。数据存在
`php/data/db.json.php`（伪装成 PHP 的 JSON，原子写入 + flock）。用宝塔部署时，
**站点根目录指向 `php/`**，并确保 `php/data/` 可写（755/775）。默认账号
`admin / admin123`，登录后请立即改密。

细节见 [`php/README.md`](./php/README.md)（保留自 PHP 版）。

切换分支：`git checkout cloudflare` / `git checkout desktop`。

---

## 更新日志

- **v4.0** — 后端从 Cloudflare Pages Functions + D1 移植为 **Python 3 标准库 + SQLite**，可部署在自己的 VPS；PBKDF2 迭代数从 3000 提到 600000；新增服务端每日壁纸缓存与壁纸信息公告；og 标签按请求 Host 自动补全；前端同步更新（首页、管理面板、登录页）；PHP 版下沉到 `php/`
- **v3.2** — 三个实现拆到分支：`cloudflare` / `desktop`，`main` 曾只保留 PHP 版
- **v3.1** — `holiday-cf` 与 `desktop-countdown` 并入本仓库
- **v3.0** — 迁移到 Cloudflare Pages + D1；后端由 PHP 重写为 Pages Functions；密码换 PBKDF2-SHA256；日历改前端渲染；管理后台拆分为 `admin.html` + `login.html`
- **v2.1** — 存储改为 JSON 文件（去 MySQL）；全站 CSRF 防护；日历大屏默认展开全部月份
