-- ============================================================
-- 0002_bg_cache.sql
-- 每日壁纸：元数据表 + 新增配置项
--
-- 说明：图片字节不在数据库里（也不在磁盘上——Workers 没有文件系统），
-- 而是交给 Cache API 按版本号缓存；这里只存"今天缓存的是哪张图、
-- 版本号是什么、壁纸标题/版权、以及刷新锁"这些轻量信息。
--
-- 运行时也会懒建这张表（见 functions/_lib/db.js 的 ensureSeed），
-- 所以老部署直接重新部署代码就能用，不必强制跑本迁移。
-- 走 wrangler CLI 的话建议还是跑一次，保持结构与迁移记录一致。
-- ============================================================

CREATE TABLE IF NOT EXISTS bg_cache (
  id             INTEGER PRIMARY KEY CHECK (id = 1),
  date           TEXT,              -- 缓存日期（上海时区 YYYY-MM-DD）
  fetched_at     TEXT,              -- 最近一次成功抓图时间
  upstream       TEXT,              -- 实际使用的上游地址
  content_type   TEXT,              -- 按文件头嗅探出来的真实类型
  bytes          INTEGER,
  version        TEXT,              -- 内容版本号（上游 ETag，没有则用 日期-字节数）
  etag           TEXT,              -- 上游原始 ETag，用来判断"今天还没换图"
  info_title     TEXT,              -- 壁纸标题（info.json）
  info_copyright TEXT,              -- 壁纸版权说明
  info_date      TEXT,              -- 上游标注的日期
  info_at        TEXT,              -- 信息抓取时间
  refresh_until  TEXT,              -- 刷新锁：未到期表示别的请求正在回源
  retry_at       TEXT,              -- 上游当天没换图时的重试时间
  retry_count    INTEGER
);

-- ---------- 老数据迁移 ----------
-- 旧版把第三方壁纸地址直接写在 bg_image_url 里，由浏览器去拉。
-- 这里把它搬到 bg_upstream_url，bg_image_url 改为本站的 /bg。
INSERT INTO settings (key, value)
SELECT 'bg_upstream_url', value
  FROM settings
 WHERE key = 'bg_image_url'
   AND (value LIKE 'http://%' OR value LIKE 'https://%')
ON CONFLICT(key) DO NOTHING;

UPDATE settings SET value = '/bg'
 WHERE key = 'bg_image_url'
   AND (value LIKE 'http://%' OR value LIKE 'https://%');

-- ---------- 新增配置项 ----------
-- 用 INSERT OR IGNORE：已经存在（用户自己改过）的值不会被覆盖。
INSERT OR IGNORE INTO settings (key, value) VALUES
  ('bg_image_url',            '/bg'),
  ('bg_upstream_url',         'https://wall.1472588.xyz/today.jpg'),
  ('bg_info_url',             'https://wall.1472588.xyz/info.json'),
  ('bg_info_announce',        '1'),
  ('bg_info_always',          '0'),
  ('bg_info_mode',            'auto'),
  ('bg_info_template',        '**今日壁纸：{title}**' || char(10) || '{copyright}'),
  ('bg_info_seconds',         '9');
