/**
 * 默认配置（兜底值）
 * 对应 VPS 版 app.py 里的 DEFAULT_SETTINGS：当 settings 表缺少某个键时，用这里的值补齐。
 *
 * v3.2 新增了「每日壁纸」相关配置。和 VPS 版的最大区别是：
 * 图片字节不落磁盘（Workers 没有文件系统），而是交给 Cache API，
 * 这里只保留元数据相关的键。
 */

export const DEFAULT_SETTINGS = {
  // ======== 学期时间 ========
  start_date: '2026-03-05 06:30:00',
  end_date: '2026-07-03 17:00:00',

  // 特殊假期（YYYY-MM-DD）
  holidays: ['2026-04-06', '2026-05-01', '2026-05-04', '2026-05-05', '2026-06-19'],

  // 特殊工作日（原本周末但需补班）
  workdays: ['2026-05-09'],

  // ======== 运行模式 ========
  mode: 'online', // online | local

  // ======== 背景配置 ========
  bg_type: 'solid', // solid | image
  bg_color: '#f7f9fc',
  // 前端实际加载的地址：本站的 /bg（由 functions/bg.js 出图），不再直连第三方
  bg_image_url: '/bg',
  // 真正的图片来源，由服务端每天拉取一次并缓存到边缘
  bg_upstream_url: 'https://wall.1472588.xyz/today.jpg',
  // 同一站点的图片信息接口（返回当日壁纸的标题与版权说明）
  bg_info_url: 'https://wall.1472588.xyz/info.json',
  // 1 = 每天首次访问首页时，把壁纸标题/版权以顶部公告弹出；0 = 关闭
  bg_info_announce: '1',
  // 弹出频率：0 = 每天只弹一次（同一张图不重复）；1 = 每次打开首页都弹
  bg_info_always: '0',
  // 公告正文怎么拼：auto=标题+版权 / title=仅标题 / custom=自定义模板
  bg_info_mode: 'auto',
  // custom 模式的模板，支持 {title} 与 {copyright} 两个占位符
  bg_info_template: '**今日壁纸：{title}**\n{copyright}',
  // 公告停留秒数（进度条走完自动收起）
  bg_info_seconds: '9',
  bg_image_opacity: '0.3',
  glass_opacity: '0.7',

  // ======== 界面配色 ========
  primary_color: '#5c6bc0',
  font_color: '#333333',
  card_bg: '#ffffff',
  page_bg: '#f7f9fc',
  progress_bar_start: '#7ed957',
  progress_bar_end: '#2e8b57',
  progress_percent_color: '#5c6bc0',

  // ======== 页面标题 ========
  title: '什么时候放假啊o(╥﹏╥)o',

  // ======== 公告设置 ========
  announcement_auto_close: '5',
};

/**
 * 允许写入 settings 表的键（白名单）
 * 新增配置项时记得同步加到 DEFAULT_SETTINGS 里，否则保存会被静默丢弃。
 */
export const SETTINGS_ALLOWED_KEYS = new Set(Object.keys(DEFAULT_SETTINGS));

/**
 * 这些键在库里以 JSON 字符串存储，读出时解析为数组，写入时序列化。
 */
export const JSON_SETTINGS_KEYS = new Set(['holidays', 'workdays']);

/** 壁纸信息公告：合法的正文拼装模式，以及停留秒数的允许区间 */
export const BG_INFO_MODES = ['auto', 'title', 'custom'];
export const BG_INFO_SECONDS_MIN = 3;
export const BG_INFO_SECONDS_MAX = 60;

/** 会话 Cookie 名 */
export const SESSION_COOKIE = 'holiday_session';

/** CSRF Cookie 名（double-submit 方案，需前端可读，故不设 HttpOnly） */
export const CSRF_COOKIE = 'holiday_csrf';

/** 只接受 http(s) 地址，避免把 javascript: / file: 之类的值写进配置 */
export function isHttpUrl(value) {
  return /^https?:\/\//i.test(String(value || '').trim());
}

/**
 * 把少数配置项收敛到合法范围，避免前端传脏值把首页写崩。
 * 与 VPS 版的 normalize_setting() 行为保持一致。
 */
export function normalizeSetting(key, value) {
  if (key === 'bg_info_mode') {
    return BG_INFO_MODES.includes(value) ? value : 'auto';
  }

  if (key === 'bg_info_seconds') {
    let seconds = parseInt(value, 10);
    if (!Number.isFinite(seconds)) seconds = 9;
    return String(Math.max(BG_INFO_SECONDS_MIN, Math.min(BG_INFO_SECONDS_MAX, seconds)));
  }

  if (key === 'bg_info_announce' || key === 'bg_info_always') {
    // 两个都是开关：announce 默认开、always 默认关，只认 0/1 语义
    const fallback = key === 'bg_info_announce' ? '1' : '0';
    const raw = String(value ?? '').trim();
    if (['0', 'false', 'False', ''].includes(raw)) return '0';
    if (['1', 'true', 'True', 'on'].includes(raw)) return '1';
    return fallback;
  }

  if (key === 'bg_info_template') {
    return String(value ?? '').replace(/\r\n/g, '\n').slice(0, 500);
  }

  if (key === 'bg_info_url' || key === 'bg_upstream_url') {
    return String(value ?? '').trim().slice(0, 500);
  }

  if (key === 'bg_image_url') {
    // 只允许站内相对路径。老后台（或旧版缓存页面）可能还在提交第三方直连地址，
    // 那种值会把浏览器重新拉回"绕过 /bg 直接拉原图"的老路，这里统一掰回 /bg。
    const raw = String(value ?? '').trim();
    return raw.startsWith('/') ? raw.slice(0, 500) : '/bg';
  }

  return value;
}
