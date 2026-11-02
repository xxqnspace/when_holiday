-- ============================================================
-- 0001_init.sql
-- 学期倒计时的 D1 表结构
--
-- 替代原 PHP 版的 data/db.json.php（JSON 文件存储）。
-- 说明：原 users 数组里的密码是 PHP bcrypt 哈希，Workers 运行时
-- 不支持 bcrypt，因此用户表不在此处播种，改由 Worker 首次请求时
-- 用 PBKDF2-SHA256 创建默认管理员（见 functions/_lib/db.js）。
-- ============================================================

-- ---------- 用户 ----------
CREATE TABLE IF NOT EXISTS users (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  username   TEXT    NOT NULL UNIQUE,
  password   TEXT    NOT NULL,          -- 格式：pbkdf2$<迭代数>$<saltHex>$<hashHex>
  role       TEXT    NOT NULL DEFAULT 'user',
  created_at TEXT    NOT NULL
);

-- ---------- 站点配置（键值对，对应原来的 settings 对象） ----------
-- holidays / workdays 两个键存 JSON 数组字符串，其余为普通字符串。
CREATE TABLE IF NOT EXISTS settings (
  key   TEXT PRIMARY KEY,
  value TEXT NOT NULL
);

-- ---------- 公告 ----------
CREATE TABLE IF NOT EXISTS announcements (
  id         INTEGER PRIMARY KEY AUTOINCREMENT,
  content    TEXT    NOT NULL DEFAULT '',
  show_from  TEXT,                      -- NULL 表示不限开始时间
  show_until TEXT,                      -- NULL 表示不限结束时间
  is_active  INTEGER NOT NULL DEFAULT 1,
  created_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_ann_created ON announcements (created_at);

-- ---------- 会话 ----------
-- 替代 PHP 的 $_SESSION。每次请求按 token 查一行即可。
-- CSRF 采用 double-submit cookie 方案，不需要在这里存 token。
CREATE TABLE IF NOT EXISTS sessions (
  token      TEXT    PRIMARY KEY,       -- 32 字节随机数的 hex
  user_id    INTEGER NOT NULL,
  username   TEXT    NOT NULL,
  role       TEXT    NOT NULL,
  created_at TEXT    NOT NULL,
  expires_at TEXT    NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_sessions_expires ON sessions (expires_at);

-- ============================================================
-- 初始配置：沿用原 data/db.json.php 中已有的方案
-- 使用 INSERT OR IGNORE，重复执行迁移不会覆盖线上已改过的值。
-- ============================================================

INSERT OR IGNORE INTO settings (key, value) VALUES
  ('start_date',               '2026-09-01 06:30:00'),
  ('end_date',                 '2027-01-22 17:00:00'),
  ('holidays',                 '["2026-10-01","2026-10-02","2026-10-05","2026-10-06","2026-10-07","2026-11-16","2026-11-17","2026-11-18","2026-09-25"]'),
  ('workdays',                 '["2026-10-10","2026-09-20"]'),
  ('mode',                     'online'),
  ('bg_type',                  'image'),
  ('bg_color',                 '#f7f9fc'),
  ('bg_image_url',             'https://wall.0a.ink/latest?mkt=zh-CN'),
  ('bg_image_opacity',         '1'),
  ('glass_opacity',            '0.35'),
  ('primary_color',            '#5c6bc0'),
  ('font_color',               '#333333'),
  ('card_bg',                  '#ffffff'),
  ('page_bg',                  '#f7f9fc'),
  ('progress_bar_start',       '#076cfb'),
  ('progress_bar_end',         '#076cfb'),
  ('progress_percent_color',   '#5c6dc0'),
  ('title',                    '什么时候放假啊o(╥﹏╥)o'),
  ('announcement_auto_close',  '5');
