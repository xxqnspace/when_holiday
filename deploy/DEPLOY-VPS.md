# holiday-cf · VPS 版部署说明

把原本跑在 **Cloudflare Pages + D1** 上的「什么时候放假啊」移植到这台 Ubuntu VPS，
占用原 edushare 的 **28417** 端口。

## 目录结构

```
/opt/holiday/
├── app.py              # 全部后端 + 静态资源服务（Python 3.10 标准库，零依赖）
├── static/             # 前端（直接来自仓库 public/，未修改任何 JS）
├── data/holiday.db     # SQLite（WAL 模式），含 users/settings/announcements/sessions
└── deploy/holiday.service
```

服务单元：`/etc/systemd/system/holiday.service`（`User=holiday`，非 root）

## 与 Cloudflare 原版的差异（适配点）

| 项目 | 原版（Cloudflare） | VPS 版 |
|------|--------------------|--------|
| 运行时 | Pages Functions（Workers，每请求隔离，CPU 10ms 上限） | 常驻 Python 进程（ThreadingHTTPServer） |
| 存储 | D1（远程 SQLite） | 本地 SQLite 文件，WAL 模式 |
| 路由 | `functions/api/[[action]].js` 捕获 | `/api/<action>` 前缀分发，action 语义完全一致 |
| 密码哈希 | PBKDF2-SHA256，3000 次（受免费版 CPU 限制） | 同算法同格式，**600000 次**（实测 243ms） |
| 响应头 | Pages 的 `_headers` 文件 | 由 `cache_control_for()` 在代码里等价实现 |
| Cookie | `Secure` 仅在 HTTPS 下加 | 同逻辑，另支持 `X-Forwarded-Proto` 反代场景 |
| og:image / og:url | 静态相对路径（README 提示需手改绝对地址） | **按请求 Host 自动补全绝对地址** |
| 静态服务 | CDN | 本进程直出，含 `nosniff`、目录穿越防护 |

接口契约（路径、请求体字段、`{success,message,data}` 结构、`holiday_session` /
`holiday_csrf` Cookie、`X-CSRF-Token` 头、PBKDF2 存储格式 `pbkdf2$<iter>$<salt>$<hash>`）
与原版完全一致，**前端 public/ 下的文件一个字节都没改**。

## 背景图每日缓存

首页背景原先是浏览器直连第三方壁纸接口。现在改成**服务端按天缓存**：

```
浏览器 ──GET /bg──> app.py ──当天首次──> 上游拉取 ──落盘──> data/bg/current
                      └──当天已缓存──> 直接读盘返回（不发外部请求）
```

- **当前图源**：`https://wall.1472588.xyz/today.jpg`（约 326 KB，1920×1080）。
  同一个站还有 `/1366x768.jpg`（167 KB）、`/vertical.jpg`（竖屏 330 KB）、
  `/uhd.jpg`（3.6 MB）可换，在后台「背景设置」里改地址即可。

- **配置项**：`bg_upstream_url` 是真正的图片源，`bg_image_url` 固定为 `/bg`。
  后台「背景设置」里填的就是源地址，保存后写入 `bg_upstream_url`。
- **跨天判定**：固定按 UTC+8 的日期，所以是北京时间零点换图。
- **换图流程**：先拉新图到临时文件 → `os.replace` 原子替换 → 删除目录里的残留旧文件。
  中途失败不会破坏旧图（宁可旧不可无）。
- **拉取失败**：继续用旧图服务，返回 503 只在完全没有缓存时发生。
  后台点「立即刷新缓存」会明确提示失败原因，例如「刷新失败，已保留原背景图：上游只返回 2 字节…」。
- **内容校验（2026-10-28 加的）**：只认文件头，不信 `Content-Type`，且要求 ≥ 10 KB。
  起因是上游 `/today.jpg` 用 `image/jpeg` 返回了 2 字节的 `OK`，被当成图片存成了「今日背景」，
  手机打开就是整片空白。现在这种响应会被判为失败 → 保留上一张有效图，后台能看到原因。
- **客户端缓存**：`/bg` 不带 `?v=` 时是 `no-cache, must-revalidate`（每次回源校验，命中只回 304）；
  带当前版本号 `/bg?v=<version>` 才是 `public, max-age=86400, immutable`。
  前端从 `/api/get_settings` 拿到 `bg_version` 拼进 CSS 地址，图一换 URL 就变，
  手机/PWA 里缓存住的旧图（包括坏图）立刻失效。原先统一 `max-age=3600`，
  换了图手机上还要挂一小时旧图。
- **上游没换图时的自愈**：跨天拉回来的图和本地一字节不差时（早上 7 点上游还没更新很常见），
  不锁死 24 小时，而是记 `retry_at` / `retry_count`，每 30 分钟自动再试一次，最多 8 次。
  日志会打印「上游返回的仍是上一张图，30 分钟后重试（第 N/8 次）」。
- **响应头**：`ETag` 仍是主要判据，304 分支约 50 ms，不重复下载图片。
- **手动干预**：后台「立即刷新缓存」按钮 → `POST /api/refresh_bg`（管理员 + CSRF）。
  `GET /api/bg_status` 额外返回 `version`、`retry_at`、`retry_count`、`awaiting_update`。

## 每日壁纸信息（顶部公告）

同一源站还提供 `info.json`，里面有当天壁纸的标题和版权说明：

```json
{ "title": "大自然的金色明镜",
  "copyright": "秋叶缤纷的池杉林，乔治亚州，美国 (© Chris Moore/Getty Images)",
  "startdate": 20261026 }
```

- **抓取时机**：跟背景图**同一次刷新**里抓，结果写进 `data/bg/meta.json`
  的 `info_title` / `info_copyright` / `info_date` / `info_at`，不额外定时任务。
- **展示方式**：首页 `GET /api/bg_info`（**公开接口**，无需登录）拿服务端**拼好的**
  `content`（正文）和 `seconds`（停留秒数），直接进公告队列。正文长什么样由后台配置决定。
- **去重**：浏览器 `localStorage.bg_info_seen` 记 `壁纸日期|标题|展示模式`。同一张图只弹一次；
  上游换图、或后台改了展示模式才会再弹（改完能立刻看到效果）；换设备/清缓存会重新弹一次。
- **容错**：信息接口挂了只影响公告，图片照常刷新；`meta.json` 里不会留上一次的旧标题。

### 管理项（后台「背景设置 → 每日壁纸公告」）

| 配置键 | 含义 | 默认 |
|---|---|---|
| `bg_info_announce` | `1` 启用 / `0` 停用 | `1` |
| `bg_info_mode` | `auto` 标题+版权 / `title` 仅标题 / `custom` 自定义文案 | `auto` |
| `bg_info_template` | `custom` 的模板，支持 `{title}` `{copyright}`；清空则回落 `auto` | `**今日壁纸：{title}**\n{copyright}` |
| `bg_info_seconds` | 停留秒数，3–60，越界自动收敛 | `9` |
| `bg_info_url` | 图片信息接口地址 | `https://wall.1472588.xyz/info.json` |

卡片上另有三个动作：

- **保存公告配置** → `save_settings`（白名单 + 值域收敛，非法模式回落 `auto`）
- **立即重抓信息** → `POST /api/refresh_bg_info`（管理员 + CSRF），只重抓 `info.json`，
  **不动已缓存的图片**；抓不到会明确报错，不会假装成功
- **在本机预览** → 清 `localStorage.bg_info_seen` 后新开首页，立刻看到真实弹出效果

`GET /api/bg_status`（管理员）在原有缓存状态上多返回一个 `announce` 对象
（`enabled` / `mode` / `seconds` / `template` / `info_url` / `content`），后台那张卡片就是它驱动的。
纯色背景模式下卡片不显示、接口也直接返回 `enabled=false`。

- **与后台公告的关系**：两者共用同一条公告条，走队列——管理员公告先播（按
  `announcement_auto_close` 秒），播完接着播壁纸信息（按 `bg_info_seconds` 秒）；
  用户点 × 则整条收起、不再续播。

> 踩过的坑：`info_url()` / `upstream_url()` 早期是直接 `SELECT ... FROM settings`，
> 老库升级时新配置项还没写进表，拿到空串 → 信息永远抓不到。现在统一走
> `get_settings()`（带 `DEFAULT_SETTINGS` 兜底），`tools/unit-check.py` 专门守这个分支。
- 缓存目录：`/opt/holiday/data/bg/`（`current` 图片本体 + `meta.json` 记录日期/来源/ETag）。

验证方式：

```bash
curl -sI http://127.0.0.1:28417/bg | grep -Ei 'content-type|etag|x-bg-date'
sudo cat /opt/holiday/data/bg/meta.json     # date 是今天就不会再回源
```

## 磁盘占用（为什么不会越用越大）

**背景图缓存永远是「一张图 + 一个 meta」，占用恒定**：

```
/opt/holiday/data/bg/
├── current     当前这张图（新图直接覆盖它）
└── meta.json   日期 / 来源 / ETag / 壁纸信息（约 400 B）
```

- 换图流程是 `写 current.tmp` → `os.replace(tmp, current)`，**同一路径原子覆盖**，
  不会产生 `2026-10-28.jpg`、`2026-10-29.jpg` 这种按天堆积的文件。
- 每次成功抓取后、以及**服务启动时**都会清扫一遍缓存目录：除了 `current` 和
  `meta.json`，其它文件（写文件中途被杀留下的 `current.tmp`、老版本遗留）一律删掉。
  日志会打印「[bg] 清理缓存残留 N 个文件」。
- 单张图上限 16 MB（`BG_MAX_BYTES`），低于 10 KB 或文件头不对的直接判为失败、不落盘。

实测：整站 `data/` 只有 **392 KB**（图 341 KB + SQLite 45 KB），50 GB 磁盘用掉 6.4 GB（14%）。

**另外两处会悄悄变大的地方已经处理：**

| 位置 | 原来 | 现在 |
|---|---|---|
| `sessions` 表 | 只读一条删一条，没被读过的过期会话永远留着 | 每次登录先 `DELETE ... WHERE expires_at <= now` |
| journald 系统日志 | 每个请求都写一行，**已涨到 120 MB**，且无上限 | 成功请求不再记录（只记 4xx/5xx 和报错）；加 `/etc/systemd/journald.conf.d/99-size-cap.conf` 限制 200 MB |

自查命令：

```bash
sudo du -sh /opt/holiday/data /opt/holiday/data/bg     # 应恒定 ~390K / ~344K
sudo ls -la /opt/holiday/data/bg                        # 应只有 current + meta.json
sudo journalctl --disk-usage                            # 应不超过 200M
df -h /                                                 # 整机
```

需要手动清日志时：`sudo journalctl --vacuum-size=100M`（只保留最近 100 MB）。

## 常用运维命令

```bash
sudo systemctl status holiday          # 状态
sudo systemctl restart holiday         # 重启
sudo journalctl -u holiday -f          # 实时日志
sudo sqlite3 /opt/holiday/data/holiday.db 'select key,value from settings'   # 看配置
sudo cat /opt/holiday/data/bg/meta.json                                      # 看当天背景图与壁纸信息
```

服务端日志里会有这类行，排查背景图问题时先看它：

```
[bg] 已缓存 2026-10-27（image/jpeg，326 KB，0.42s）
[bg] 壁纸信息拉取失败：URLError: <urlopen error timed out>
```

## 自查脚本（本地跑，不打生产）

| 脚本 | 作用 |
|---|---|
| `tools/smoke.py` | 起临时实例跑全量接口断言（含背景缓存、壁纸信息、CSRF、权限） |
| `tools/unit-check.py` | 不启动服务：配置回落默认值、info.json 解析与容错 |
| `tools/jscheck.py` | 无头浏览器跑后台 JS，查语法/运行时错误与字段回填 |
| `tools/jscheck-home.py` | 无头浏览器跑首页，验公告队列、去重、自动收起 |
| `tools/check-admin.py` | 静态检查后台结构（id/onclick/标签闭合/设计约束） |
| `tools/preview.py` / `diag.py` | 截图与布局溢出测量 |

## 备份

数据只在一个文件里，备份它就是备份全部：

```bash
sudo systemctl stop holiday
sudo cp /opt/holiday/data/holiday.db /root/holiday-$(date +%F).db
sudo systemctl start holiday
```

（WAL 模式下热备份请用 `sqlite3 holiday.db ".backup /root/x.db"`，不要直接 cp。）

## 回滚到 edushare

edushare 的文件没有被删除，只是停用：

```bash
sudo systemctl disable --now holiday
sudo systemctl enable --now edushare
```

## 已知限制

1. **没有 HTTPS**：站点走 `http://IP:28417`，登录密码在网络上是明文传输的
   （Cookie 因此也没带 `Secure`）。要真正安全，需要套一层域名 + TLS
   （Caddy / nginx + Let's Encrypt，或 Cloudflare Tunnel）。
2. **PWA 装不上**：浏览器只允许在 HTTPS 或 localhost 下注册 Service Worker。
   `sw.js` 注册失败会在控制台打一行日志，页面功能不受影响，但无法「添加到主屏幕」离线使用。
3. 单进程 + SQLite，适合个人使用；多用户高并发不是这个方案的目标。
