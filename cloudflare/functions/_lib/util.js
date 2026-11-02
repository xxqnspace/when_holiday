/**
 * 通用工具：时间格式化、JSON 响应、Cookie 读写
 *
 * 注意时区：Workers 运行在 UTC，而原 PHP 版设置了 Asia/Shanghai。
 * 中国无夏令时，固定 UTC+8，因此这里统一手工偏移，保证
 * created_at / expires_at 等字符串与旧数据格式完全一致。
 */

const SHANGHAI_OFFSET_MS = 8 * 60 * 60 * 1000;

/**
 * 返回上海时区的 'YYYY-MM-DD HH:MM:SS'
 * 与 PHP 的 date('Y-m-d H:i:s') 输出一致，保证字符串比较排序有效。
 */
export function nowShanghai(date = new Date()) {
  return new Date(date.getTime() + SHANGHAI_OFFSET_MS)
    .toISOString()
    .slice(0, 19)
    .replace('T', ' ');
}

/** 构造 JSON 响应 */
export function jsonResponse(payload, status = 200, headers = {}) {
  return new Response(JSON.stringify(payload), {
    status,
    headers: {
      'Content-Type': 'application/json; charset=utf-8',
      'Cache-Control': 'no-store',
      ...headers,
    },
  });
}

/**
 * 成功响应
 * 保持与原 api.php 一致的 { success, message, data } 结构，
 * 前端所有判断都基于 success 字段。
 */
export function ok(data = null, message = '') {
  return jsonResponse({ success: true, message, data });
}

/** 失败响应 */
export function fail(message = '', data = null) {
  return jsonResponse({ success: false, message, data });
}

/** 解析请求 Cookie */
export function parseCookies(request) {
  const header = request.headers.get('Cookie') || '';
  const out = {};
  header.split(';').forEach((pair) => {
    const idx = pair.indexOf('=');
    if (idx < 0) return;
    const key = pair.slice(0, idx).trim();
    if (!key) return;
    out[key] = decodeURIComponent(pair.slice(idx + 1).trim());
  });
  return out;
}

/**
 * 序列化 Set-Cookie
 * Secure 仅在 HTTPS 下添加，否则本地 wrangler pages dev（http://localhost）无法写入。
 */
export function serializeCookie(name, value, request, opts = {}) {
  const isHttps = new URL(request.url).protocol === 'https:';
  const parts = [`${name}=${encodeURIComponent(value)}`, 'Path=/'];

  if (opts.maxAge !== undefined) parts.push(`Max-Age=${opts.maxAge}`);
  if (opts.httpOnly) parts.push('HttpOnly');
  if (isHttps) parts.push('Secure');
  parts.push(`SameSite=${opts.sameSite || 'Lax'}`);

  return parts.join('; ');
}

/** 读取 JSON 请求体，失败时返回空对象 */
export async function readJsonBody(request) {
  const type = request.headers.get('Content-Type') || '';
  if (!type.includes('application/json')) return {};
  try {
    const text = await request.text();
    if (!text) return {};
    const parsed = JSON.parse(text);
    return parsed && typeof parsed === 'object' ? parsed : {};
  } catch {
    return {};
  }
}

/** 生成 N 字节随机数的十六进制字符串 */
export function randomHex(bytes = 32) {
  const buf = crypto.getRandomValues(new Uint8Array(bytes));
  return [...buf].map((b) => b.toString(16).padStart(2, '0')).join('');
}
