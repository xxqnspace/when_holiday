# when_holiday · 什么时候放假啊

一个**学期倒计时与进度追踪**网页应用，可在手机桌面以 PWA 方式安装使用。实时显示学期已过百分比、剩余天数、工作日倒计时，并附带学期日历视图和管理后台。
用于在枯燥的生活中给予我们前进的力量与希望。

> **v3.2**：同步 VPS 版（`main`）v4.x 的能力 —— 服务端每日壁纸缓存（`/bg`）、
> 壁纸信息公告、后台壁纸状态面板；图片字节交给 **Cache API**，不落磁盘也不用新增绑定。
>
> **v3.0**：从 PHP + JSON 文件存储迁移到 **Cloudflare Pages + D1**。
> 原 PHP 版本保留在 `main` 分支下的 `php/`，可继续在宝塔等环境运行。
>
> 🌿 本分支（`cloudflare`）是 `when_holiday` 的 Cloudflare 版，**项目文件就在分支根目录**，
> `wrangler` 命令直接在仓库根目录执行。

---

## 技术栈

| 层面 | 技术 |
|------|------|
| 部署 | Cloudflare Pages（静态资源 + Functions） |
| 后端 | Pages Functions（Workers 运行时），纯原生 JS，无框架无依赖 |
| 存储 | Cloudflare D1（SQLite） |
| 图片缓存 | Cloudflare Cache API（`caches.default`），元数据在 D1 的 `bg_cache` 表 |
| 前端 | 原生 HTML + CSS + JavaScript + Font Awesome（国内 CDN） |
| 认证 | PBKDF2-SHA256 密码哈希 + D1 会话表 + HttpOnly Cookie + CSRF（double-submit cookie） |
| PWA | Service Worker + Manifest |

---

## 核心功能

### 首页（`public/index.html`）
- **学期进度条** — 精确到 6 位小数的百分比进度
- **总倒计时** — 学期结束的 天:时:分:秒 实时倒计时
- **工作日倒计时** — 排除周末和自定义假期的有效天数
- **学期日历** — 按月展示学期范围内的日历，标注：
  - 已过日期（浅绿）、节假日（浅蓝）、工作日（白色）、当天（高亮）、周次列
  - **大屏默认展开全部月份，手机小屏默认只显示当前月份**（自动响应式）
- **公告栏** — 支持 Markdown，可配置展示窗口和自动关闭秒数
- **每日壁纸信息** — 服务端随背景图一起抓 `title` / `copyright`，以顶部公告弹出；
  文案支持 `auto`（标题+版权）/ `title`（仅标题）/ `custom`（自定义模板），
  可配停留秒数与"每天一次 / 每次打开都弹"
- **PWA 支持** — 安装至手机桌面，支持离线缓存静态资源
- **可定制 UI** — 主题色、字体色、背景色、进度条颜色均可在管理面板调整；支持纯色或图片背景（毛玻璃效果）

### 每日壁纸（`/bg`，v3.2 新增）
- 浏览器不再直连第三方壁纸站，改由 `/bg` 出图：服务端每天回源一次，按版本号写入边缘缓存
- 图片类型按**文件头**嗅探（不信上游 `Content-Type`），过小/非图片直接拒收
- 上游当天还没换图时，保持现有版本并安排 30 分钟后重试（最多 8 次）
- 首次访问 / 缓存被驱逐时同步回源，其余情况走 `stale-while-revalidate`：
  先用昨天的图秒开，后台换好今天的图后前端轮询 `/api/bg_version` 自动替换
- `?v=<版本号>` 命中时下发 `max-age=86400, immutable`，老地址一律 `no-cache` + ETag 304

### 管理面板（`public/admin.html` + `public/login.html`）
- 登录页面（用户名/密码验证，D1 会话 + CSRF 防护）
- **用户管理**（管理员专属）— 创建/编辑/删除用户，分配角色
- **学期配置** — 设置起止时间、自定义假期、补班日
- **界面配色** — 自定义主题色、字体色、背景色等
- **背景设置** — 纯色或图片背景，支持毛玻璃透明度调节
- **每日壁纸公告**（v3.2）— 图片源地址、信息接口、弹出模式/频率/秒数、状态矩阵与效果预览、
  立即刷新背景 / 立即重抓信息
- **公告管理** — 创建/编辑/删除公告，控制展示时间范围
- **修改密码** — 所有用户可自行修改密码

---

## 项目结构

```
/（cloudflare 分支根目录）
├── public/                    # 静态资源（走 CDN，请求免费且不限量）
│   ├── index.html             # 首页（倒计时 + 日历，日历由前端 JS 渲染）
│   ├── admin.html             # 管理面板
│   ├── login.html             # 登录页
│   ├── _headers               # Pages 响应头配置
│   ├── sw.js                  # Service Worker
│   ├── manifest.json          # PWA 清单
│   └── icons/                 # PWA 图标（72×72 至 512×512）
├── functions/                 # Pages Functions（后端）
│   ├── _middleware.js         # 全局中间件：把 og:url / og:image 补成绝对地址
│   ├── bg.js                  # /bg 出图（每日壁纸）
│   ├── api/[[action]].js      # /api/* 统一入口
│   └── _lib/
│       ├── config.js          # 默认配置、白名单、配置值归一化
│       ├── util.js            # 时间格式化、JSON 响应、Cookie 工具
│       ├── db.js              # D1 数据访问层（含 bg_cache 元数据）
│       ├── auth.js            # 密码哈希、会话、CSRF
│       ├── bg.js              # 壁纸缓存：回源、校验、版本号、信息公告
│       └── handlers.js        # 各接口的具体实现
├── migrations/
│   ├── 0001_init.sql          # 建表 + 初始配置
│   └── 0002_bg_cache.sql      # v3.2：bg_cache 表 + 壁纸相关配置 + 老数据迁移
├── wrangler.toml.example      # Pages / D1 配置模板（用 CLI 部署时复制为 wrangler.toml）
└── package.json
```

### 接口一览（`/api/<action>`）

| 接口 | 方法 | 权限 | 说明 |
|------|------|------|------|
| `session` | GET | 公开 | 当前登录态 + CSRF token |
| `login` / `logout` | POST | 公开 | 登录 / 退出 |
| `change_password` | POST | 登录 | 修改自己的密码 |
| `get_users` / `create_user` / `update_user` / `delete_user` | GET / POST | 管理员 | 用户管理 |
| `get_settings` | GET | 公开 | 站点配置（附带 `bg_version`） |
| `save_settings` | POST | 管理员 | 保存配置（白名单 + 值归一化） |
| `get_mode` | GET | 公开 | 运行模式 |
| `bg_version` | GET | 公开 | 图片版本探针：`{version, date, refreshing, has_image}` |
| `bg_info` | GET | 公开 | 当日壁纸公告：`{enabled, content, seconds, mode, always, ...}` |
| `bg_status` | GET | 管理员 | 壁纸缓存状态 + 公告配置快照 |
| `refresh_bg` | POST | 管理员 | 强制回源换图 |
| `refresh_bg_info` | POST | 管理员 | 只重抓标题/版权 |
| `get_announcement` | GET | 公开 | 当前生效公告 |
| `get_announcements` / `save_announcement` / `delete_announcement` | GET / POST | 管理员 | 公告管理 |

`/bg` 不是 `/api` 下的接口，它由 `functions/bg.js` 单独处理，返回图片字节。

---

## 部署步骤

> ⚠️ **必须走 Git 集成，不能用控制台的「拖拽上传」。**
> Pages 的 Direct Upload 只处理静态文件，不会打包 `functions/` 目录 ——
> 拖上去的站点会没有任何后端接口。

### 方式一：Cloudflare 控制台（全程不需要命令行）

#### 第 1 步：代码位置

代码就是本分支（`cloudflare`）的根目录，**不需要再单独建仓库、也不需要拖拽上传文件**。
Pages 项目连接 `when_holiday` 仓库、选中 `cloudflare` 分支即可，Root directory 留空（用分支根目录）。

> 🚫 PHP 版在 `main` 分支，不在本分支，不会干扰 Pages 构建。

#### 第 2 步：创建 D1 数据库

控制台左侧 **Storage & Databases → D1 SQL Database** → **Create database**
→ 名字填 `holiday` → Create。

#### 第 3 步：在控制台执行建表 SQL

1. 点进刚创建的 `holiday` 数据库
2. 切到 **Console** 标签
3. 打开项目里的 `migrations/0001_init.sql`，**全选复制**
4. 粘贴到 Console 输入框，点 **Execute**
5. 再对 `migrations/0002_bg_cache.sql` 重复一次（壁纸缓存表 + 新配置项）

这一步同时会把你原来的学期配置（起止时间、假期、补班日、配色、背景）
写进数据库。

#### 第 4 步：创建 Pages 项目

**Workers & Pages → Create → Pages → Connect to Git** → 授权 GitHub
→ 选中 `holiday` 仓库。构建配置：

| 配置项 | 填写内容 |
|--------|----------|
| Production branch | `cloudflare` ← 别选 main，main 里没有 `functions/` |
| Framework preset | `None` |
| Root directory | 留空（默认即分支根目录） |
| Build command | 留空 |
| Build output directory | `public` |

点 **Save and Deploy**。首次构建会成功，但此时还没有绑定数据库，
接口会提示未绑定（页面本身能打开，属正常现象）。

#### 第 5 步：绑定 D1 数据库

进入刚创建的 Pages 项目 → **Settings → Bindings → Add → D1 database**：

| 字段 | 填写内容 |
|------|----------|
| Variable name | `DB` ← **必须完全一致，大小写敏感** |
| D1 database | 选择 `holiday` |

保存。

#### 第 6 步：重新部署，让绑定生效

**Deployments** 标签 → 找到最新一次部署 → 右侧 **⋯** → **Retry deployment**，
等状态变成 Success。

> 绑定不会自动应用到已经完成的部署，这一步不能跳过。

#### 第 7 步：首次访问

打开项目分配的 `*.pages.dev` 域名，用下面的账号登录并**立即修改密码**：

| 用户名 | 密码 |
|--------|------|
| `admin` | `admin123` |

#### 可选：调整参数

不设置也没关系，代码里有相同的默认值。如需修改，在
**Settings → Environment variables** 添加：

| 变量名 | 值 | 说明 |
|--------|-----|------|
| `PBKDF2_ITERATIONS` | `3000` | 密码哈希迭代数 |
| `SESSION_DAYS` | `30` | 登录状态保持天数 |

改完同样需要重新部署。

#### 从 v3.0 升级到 v3.2（已有部署）

**不需要新增任何绑定，也不需要改配置**，把新代码推上去、等 Pages 自动构建完成即可。

| 要做的事 | 说明 |
|----------|------|
| 重新部署 | 推送 `cloudflare` 分支后 Pages 会自动构建；也可以手动 Retry deployment |
| 迁移（可选） | 代码会在运行时**懒建** `bg_cache` 表并自动把旧的 `bg_image_url`（第三方地址）搬到 `bg_upstream_url`，所以不跑迁移也能用。想保持迁移记录一致，就在 D1 Console 里执行一次 `0002_bg_cache.sql` |
| 检查图片源 | 后台「背景设置 → 每日壁纸」里确认源地址。默认用 `wall.1472588.xyz/today.jpg`；如果你原来填的是别的第三方地址，迁移会沿用你的旧地址 |
| 首次访问会慢一点 | 当天第一次请求 `/bg` 需要回源拉图（几百毫秒到两三秒），之后整个边缘节点都命中缓存 |

> 💡 图片缓存是按**边缘节点**存的，不是全局一份。理论上每个 Cloudflare 机房当天各回源一次，
> 个人站点通常只命中一两个节点，可以忽略。缓存被驱逐后下一个访问者会触发一次同步回源，页面不会白屏。

---

### 方式二：Wrangler 命令行

需要本机有 Node.js，适合已经习惯命令行的场景。先切到本分支 `git checkout cloudflare`，
以下命令在仓库根目录执行。

```bash
npm install
npx wrangler login

# 1. 创建数据库（生产库 + 预览库）
npx wrangler d1 create holiday
npx wrangler d1 create holiday-preview
```

把两条命令输出的 `database_id` 填入 `wrangler.toml`
（先 `cp wrangler.toml.example wrangler.toml`，再取消 `[[d1_databases]]` 那一段的注释）。

```bash
# 2. 执行迁移
npx wrangler d1 migrations apply holiday --remote
npx wrangler d1 migrations apply holiday --local   # 本地开发用

# 3. 部署
npx wrangler pages deploy
```

首次部署时按提示创建 Pages 项目，绑定会随 `wrangler.toml` 一并生效。
之后的账号密码与「方式一」第 7 步相同。

---

## 本地开发

切到 `cloudflare` 分支后，在仓库根目录执行：

```bash
npx wrangler d1 migrations apply holiday --local
npx wrangler pages dev
```

默认监听 `http://localhost:8788`。

---

## 从 PHP 版迁移的注意事项

| 项目 | 说明 |
|------|------|
| **密码** | PHP 用的是 bcrypt（`$2y$...`），Workers 运行时不支持该算法，**密码无法迁移**。数据库初始化时会重新创建默认管理员，登录后请自行改密。 |
| **配置** | 原 `data/db.json.php` 里的学期起止时间、假期、补班日、配色、背景等已完整写入 `migrations/0001_init.sql`。 |
| **公告** | 原数据中公告为空，如有内容需在后台重新录入。 |
| **访问路径** | 由 `/web/holiday/index.php` 变为站点根路径，页面间链接改为 `index.html` / `admin.html` / `login.html`。 |
| **OG 标签** | `public/*.html` 里写的是相对路径，由 `functions/_middleware.js` 在响应时补成当前域名的绝对地址，换域名不用改代码。 |

---

## 与 VPS 版（`main`）的差异

两版接口契约完全一致（路径、请求体字段、`{success,message,data}` 结构、
`holiday_session` / `holiday_csrf` Cookie、`X-CSRF-Token` 头、
PBKDF2 存储格式 `pbkdf2$<iter>$<salt>$<hash>` 都没变），差别只在运行时能力：

| 项目 | Cloudflare 版（本分支） | VPS 版（`main`） |
|------|------------------------|------------------|
| 运行时 | Pages Functions，免费版每请求 10 ms CPU | 常驻 Python 进程，无 CPU 上限 |
| 数据 | D1 | 本地 SQLite（WAL） |
| 图片字节 | Cache API（按边缘节点，可能被驱逐） | 磁盘文件 `data/bg/current`（持久、原子替换） |
| 后台换图 | `context.waitUntil()` | `threading.Thread` |
| 刷新状态 | `bg_cache.refresh_until` 时间戳 | 进程内布尔标志 |
| 版本号 | 上游 ETag，无则 `日期-字节数` | 整图 sha1 前 16 位 |
| 密码哈希 | PBKDF2-SHA256 **3000** 次（受 10 ms 限制） | 同算法 **300000** 次 |
| og 标签 | `_middleware.js` 响应时改写 | 返回 HTML 时按 Host 拼 |
| 静态资源 | CDN 直出 | 进程直出 |

> 图片这条差异带来的实际影响只有两点：① 每个边缘节点当天可能各回源一次；
> ② 缓存被驱逐后第一个访问者会等一次同步回源。功能上没有区别。

---

## 成本与限制

免费额度对本项目非常充裕：

| 资源 | 免费额度 | 本项目的实际用量 |
|------|----------|------------------|
| Pages 静态请求 | 不限量、不限带宽 | 占绝大多数请求 |
| Functions 请求 | 10 万次/天 | 每天几十次 |
| D1 存储 | 5 GB | 不到 1 MB |
| D1 读取 | 500 万行/天 | 每天几百行 |
| Cache API | 不单独计费（跟随边缘缓存策略） | 每天每节点一张图 |

`/bg` 这条链路只在**缓存未命中**时消耗 CPU：回源后把图片读成 `ArrayBuffer`
做一次文件头嗅探（只取前 32 字节做判断，不做整图哈希）。命中缓存时是纯流式转发，
几乎不占 CPU —— 这也是没有沿用 VPS 版「整图 sha1 当 ETag」的原因。

**唯一需要留意的是 CPU 时间**：免费版每个请求只有 **10 ms** 计算时间，
而密码哈希（PBKDF2）是 CPU 密集操作。实测耗时如下
（Node 22 WebCrypto，与 Workers 同属 V8 + BoringSSL 系，数量级一致）：

| 迭代数 | 单次哈希耗时 | 对免费版 10 ms 预算 |
|--------|--------------|---------------------|
| 10000 | 19–37 ms | 明显超限 |
| **3000（默认）** | 6–11 ms | 基本可用 |
| 1000 | 2–4 ms | 宽裕 |

因此 `wrangler.toml.example` 与代码里的默认值都取 **3000**。

> ⚠️ **这是明确的安全取舍。** OWASP 对 PBKDF2-SHA256 的建议值是 600000，
> 但 Workers 运行时**不支持 bcrypt**（依赖 C++ 绑定），Web Crypto 也**不提供
> scrypt / Argon2** —— PBKDF2 是免费版唯一可行且 CPU 可承受的选项。
> Cloudflare 官方的立场是「不要为迁就免费版而削弱密码哈希，应升级 Paid
> 或改用托管认证服务」。对一个只有你自己登录的学期倒计时后台，
> 这个折中是可接受的；但请务必设置一个足够长的密码。

升级到 Workers Paid（$5/月，含 3000 万 CPU 毫秒）后，建议把
`PBKDF2_ITERATIONS` 调到 `600000`。若登录时出现
`Error 1102（Worker exceeded resource limits）`，说明当前值仍然偏高，继续调小即可。

---

## 更新日志

- **v3.2** — 同步 VPS 版 v4.x 的能力：新增 `/bg` 每日壁纸（服务端回源 + Cache API 缓存 +
  文件头校验 + 版本号 + 当天未换图自动重试）、壁纸信息公告（`bg_info`，支持 auto/title/custom
  文案与弹出频率）、后台「每日壁纸公告」面板与状态矩阵、`bg_version` 轻量探针、
  `bg_status` / `refresh_bg` / `refresh_bg_info` 管理接口；新增 `bg_cache` 表与 7 个壁纸配置项；
  新增 `_middleware.js` 自动补全 og 标签；登录时批量清理过期会话；前端三个页面整体更新
- **v3.0** — 迁移到 Cloudflare Pages + D1；后端由 PHP 重写为 Pages Functions；
  密码算法换为 PBKDF2-SHA256；会话改存 D1；日历由服务端渲染改为前端渲染；
  管理后台拆分为 `admin.html` + `login.html`；修复「编辑公告」实际会新增公告的问题
- **v2.1** — 存储改为 JSON 文件（去 MySQL）；全站 CSRF 防护；管理界面图标换 Font Awesome（国内 CDN）；日历大屏默认展开全部月份；修复公告列表脱离 Tab、空时间保存为 `:00` 等 Bug
