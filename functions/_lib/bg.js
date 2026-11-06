/**
 * 每日壁纸：服务端回源 + 边缘缓存 + 信息公告
 *
 * 对应 VPS 版 app.py 里「背景图每日缓存」那一大段，但存储介质换掉了：
 *
 *   VPS 版                          Cloudflare 版（本项目）
 *   data/bg/current（磁盘文件）  →  Cache API（caches.default）
 *   threading.Thread 后台换图    →  context.waitUntil()，响应之后再回源
 *   进程内 refreshing 布尔标志   →  bg_cache.refresh_until 时间戳（跨 isolate 才一致）
 *    整图 sha1 当 ETag           →  直接用上游 ETag（大图哈希会吃掉 10ms CPU 预算）
 *
 * 代价说明：Cache API 是按边缘节点存的，可能被提前驱逐。驱逐后下一个请求会
 * 同步回源一次（几百毫秒到两三秒），用户看到的是背景晚一点出来，不会白屏。
 */

import { BG_INFO_MODES, BG_INFO_SECONDS_MIN, BG_INFO_SECONDS_MAX, isHttpUrl } from './config.js';
import { ensureBgSchema, getBgMeta, getSettings, patchBgMeta } from './db.js';
import { nowShanghai, shanghaiPlus, todayShanghai } from './util.js';

// 一张真实壁纸不可能这么小。低于这个值基本是错误页 / 占位响应
// （上游曾用 image/jpeg 返回过 2 字节的 "OK"）
const BG_MIN_BYTES = 10 * 1024;
const BG_MAX_BYTES = 16 * 1024 * 1024;
const BG_INFO_MAX_BYTES = 256 * 1024;

// 刷新锁：抢到锁的 isolate 在这段时间内负责回源，其它请求不重复打上游
const BG_REFRESH_LOCK_SECONDS = 120;
// 上游当天还没换图时，隔一段时间再看，别把昨天的图锁死一整天
const BG_RETRY_MINUTES = 30;
const BG_RETRY_MAX = 8;

const BG_UA =
  'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 ' +
  '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

// ==================== 工具 ====================

/** 压成单行并截断，避免上游文本里的换行/超长串撑破公告条 */
function cleanText(value, limit = 200) {
  return String(value ?? '')
    .replace(/\s+/g, ' ')
    .trim()
    .slice(0, limit);
}

/** 按文件头判断图片类型；不是已知图片就返回空串 */
function sniffImageType(buf) {
  const b = new Uint8Array(buf);
  if (b.length < 4) return '';
  if (b[0] === 0xff && b[1] === 0xd8 && b[2] === 0xff) return 'image/jpeg';
  if (b[0] === 0x89 && b[1] === 0x50 && b[2] === 0x4e && b[3] === 0x47) return 'image/png';
  if (b[0] === 0x47 && b[1] === 0x49 && b[2] === 0x46) return 'image/gif';
  if (b[0] === 0x42 && b[1] === 0x4d) return 'image/bmp';
  if (b.length >= 12 && b[0] === 0x52 && b[1] === 0x49 && b[2] === 0x46 && b[3] === 0x46
      && b[8] === 0x57 && b[9] === 0x45 && b[10] === 0x42 && b[11] === 0x50) {
    return 'image/webp';
  }
  if (b.length >= 16 && b[4] === 0x66 && b[5] === 0x74 && b[6] === 0x79 && b[7] === 0x70) {
    const brand = String.fromCharCode(b[8], b[9], b[10], b[11]);
    if (brand === 'avif' || brand === 'avis') return 'image/avif';
  }
  return '';
}

/** 上游 ETag 去掉引号和弱校验前缀，作为内容版本号 */
function normalizeEtag(raw) {
  return String(raw || '').trim().replace(/^W\//i, '').replace(/^"|"$/g, '').slice(0, 80);
}

/** 当天已缓存，但在等上游换图：到点了就允许再拉一次 */
function retryDue(meta) {
  if (!meta || meta.date !== todayShanghai()) return false;
  if (Number(meta.retry_count || 0) >= BG_RETRY_MAX) return false;
  return !!meta.retry_at && nowShanghai() >= meta.retry_at;
}

/** 今天的图已经缓存好，且不在等待重试 → 不需要任何回源 */
function isFresh(meta) {
  return !!meta && meta.date === todayShanghai() && !!meta.version && !retryDue(meta);
}

/** 是否有别的请求正在回源（refresh_until 未到期） */
export function isRefreshing(meta) {
  return !!meta && !!meta.refresh_until && meta.refresh_until > nowShanghai();
}

/** 缓存键：版本号进 URL，换图即换键，旧图自然失效 */
function cacheKey(origin, meta) {
  const tag = meta && meta.version ? String(meta.version) : 'none';
  return new URL(`/__bg_cache/${encodeURIComponent(tag)}`, origin).toString();
}

function getCache() {
  try {
    return typeof caches !== 'undefined' && caches.default ? caches.default : null;
  } catch {
    return null;
  }
}

// ==================== 回源 ====================

/** 拉上游图片，只信文件头不信 Content-Type */
async function fetchImage(url) {
  const resp = await fetch(url, {
    headers: {
      'User-Agent': BG_UA,
      Accept: 'image/avif,image/webp,image/apng,image/*,*/*;q=0.8',
      'Accept-Language': 'zh-CN,zh;q=0.9',
    },
  });
  if (!resp.ok) throw new Error(`上游返回 HTTP ${resp.status}`);

  const declared = (resp.headers.get('content-type') || '').split(';')[0].trim().toLowerCase();
  const length = Number(resp.headers.get('content-length') || 0);
  if (length && length > BG_MAX_BYTES) {
    throw new Error(`图片超过 ${BG_MAX_BYTES} 字节上限（Content-Length=${length}）`);
  }

  const buf = await resp.arrayBuffer();
  if (!buf.byteLength) throw new Error('上游返回空内容');
  if (buf.byteLength > BG_MAX_BYTES) throw new Error(`图片超过 ${BG_MAX_BYTES} 字节上限`);
  if (buf.byteLength < BG_MIN_BYTES) {
    throw new Error(
      `上游只返回 ${buf.byteLength} 字节（Content-Type=${declared || '-'}），不是有效图片`,
    );
  }

  const ctype = sniffImageType(buf.slice(0, 32));
  if (!ctype) throw new Error('上游内容不是图片（文件头无法识别）');

  return {
    buf,
    ctype,
    declared,
    etag: resp.headers.get('etag') || '',
    lastModified: resp.headers.get('last-modified') || '',
  };
}

/** 拉取壁纸信息（标题 / 版权），返回可直接并进元数据的字段 */
export async function fetchBgInfo(url) {
  if (!isHttpUrl(url)) throw new Error('图片信息接口地址无效');

  const resp = await fetch(url, {
    headers: {
      'User-Agent': BG_UA,
      Accept: 'application/json,text/plain,*/*;q=0.8',
      'Accept-Language': 'zh-CN,zh;q=0.9',
    },
  });
  if (!resp.ok) throw new Error(`图片信息接口返回 HTTP ${resp.status}`);

  const length = Number(resp.headers.get('content-length') || 0);
  if (length && length > BG_INFO_MAX_BYTES) {
    throw new Error(`图片信息超过 ${BG_INFO_MAX_BYTES} 字节上限`);
  }

  const text = await resp.text();
  if (text.length > BG_INFO_MAX_BYTES) throw new Error('图片信息响应过大');

  let data;
  try {
    data = JSON.parse(text);
  } catch {
    throw new Error('图片信息不是合法 JSON');
  }
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    throw new Error('图片信息不是 JSON 对象');
  }

  const title = cleanText(data.title, 120);
  const copyright = cleanText(data.copyright, 300);
  if (!title && !copyright) throw new Error('图片信息里没有 title / copyright');

  return {
    info_title: title,
    info_copyright: copyright,
    info_date: cleanText(data.startdate, 16),
    info_at: nowShanghai(),
  };
}

/**
 * 回源刷新一次（串行化靠 bg_cache.refresh_until 这把"软锁"）。
 *
 * @returns {Promise<{ok: boolean, error?: string, meta: object}>}
 */
export async function refreshBg(env, settings, { force = false, origin } = {}) {
  const before = await getBgMeta(env);
  if (!force && isRefreshing(before)) {
    return { ok: false, error: '已有请求正在刷新', meta: before };
  }
  if (!origin) return { ok: false, error: '缺少站点 origin，无法写缓存', meta: before };

  const url = String((settings && settings.bg_upstream_url) || '').trim();
  if (!isHttpUrl(url)) {
    return { ok: false, error: '未配置图片源地址', meta: before };
  }

  // 抢锁失败不影响正确性，只是可能有两个 isolate 同时回源一次
  await patchBgMeta(env, { refresh_until: shanghaiPlus(BG_REFRESH_LOCK_SECONDS) });

  let img;
  try {
    img = await fetchImage(url);
  } catch (err) {
    await patchBgMeta(env, { refresh_until: '' });
    console.error('[bg] 拉取失败', err && err.message);
    return { ok: false, error: String((err && err.message) || err), meta: before };
  }

  const etag = normalizeEtag(img.etag);
  const today = todayShanghai();
  // 上游没给 ETag 时（这个壁纸站就没有），用「同一天 + 字节数相同」判断是不是同一张图。
  // 照片级内容尺寸撞车的概率可以忽略，换来的是不必对 3MB 图做哈希/逐字节比较。
  const sameByEtag = !!etag && etag === normalizeEtag(before.etag) && before.date === today;
  const sameBySize = !etag && before.date === today
    && Number(before.bytes || 0) === img.buf.byteLength;
  const sameImage = sameByEtag || sameBySize;
  // 没有 ETag 时用「日期 + 字节数」当版本号：同一天里图没变版本就不变，够用
  const version = etag || `${today}-${img.buf.byteLength}`;

  // 写缓存（Cache API 不可用时跳过，只是每次请求都要回源）
  const cache = getCache();
  if (cache) {
    try {
      const cached = new Response(img.buf, {
        headers: {
          'Content-Type': img.ctype,
          'Cache-Control': 'public, max-age=86400',
          ETag: `"${version}"`,
        },
      });
      await cache.put(cacheKey(origin, { version }), cached);
    } catch (err) {
      console.error('[bg] 写缓存失败', err && err.message);
    }
  }

  const patch = {
    date: today,
    fetched_at: nowShanghai(),
    upstream: url,
    content_type: img.ctype,
    bytes: img.buf.byteLength,
    version,
    etag,
    refresh_until: '',
  };

  if (!sameImage) {
    // 图换了：重新抓当天的说明文案。抓不到不影响出图，只是不弹公告。
    let info = {};
    const infoUrl = String((settings && settings.bg_info_url) || '').trim();
    if (isHttpUrl(infoUrl)) {
      try {
        info = await fetchBgInfo(infoUrl);
      } catch (err) {
        console.error('[bg] 壁纸信息拉取失败', err && err.message);
      }
    }
    Object.assign(patch, {
      info_title: info.info_title ?? '',
      info_copyright: info.info_copyright ?? '',
      info_date: info.info_date ?? '',
      info_at: info.info_at ?? '',
      retry_at: '',
      retry_count: 0,
    });
  } else {
    // 上游给的还是同一张图：保持已有版本和文案，安排一次稍后重试
    const attempts = (Number(before.retry_count || 0) || 0) + 1;
    patch.retry_count = attempts;
    patch.retry_at = attempts <= BG_RETRY_MAX
      ? shanghaiPlus(BG_RETRY_MINUTES * 60)
      : '';
  }

  const meta = await patchBgMeta(env, patch);
  console.log(
    `[bg] 已缓存 ${today}（${img.ctype}，${Math.round(img.buf.byteLength / 1024)} KB，version=${version}）`,
  );
  // 把这次拉到的字节一并返回：Cache API 不可用（本地 dev 或写入失败）时直接拿它出图
  return { ok: true, meta, sameImage, image: { buf: img.buf, ctype: img.ctype } };
}

/** 安排一次后台回源；只在响应返回后执行，不拖慢当前请求 */
function scheduleRefresh(env, settings, origin, waitUntil) {
  const task = refreshBg(env, settings, { origin }).catch((err) => {
    console.error('[bg] 后台刷新异常', err && err.message);
  });
  if (typeof waitUntil === 'function') waitUntil(task);
  return task;
}

// ==================== 对外接口 ====================

/**
 * /bg 出图
 *
 * - 命中边缘缓存：直接出图，带 ETag / Cache-Control
 * - 未命中：同步回源一次（宁可慢一点也别让首页背景空着），并顺手把缓存补回去
 * - 带回 ?v=<当前版本> 的请求可以放心长缓存；老地址一律 must-revalidate
 */
export async function handleBgRequest(context) {
  const { request, env } = context;
  const waitUntil = context.waitUntil ? context.waitUntil.bind(context) : null;
  const url = new URL(request.url);
  const origin = url.origin;

  if (!env.DB) {
    return new Response('D1 数据库未绑定，无法提供背景图', { status: 503 });
  }

  let settings;
  try {
    await ensureBgSchema(env);
    settings = await getSettings(env);
  } catch (err) {
    console.error('[bg] 读取配置失败', err && err.message);
    return new Response('背景图配置不可用', { status: 503 });
  }

  let meta = await getBgMeta(env);

  // 过期的图先顶着（stale-while-revalidate），后台换今天的
  if (!isFresh(meta) && !isRefreshing(meta)) {
    scheduleRefresh(env, settings, origin, waitUntil);
  }

  const cache = getCache();
  let hit = cache && meta.version ? await cache.match(cacheKey(origin, meta)) : null;
  // 缓存不可用 / 被驱逐时，这次回源拿到的字节可以直接用，不依赖 Cache API
  let fallback = null;

  if (!hit) {
    // 缓存被驱逐 / 第一次访问：同步回源，别让用户看白屏
    const result = await refreshBg(env, settings, { force: true, origin });
    if (result.ok) {
      meta = result.meta;
      fallback = result.image || null;
      hit = cache ? await cache.match(cacheKey(origin, meta)) : null;
    }
  }

  if (!hit && !fallback) {
    return new Response('背景图暂不可用', {
      status: 503,
      headers: { 'Cache-Control': 'no-store' },
    });
  }

  const version = String(meta.version || '');
  const etag = `"${version}"`;
  const clientVersion = (url.searchParams.get('v') || '').trim();
  const cacheControl = clientVersion && clientVersion === version
    ? 'public, max-age=86400, immutable'
    : 'no-cache, must-revalidate';

  const headers = {
    ETag: etag,
    'Cache-Control': cacheControl,
    'X-Bg-Date': String(meta.date || ''),
    'X-Content-Type-Options': 'nosniff',
  };

  if ((request.headers.get('If-None-Match') || '').trim() === etag) {
    return new Response(null, { status: 304, headers });
  }

  const isHead = request.method === 'HEAD';
  const body = isHead ? null : (hit ? hit.body : fallback.buf);
  const contentType = hit
    ? String(hit.headers.get('Content-Type') || meta.content_type || 'image/jpeg')
    : fallback.ctype;
  const size = hit ? Number(meta.bytes || 0) : fallback.buf.byteLength;

  return new Response(body, {
    status: 200,
    headers: {
      ...headers,
      'Content-Type': contentType,
      ...(size ? { 'Content-Length': String(size) } : {}),
    },
  });
}

/** GET /api/bg_info —— 首页用：当天壁纸的标题与版权说明（公开接口） */
export async function bgInfoPayload(env, { origin, waitUntil } = {}) {
  const settings = await getSettings(env);
  const disabled = (settings.bg_type || 'solid') !== 'image'
    || String(settings.bg_info_announce ?? '1') === '0';
  if (disabled) return { enabled: false };

  let meta = await getBgMeta(env);
  if (!isFresh(meta) && !isRefreshing(meta) && origin) {
    scheduleRefresh(env, settings, origin, waitUntil);
  }

  const title = String(meta.info_title || '');
  const copyright = String(meta.info_copyright || '');
  const content = title || copyright ? renderBgInfoContent(settings, title, copyright) : '';

  return {
    enabled: !!content,
    refreshing: isRefreshing(meta),
    date: String(meta.date || ''),
    title,
    copyright,
    info_date: String(meta.info_date || ''),
    // 后台可配的两项：正文（前端直接渲染）与停留秒数
    content,
    seconds: parseSeconds(settings.bg_info_seconds),
    mode: BG_INFO_MODES.includes(settings.bg_info_mode) ? settings.bg_info_mode : 'auto',
    // true = 每次打开首页都弹（前端跳过「已看过」判断）
    always: String(settings.bg_info_always ?? '0') === '1',
  };
}

/** GET /api/bg_version —— 轻量探针：只读元数据，不回源、不读配置 */
export async function bgVersionPayload(env) {
  const meta = await getBgMeta(env);
  return {
    version: String(meta.version || ''),
    date: String(meta.date || ''),
    refreshing: isRefreshing(meta),
    has_image: !!meta.version,
  };
}

/** GET /api/bg_status —— 后台壁纸状态快照 */
export async function bgStatusPayload(env) {
  const meta = await getBgMeta(env);
  const settings = await getSettings(env);
  const title = String(meta.info_title || '');
  const copyright = String(meta.info_copyright || '');
  const today = todayShanghai();

  return {
    cached: !!meta.version && meta.date === today,
    refreshing: isRefreshing(meta),
    version: String(meta.version || ''),
    date: meta.date || null,
    is_today: meta.date === today,
    fetched_at: meta.fetched_at || null,
    upstream: String(meta.upstream || ''),
    content_type: String(meta.content_type || ''),
    bytes: Number(meta.bytes || 0),
    retry_at: String(meta.retry_at || ''),
    retry_count: Number(meta.retry_count || 0),
    awaiting_update: !!(meta.retry_at && Number(meta.retry_count || 0) < BG_RETRY_MAX),
    title,
    copyright,
    info_date: String(meta.info_date || ''),
    info_at: String(meta.info_at || ''),
    announce: {
      enabled: String(settings.bg_info_announce ?? '1') !== '0',
      always: String(settings.bg_info_always ?? '0') === '1',
      mode: settings.bg_info_mode || 'auto',
      seconds: parseSeconds(settings.bg_info_seconds),
      template: String(settings.bg_info_template || ''),
      info_url: String(settings.bg_info_url || ''),
      content: title || copyright ? renderBgInfoContent(settings, title, copyright) : '',
    },
  };
}

/** 把标题/版权按后台配置拼成公告正文（Markdown 片段） */
export function renderBgInfoContent(settings, title, copyText) {
  const t = title || '';
  const c = copyText || '';
  const mode = BG_INFO_MODES.includes(settings && settings.bg_info_mode)
    ? settings.bg_info_mode
    : 'auto';

  if (mode === 'title') {
    return `**今日壁纸：${t || '每日壁纸'}**`;
  }

  if (mode === 'custom') {
    const template = String((settings && settings.bg_info_template) || '').replace(/\r\n/g, '\n');
    const body = template
      .replace(/\{title\}/g, t || '每日壁纸')
      .replace(/\{copyright\}/g, c)
      .trim();
    // 模板被清空时回落到 auto，别让公告凭空消失
    if (body) return body;
  }

  let text = `**今日壁纸：${t || '每日壁纸'}**`;
  if (c) text += `\n${c}`;
  return text;
}

function parseSeconds(value) {
  const seconds = parseInt(value, 10);
  if (!Number.isFinite(seconds)) return 9;
  return Math.max(BG_INFO_SECONDS_MIN, Math.min(BG_INFO_SECONDS_MAX, seconds));
}

/** 供 handlers 复用：只更新壁纸信息字段（不动图片缓存） */
export async function applyBgInfo(env, info) {
  return patchBgMeta(env, info);
}
