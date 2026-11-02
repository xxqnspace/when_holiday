/**
 * 认证模块：密码哈希、会话管理、CSRF
 *
 * 与 PHP 版的差异：
 *  - 密码算法从 bcrypt 换为 PBKDF2-HMAC-SHA256。
 *    bcrypt 依赖 C++ 绑定，V8 isolate 里无法运行，Web Crypto 也不支持。
 *  - 会话从 $_SESSION 换成 D1 sessions 表 + HttpOnly Cookie。
 *  - CSRF 从「session 内存 token」换成 double-submit cookie 方案，
 *    好处是登录前的接口也能带上 token（静态页面拿不到服务端注入的 token）。
 */

import { SESSION_COOKIE, CSRF_COOKIE } from './config.js';
import { nowShanghai, parseCookies, serializeCookie, randomHex } from './util.js';

const PBKDF2_HASH = 'SHA-256';
const PBKDF2_KEY_BITS = 256;

/**
 * 默认迭代次数。
 *
 * 实测 10000 次迭代约需 19-37ms CPU，而免费版 Workers 每请求仅 10ms，
 * 故默认取 3000（约 6-11ms）。生产值由 wrangler.toml 的
 * PBKDF2_ITERATIONS 覆盖，升级到 Paid 计划后建议调到 600000。
 */
const DEFAULT_ITERATIONS = 3000;

function toHex(bytes) {
  return [...bytes].map((b) => b.toString(16).padStart(2, '0')).join('');
}

function fromHex(hex) {
  if (typeof hex !== 'string' || hex.length % 2 !== 0 || !/^[0-9a-f]*$/i.test(hex)) {
    return null;
  }
  const out = new Uint8Array(hex.length / 2);
  for (let i = 0; i < out.length; i++) {
    out[i] = parseInt(hex.substr(i * 2, 2), 16);
  }
  return out;
}

/**
 * 常数时间比较，避免通过响应耗时推断哈希。
 * 不依赖 crypto.subtle.timingSafeEqual（该 API 在部分运行时不一定存在）。
 */
function timingSafeEqual(a, b) {
  if (!a || !b || a.length !== b.length) return false;
  let diff = 0;
  for (let i = 0; i < a.length; i++) {
    diff |= a[i] ^ b[i];
  }
  return diff === 0;
}

async function derive(password, salt, iterations) {
  const key = await crypto.subtle.importKey(
    'raw',
    new TextEncoder().encode(password),
    'PBKDF2',
    false,
    ['deriveBits'],
  );
  const bits = await crypto.subtle.deriveBits(
    { name: 'PBKDF2', hash: PBKDF2_HASH, salt, iterations },
    key,
    PBKDF2_KEY_BITS,
  );
  return new Uint8Array(bits);
}

/** 读取配置的迭代次数 */
export function getIterations(env) {
  const n = parseInt(env.PBKDF2_ITERATIONS || '', 10);
  return Number.isFinite(n) && n > 0 ? n : DEFAULT_ITERATIONS;
}

/**
 * 生成密码哈希
 * 存储格式：pbkdf2$<迭代数>$<saltHex>$<hashHex>
 * 带上迭代数是为了将来能平滑升级加盐强度。
 */
export async function hashPassword(password, env) {
  const iterations = getIterations(env);
  const salt = crypto.getRandomValues(new Uint8Array(16));
  const bits = await derive(password, salt, iterations);
  return `pbkdf2$${iterations}$${toHex(salt)}$${toHex(bits)}`;
}

/**
 * 校验密码
 * 对非 pbkdf2 前缀的旧格式（例如 PHP 的 $2y$ bcrypt 哈希）一律返回 false，
 * 不会抛异常，避免脏数据把接口打挂。
 */
export async function verifyPassword(password, stored, env) {
  const parts = String(stored || '').split('$');
  if (parts.length !== 4 || parts[0] !== 'pbkdf2') return false;

  const iterations = parseInt(parts[1], 10);
  const salt = fromHex(parts[2]);
  const expected = fromHex(parts[3]);
  if (!Number.isFinite(iterations) || iterations <= 0 || !salt || !expected) return false;

  const actual = await derive(password, salt, iterations);
  return timingSafeEqual(actual, expected);
}

// ==================== 会话 ====================

/**
 * 创建会话并写入 D1
 * @returns {Promise<{token: string, maxAge: number}>}
 */
export async function createSession(env, user) {
  const days = parseInt(env.SESSION_DAYS || '30', 10) || 30;
  const token = randomHex(32);
  const now = Date.now();

  await env.DB.prepare(
    `INSERT INTO sessions (token, user_id, username, role, created_at, expires_at)
     VALUES (?, ?, ?, ?, ?, ?)`,
  )
    .bind(
      token,
      user.id,
      user.username,
      user.role,
      nowShanghai(new Date(now)),
      nowShanghai(new Date(now + days * 86400000)),
    )
    .run();

  return { token, maxAge: days * 86400 };
}

/** 按 token 删除会话 */
export async function deleteSession(env, token) {
  if (!token) return;
  await env.DB.prepare('DELETE FROM sessions WHERE token = ?').bind(token).run();
}

/**
 * 从请求中解析当前会话
 * 过期即删除并返回 null，顺带做一次惰性清理。
 */
export async function readSession(env, request) {
  const token = parseCookies(request)[SESSION_COOKIE];
  if (!token) return null;

  const row = await env.DB.prepare('SELECT * FROM sessions WHERE token = ?').bind(token).first();
  if (!row) return null;

  if (row.expires_at <= nowShanghai()) {
    await deleteSession(env, token);
    return null;
  }
  return row;
}

// ==================== Cookie ====================

/** 生成写入会话 Cookie 的 Set-Cookie 头 */
export function sessionCookieHeader(token, maxAge, request) {
  return serializeCookie(SESSION_COOKIE, token, request, {
    maxAge,
    httpOnly: true,
    sameSite: 'Lax',
  });
}

/** 清除会话 Cookie 的 Set-Cookie 头 */
export function clearSessionCookieHeader(request) {
  return serializeCookie(SESSION_COOKIE, '', request, {
    maxAge: 0,
    httpOnly: true,
    sameSite: 'Lax',
  });
}

/**
 * 生成 CSRF cookie 头；已有有效 cookie 时返回 null（不重复下发）
 */
export function ensureCsrfCookieHeader(request, existingToken) {
  if (existingToken) return null;
  const token = randomHex(16);
  return {
    token,
    header: serializeCookie(CSRF_COOKIE, token, request, {
      maxAge: 86400,
      httpOnly: false, // 前端需要读取该值并回填到请求头
      sameSite: 'Lax',
    }),
  };
}

/**
 * CSRF 校验：比对 Cookie 与请求头是否一致
 * 攻击者跨站时既读不到 Cookie 值，也无法伪造自定义请求头，故可有效拦截。
 */
export function verifyCsrf(request) {
  const cookieToken = parseCookies(request)[CSRF_COOKIE] || '';
  const headerToken = request.headers.get('X-CSRF-Token') || '';
  if (!cookieToken || !headerToken) return false;

  const a = new TextEncoder().encode(cookieToken);
  const b = new TextEncoder().encode(headerToken);
  return timingSafeEqual(a, b);
}
