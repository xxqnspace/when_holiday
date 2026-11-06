/**
 * 数据访问层（D1）
 *
 * 替代原 PHP 版的 storage.php。原版把整份数据塞进一个 JSON 文件
 * 并用 flock 做并发保护，这里改为五张表 + SQL 语句，并发由 D1 保证。
 *
 * 首次请求会自动创建默认管理员 admin / admin123。
 *
 * v3.2 新增 bg_cache 表：背景图字节在 Cache API 里，这里只存元数据
 * （日期、版本号、上游 ETag、壁纸标题/版权、刷新锁）。
 */

import {
  DEFAULT_SETTINGS,
  JSON_SETTINGS_KEYS,
  SETTINGS_ALLOWED_KEYS,
  isHttpUrl,
  normalizeSetting,
} from './config.js';
import { nowShanghai } from './util.js';
import { hashPassword } from './auth.js';

/** 把默认配置转成可写入的值 */
function toDbValue(key, value) {
  return JSON_SETTINGS_KEYS.has(key) ? JSON.stringify(value) : String(value);
}

/**
 * bg_cache 建表语句。
 *
 * 除了迁移脚本，这里还会在运行时懒建一次 —— 老部署直接重新部署代码就能用，
 * 不必先去控制台跑 0002 迁移。表已存在时 CREATE IF NOT EXISTS 是空操作。
 */
const BG_SCHEMA = `
CREATE TABLE IF NOT EXISTS bg_cache (
  id             INTEGER PRIMARY KEY CHECK (id = 1),
  date           TEXT,
  fetched_at     TEXT,
  upstream       TEXT,
  content_type   TEXT,
  bytes          INTEGER,
  version        TEXT,
  etag           TEXT,
  info_title     TEXT,
  info_copyright TEXT,
  info_date      TEXT,
  info_at        TEXT,
  refresh_until  TEXT,
  retry_at       TEXT,
  retry_count    INTEGER
)`;

// 每个 isolate 只需要建一次表（isolate 复用期间不必重复 DDL）
let bgSchemaReady = false;

/**
 * 懒建 bg_cache 表（每个 isolate 一次）。
 * /bg 与 /api/* 都会先调它，所以老部署不跑迁移也不会报表不存在。
 */
export async function ensureBgSchema(env) {
  if (bgSchemaReady) return;
  try {
    await env.DB.prepare(BG_SCHEMA).run();
    bgSchemaReady = true;
  } catch (err) {
    // 建表失败不影响主流程（可能是并发建表），下次请求再试
    console.error('[db] bg_cache 建表失败', err && err.message);
  }
}

/**
 * 首次初始化：建默认管理员，并补齐缺失的配置键。
 * 只在 users 表为空时执行，所以日常请求只会多一次 COUNT 查询。
 */
export async function ensureSeed(env) {
  await ensureBgSchema(env);

  const row = await env.DB.prepare('SELECT COUNT(*) AS n FROM users').first();
  if (row && row.n > 0) return false;

  const password = await hashPassword('admin123', env);
  await env.DB.prepare(
    `INSERT OR IGNORE INTO users (username, password, role, created_at)
     VALUES (?, ?, ?, ?)`,
  )
    .bind('admin', password, 'admin', nowShanghai())
    .run();

  const stmts = Object.entries(DEFAULT_SETTINGS).map(([key, value]) =>
    env.DB.prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)').bind(
      key,
      toDbValue(key, value),
    ),
  );
  await env.DB.batch(stmts);

  return true;
}

// ==================== 配置 ====================

/**
 * 读取全部配置，缺失的键用 DEFAULT_SETTINGS 补齐。
 * holidays / workdays 会解析成数组后返回，前端无需再判断类型。
 */
export async function getSettings(env) {
  const { results } = await env.DB.prepare('SELECT key, value FROM settings').all();

  const settings = { ...DEFAULT_SETTINGS };
  for (const row of results || []) {
    if (!SETTINGS_ALLOWED_KEYS.has(row.key)) continue;

    if (JSON_SETTINGS_KEYS.has(row.key)) {
      try {
        const parsed = JSON.parse(row.value);
        settings[row.key] = Array.isArray(parsed) ? parsed : [];
      } catch {
        settings[row.key] = [];
      }
    } else {
      settings[row.key] = row.value;
    }
  }
  return settings;
}

/**
 * 批量写入配置（只接受白名单内的键）
 * @returns {Promise<number>} 实际写入的键数量
 */
export async function saveSettings(env, patch) {
  const stmts = [];

  for (const [key, rawValue] of Object.entries(patch || {})) {
    if (!SETTINGS_ALLOWED_KEYS.has(key)) continue;

    let value = rawValue;
    if (JSON_SETTINGS_KEYS.has(key)) {
      // 前端可能传数组，也可能传 JSON 字符串
      if (typeof value === 'string') {
        try {
          value = JSON.parse(value);
        } catch {
          value = [];
        }
      }
      if (!Array.isArray(value)) value = [];
      value = value.filter((item) => typeof item === 'string');
    } else if (value === null || value === undefined) {
      continue;
    } else {
      value = normalizeSetting(key, value);
    }

    stmts.push(
      env.DB.prepare(
        `INSERT INTO settings (key, value) VALUES (?, ?)
         ON CONFLICT(key) DO UPDATE SET value = excluded.value`,
      ).bind(key, toDbValue(key, value)),
    );
  }

  if (stmts.length) await env.DB.batch(stmts);
  return stmts.length;
}

/**
 * 老版本把第三方壁纸地址直接写在 bg_image_url 里，由浏览器去拉。
 *
 * 这里一次性迁移：上游地址搬到 bg_upstream_url，bg_image_url 改为本站的 /bg，
 * 之后所有访问都走服务端缓存。迁移是幂等的，重复执行没有副作用。
 *
 * @returns {Promise<boolean>} 是否真的改动了数据
 */
export async function migrateSettings(env) {
  const row = await env.DB.prepare(
    "SELECT value FROM settings WHERE key = 'bg_image_url'",
  ).first();
  if (!row) return false;

  const value = String(row.value || '').trim();
  if (!isHttpUrl(value)) return false;

  const existing = await env.DB.prepare(
    "SELECT value FROM settings WHERE key = 'bg_upstream_url'",
  ).first();

  const stmts = [];
  if (!existing || !String(existing.value || '').trim()) {
    stmts.push(
      env.DB.prepare(
        "INSERT INTO settings (key, value) VALUES ('bg_upstream_url', ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value",
      ).bind(value),
    );
  }
  stmts.push(
    env.DB.prepare("UPDATE settings SET value = '/bg' WHERE key = 'bg_image_url'"),
  );
  await env.DB.batch(stmts);

  console.log('[migrate] bg_image_url -> /bg，上游地址保存为', value);
  return true;
}

// ==================== 背景图元数据 ====================

/** 读取 bg_cache 的唯一一行，没有就返回空对象 */
export async function getBgMeta(env) {
  try {
    const row = await env.DB.prepare('SELECT * FROM bg_cache WHERE id = 1').first();
    if (!row) return {};
    const meta = { ...row };
    delete meta.id;
    return meta;
  } catch (err) {
    // 表还不存在（老部署首次请求、建表语句失败）时退化为空元数据
    console.error('[db] 读取 bg_cache 失败', err && err.message);
    return {};
  }
}

/** 覆盖写入 bg_cache（单行 upsert，缺的字段写 null） */
export async function saveBgMeta(env, meta) {
  const m = meta || {};
  await env.DB.prepare(
    `INSERT INTO bg_cache
       (id, date, fetched_at, upstream, content_type, bytes, version, etag,
        info_title, info_copyright, info_date, info_at,
        refresh_until, retry_at, retry_count)
     VALUES (1, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
     ON CONFLICT(id) DO UPDATE SET
       date = excluded.date,
       fetched_at = excluded.fetched_at,
       upstream = excluded.upstream,
       content_type = excluded.content_type,
       bytes = excluded.bytes,
       version = excluded.version,
       etag = excluded.etag,
       info_title = excluded.info_title,
       info_copyright = excluded.info_copyright,
       info_date = excluded.info_date,
       info_at = excluded.info_at,
       refresh_until = excluded.refresh_until,
       retry_at = excluded.retry_at,
       retry_count = excluded.retry_count`,
  )
    .bind(
      m.date ?? null,
      m.fetched_at ?? null,
      m.upstream ?? null,
      m.content_type ?? null,
      m.bytes ?? null,
      m.version ?? null,
      m.etag ?? null,
      m.info_title ?? null,
      m.info_copyright ?? null,
      m.info_date ?? null,
      m.info_at ?? null,
      m.refresh_until ?? null,
      m.retry_at ?? null,
      m.retry_count ?? null,
    )
    .run();
}

/** 在当前元数据上打补丁（读-合并-写），用于只更新部分字段 */
export async function patchBgMeta(env, patch) {
  const meta = await getBgMeta(env);
  const merged = { ...meta, ...(patch || {}) };
  await saveBgMeta(env, merged);
  return merged;
}

// ==================== 用户 ====================

export async function findUserByUsername(env, username) {
  return env.DB.prepare('SELECT * FROM users WHERE username = ?').bind(username).first();
}

export async function findUserById(env, id) {
  return env.DB.prepare('SELECT * FROM users WHERE id = ?').bind(id).first();
}

/** 用户列表（不含密码字段） */
export async function listUsers(env) {
  const { results } = await env.DB.prepare(
    'SELECT id, username, role, created_at FROM users ORDER BY id ASC',
  ).all();
  return results || [];
}

export async function createUser(env, { username, password, role }) {
  const hash = await hashPassword(password, env);
  await env.DB.prepare(
    `INSERT INTO users (username, password, role, created_at)
     VALUES (?, ?, ?, ?)`,
  )
    .bind(username, hash, role, nowShanghai())
    .run();
}

/**
 * 更新用户；newPassword 为空时只改角色
 */
export async function updateUser(env, id, { role, newPassword }) {
  if (newPassword) {
    const hash = await hashPassword(newPassword, env);
    await env.DB.prepare('UPDATE users SET role = ?, password = ? WHERE id = ?')
      .bind(role, hash, id)
      .run();
  } else {
    await env.DB.prepare('UPDATE users SET role = ? WHERE id = ?').bind(role, id).run();
  }
}

export async function deleteUser(env, id) {
  await env.DB.prepare('DELETE FROM users WHERE id = ?').bind(id).run();
  // 同步清掉该用户的会话，避免残留下线态
  await env.DB.prepare('DELETE FROM sessions WHERE user_id = ?').bind(id).run();
}

// ==================== 公告 ====================

export async function listAnnouncements(env) {
  const { results } = await env.DB.prepare(
    'SELECT * FROM announcements ORDER BY created_at DESC, id DESC',
  ).all();
  return results || [];
}

/**
 * 取当前生效的最新公告
 * 生效条件与 PHP 版一致：is_active = 1，且在展示时间窗口内（留空表示不限）
 */
export async function getActiveAnnouncement(env) {
  const now = nowShanghai();
  const row = await env.DB.prepare(
    `SELECT * FROM announcements
      WHERE is_active = 1
        AND (show_from  IS NULL OR show_from  = '' OR show_from  <= ?)
        AND (show_until IS NULL OR show_until = '' OR show_until >= ?)
      ORDER BY created_at DESC, id DESC
      LIMIT 1`,
  )
    .bind(now, now)
    .first();
  return row || null;
}

export async function saveAnnouncement(env, { id, content, showFrom, showUntil, isActive }) {
  if (id) {
    await env.DB.prepare(
      `UPDATE announcements
          SET content = ?, show_from = ?, show_until = ?, is_active = ?
        WHERE id = ?`,
    )
      .bind(content, showFrom, showUntil, isActive, id)
      .run();
    return id;
  }

  const result = await env.DB.prepare(
    `INSERT INTO announcements (content, show_from, show_until, is_active, created_at)
     VALUES (?, ?, ?, ?, ?)`,
  )
    .bind(content, showFrom, showUntil, isActive, nowShanghai())
    .run();

  return result.meta?.last_row_id ?? null;
}

export async function deleteAnnouncement(env, id) {
  await env.DB.prepare('DELETE FROM announcements WHERE id = ?').bind(id).run();
}
