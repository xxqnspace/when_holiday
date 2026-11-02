/**
 * 数据访问层（D1）
 *
 * 替代原 PHP 版的 storage.php。原版把整份数据塞进一个 JSON 文件
 * 并用 flock 做并发保护，这里改为四张表 + SQL 语句，并发由 D1 保证。
 *
 * 首次请求会自动创建默认管理员 admin / admin123。
 */

import { DEFAULT_SETTINGS, JSON_SETTINGS_KEYS, SETTINGS_ALLOWED_KEYS } from './config.js';
import { nowShanghai } from './util.js';
import { hashPassword } from './auth.js';

/** 把默认配置转成可写入的值 */
function toDbValue(key, value) {
  return JSON_SETTINGS_KEYS.has(key) ? JSON.stringify(value) : String(value);
}

/**
 * 首次初始化：建默认管理员，并补齐缺失的配置键。
 * 只在 users 表为空时执行，所以日常请求只会多一次 COUNT 查询。
 */
export async function ensureSeed(env) {
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
