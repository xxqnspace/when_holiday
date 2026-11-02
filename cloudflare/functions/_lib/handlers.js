/**
 * API 处理器
 *
 * 对应原 PHP 版的 api.php，覆盖全部 action。
 * 请求方式与原版保持一致的语义，但把 FormData 换成了 JSON：
 *   GET  /api/<action>         读操作
 *   POST /api/<action>         写操作（JSON body）
 */

import { CSRF_COOKIE, SESSION_COOKIE } from './config.js';
import { ok, fail, jsonResponse, readJsonBody, parseCookies } from './util.js';
import {
  ensureSeed,
  getSettings,
  saveSettings,
  findUserByUsername,
  findUserById,
  listUsers,
  createUser,
  updateUser,
  deleteUser,
  listAnnouncements,
  getActiveAnnouncement,
  saveAnnouncement,
  deleteAnnouncement,
} from './db.js';
import {
  hashPassword,
  verifyPassword,
  createSession,
  deleteSession,
  readSession,
  sessionCookieHeader,
  clearSessionCookieHeader,
  ensureCsrfCookieHeader,
  verifyCsrf,
} from './auth.js';

const VALID_ROLES = ['admin', 'user'];

// ==================== 入口 ====================

/**
 * Pages Functions 的统一入口
 * @param {{request: Request, env: any, params: any}} context
 */
export async function handleApi(context) {
  const { request, env, params } = context;

  if (!env.DB) {
    return fail(
      'D1 数据库未绑定。请在 Cloudflare 控制台的 Pages 项目 → Settings → Bindings 添加 D1 database binding，变量名填 DB',
    );
  }

  // 优先取 Pages 路由参数（[[action]] 捕获的是数组）；万一没取到则退回解析 URL 路径，
  // 这样即使以后调整了 functions/ 下的文件名，接口也不会整体失效。
  const raw = params?.action;
  let action = Array.isArray(raw) ? raw[0] || '' : raw || '';
  if (!action) {
    const segments = new URL(request.url).pathname.split('/').filter(Boolean);
    action = segments[1] || '';
  }
  const method = (request.method || 'GET').toUpperCase();

  // CSRF：double-submit cookie。首次访问没有 cookie 时在这里生成，
  // 这样登录前的接口也能拿到 token。
  const cookies = parseCookies(request);
  let csrfToken = cookies[CSRF_COOKIE] || '';
  let csrfSetCookie = null;
  if (!csrfToken) {
    const generated = ensureCsrfCookieHeader(request, null);
    csrfToken = generated.token;
    csrfSetCookie = generated.header;
  }

  const ctx = { request, env, method, action, csrfToken, cookies };

  let response;
  try {
    await ensureSeed(env);
    response = await route(ctx);
  } catch (err) {
    console.error(`[api:${action}]`, err && err.stack ? err.stack : err);
    response = fail('服务器内部错误：' + (err && err.message ? err.message : String(err)));
  }

  if (csrfSetCookie) {
    const headers = new Headers(response.headers);
    headers.append('Set-Cookie', csrfSetCookie);
    response = new Response(response.body, { status: response.status, headers });
  }

  return response;
}

// ==================== 路由 ====================

async function route(ctx) {
  const { action, method } = ctx;
  const isPost = method === 'POST';
  const postOnly = () => fail(`操作 ${action} 需要使用 POST 请求`);

  switch (action) {
    // 认证
    case 'session':
      return handleSession(ctx);
    case 'login':
      return isPost ? handleLogin(ctx) : postOnly();
    case 'logout':
      return isPost ? handleLogout(ctx) : postOnly();
    case 'change_password':
      return isPost ? handleChangePassword(ctx) : postOnly();

    // 用户管理
    case 'get_users':
      return handleGetUsers(ctx);
    case 'create_user':
      return isPost ? handleCreateUser(ctx) : postOnly();
    case 'update_user':
      return isPost ? handleUpdateUser(ctx) : postOnly();
    case 'delete_user':
      return isPost ? handleDeleteUser(ctx) : postOnly();

    // 配置
    case 'get_settings':
      return handleGetSettings(ctx);
    case 'save_settings':
      return isPost ? handleSaveSettings(ctx) : postOnly();
    case 'get_mode':
      return handleGetMode(ctx);

    // 公告
    case 'get_announcement':
      return handleGetAnnouncement(ctx);
    case 'get_announcements':
      return handleGetAnnouncements(ctx);
    case 'save_announcement':
      return isPost ? handleSaveAnnouncement(ctx) : postOnly();
    case 'delete_announcement':
      return isPost ? handleDeleteAnnouncement(ctx) : postOnly();

    default:
      return fail('未知操作：' + action);
  }
}

// ==================== 权限守卫 ====================

/** 要求已登录，返回 { session } 或 { error: Response } */
async function requireLogin(ctx) {
  const session = await readSession(ctx.env, ctx.request);
  if (!session) return { error: fail('请先登录') };
  return { session };
}

/** 要求管理员权限 */
async function requireAdmin(ctx) {
  const guard = await requireLogin(ctx);
  if (guard.error) return guard;
  if (guard.session.role !== 'admin') return { error: fail('需要管理员权限') };
  return guard;
}

/** CSRF 校验，通过返回 null */
function requireCsrf(ctx) {
  if (!verifyCsrf(ctx.request)) {
    return fail('安全校验失败，请刷新页面重试');
  }
  return null;
}

// ==================== 认证 ====================

/** GET /api/session —— 查询当前登录态 */
async function handleSession(ctx) {
  const session = await readSession(ctx.env, ctx.request);
  return ok({
    logged_in: !!session,
    user: session
      ? { id: session.user_id, username: session.username, role: session.role }
      : null,
    csrf_token: ctx.csrfToken,
  });
}

/** POST /api/login */
async function handleLogin(ctx) {
  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  const username = String(body.username || '').trim();
  const password = String(body.password || '');

  if (!username || !password) {
    return fail('用户名和密码不能为空');
  }

  const user = await findUserByUsername(ctx.env, username);
  if (!user || !(await verifyPassword(password, user.password, ctx.env))) {
    return fail('用户名或密码错误');
  }

  const { token, maxAge } = await createSession(ctx.env, user);

  return jsonResponse(
    {
      success: true,
      message: '登录成功',
      data: { id: user.id, username: user.username, role: user.role },
    },
    200,
    { 'Set-Cookie': sessionCookieHeader(token, maxAge, ctx.request) },
  );
}

/** POST /api/logout */
async function handleLogout(ctx) {
  await deleteSession(ctx.env, ctx.cookies[SESSION_COOKIE]);
  return jsonResponse(
    { success: true, message: '已退出登录', data: null },
    200,
    { 'Set-Cookie': clearSessionCookieHeader(ctx.request) },
  );
}

/** POST /api/change_password */
async function handleChangePassword(ctx) {
  const guard = await requireLogin(ctx);
  if (guard.error) return guard.error;

  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  const oldPassword = String(body.old_password || '');
  const newPassword = String(body.new_password || '');

  if (newPassword.length < 6) {
    return fail('新密码至少6位');
  }

  const user = await findUserById(ctx.env, guard.session.user_id);
  if (!user || !(await verifyPassword(oldPassword, user.password, ctx.env))) {
    return fail('原密码错误');
  }

  await updateUser(ctx.env, user.id, { role: user.role, newPassword });
  return ok(null, '密码修改成功');
}

// ==================== 用户管理 ====================

/** GET /api/get_users */
async function handleGetUsers(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;
  return ok(await listUsers(ctx.env));
}

/** POST /api/create_user */
async function handleCreateUser(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;

  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  const username = String(body.username || '').trim();
  const password = String(body.password || '');
  const role = String(body.role || 'user');

  if (!username || password.length < 6) {
    return fail('用户名不能为空，密码至少6位');
  }
  if (!VALID_ROLES.includes(role)) {
    return fail('角色无效');
  }
  if (await findUserByUsername(ctx.env, username)) {
    return fail('用户名已存在');
  }

  try {
    await createUser(ctx.env, { username, password, role });
  } catch (err) {
    // 并发下 UNIQUE 约束兜底
    if (String(err && err.message).includes('UNIQUE')) {
      return fail('用户名已存在');
    }
    throw err;
  }

  return ok(null, '用户创建成功');
}

/** POST /api/update_user */
async function handleUpdateUser(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;

  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  const userId = parseInt(body.user_id, 10) || 0;
  const role = String(body.role || '');
  const newPassword = String(body.new_password || '');

  if (userId <= 0) return fail('用户ID无效');
  if (!VALID_ROLES.includes(role)) return fail('角色无效');
  if (newPassword && newPassword.length < 6) return fail('新密码至少6位');
  if (!(await findUserById(ctx.env, userId))) return fail('用户不存在');

  await updateUser(ctx.env, userId, { role, newPassword });
  return ok(null, '用户更新成功');
}

/** POST /api/delete_user */
async function handleDeleteUser(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;

  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  const userId = parseInt(body.user_id, 10) || 0;

  if (userId <= 0) return fail('用户ID无效');
  if (userId === guard.session.user_id) return fail('不能删除自己的账号');

  await deleteUser(ctx.env, userId);
  return ok(null, '用户已删除');
}

// ==================== 配置 ====================

/** GET /api/get_settings —— 公开接口，首页也要读 */
async function handleGetSettings(ctx) {
  return ok(await getSettings(ctx.env));
}

/** POST /api/save_settings */
async function handleSaveSettings(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;

  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  let settings = body.settings;

  // 兼容前端传 JSON 字符串的写法
  if (typeof settings === 'string') {
    try {
      settings = JSON.parse(settings);
    } catch {
      settings = null;
    }
  }
  if (!settings || typeof settings !== 'object') {
    return fail('无效的配置数据');
  }

  await saveSettings(ctx.env, settings);
  return ok(null, '配置保存成功');
}

/** GET /api/get_mode */
async function handleGetMode(ctx) {
  const settings = await getSettings(ctx.env);
  return ok({ mode: settings.mode || 'online' });
}

// ==================== 公告 ====================

/** GET /api/get_announcement —— 公开接口，返回当前生效的公告 */
async function handleGetAnnouncement(ctx) {
  return ok(await getActiveAnnouncement(ctx.env));
}

/** GET /api/get_announcements */
async function handleGetAnnouncements(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;
  return ok(await listAnnouncements(ctx.env));
}

/** POST /api/save_announcement */
async function handleSaveAnnouncement(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;

  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  const id = body.id ? parseInt(body.id, 10) : null;

  await saveAnnouncement(ctx.env, {
    id,
    content: String(body.content ?? ''),
    // 留空表示不限时间，统一转成 null
    showFrom: body.show_from ? String(body.show_from) : null,
    showUntil: body.show_until ? String(body.show_until) : null,
    isActive: parseInt(body.is_active ?? 1, 10) ? 1 : 0,
  });

  return ok(null, '公告保存成功');
}

/** POST /api/delete_announcement */
async function handleDeleteAnnouncement(ctx) {
  const guard = await requireAdmin(ctx);
  if (guard.error) return guard.error;

  const csrfError = requireCsrf(ctx);
  if (csrfError) return csrfError;

  const body = await readJsonBody(ctx.request);
  const id = parseInt(body.id, 10) || 0;
  if (id <= 0) return fail('公告ID无效');

  await deleteAnnouncement(ctx.env, id);
  return ok(null, '公告已删除');
}
