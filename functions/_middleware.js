/**
 * Pages 全局中间件（functions/_middleware.js 会对所有路由生效）
 *
 * 只干一件事：把 HTML 里 og:url / og:image 的相对路径补成当前域名的绝对地址。
 *
 * 为什么需要：微信/QQ 分享卡片要求 og 的图片和链接是绝对 URL，相对路径会被忽略。
 * VPS 版是 Python 在返回 HTML 时按请求 Host 拼好的，而 Pages 的静态文件是原样下发的，
 * 所以只能在这一层改写。好处是不用再把域名写死在 HTML 里，换域名/预览域名都能用。
 */

const OG_PATTERN = /(property="og:(?:url|image)"\s+content=")(\/[^"]*)(")/g;

export async function onRequest(context) {
  const { request, next } = context;
  const url = new URL(request.url);

  // 接口和背景图不参与改写
  if (url.pathname.startsWith('/api/') || url.pathname === '/bg') return next();
  // HEAD 没有 body，没必要读一遍再拼回去
  if (request.method !== 'GET') return next();

  const response = await next();

  const contentType = response.headers.get('content-type') || '';
  if (!contentType.includes('text/html')) return response;

  let html;
  try {
    html = await response.text();
  } catch {
    return response;
  }

  if (!html.includes('property="og:')) {
    return new Response(html, {
      status: response.status,
      statusText: response.statusText,
      headers: response.headers,
    });
  }

  const origin = url.origin;
  const patched = html.replace(OG_PATTERN, (_, head, path, tail) => `${head}${origin}${path}${tail}`);

  const headers = new Headers(response.headers);
  // 改写后长度变了，必须去掉原来的 Content-Length，交给运行时重新计算
  headers.delete('content-length');

  return new Response(patched, {
    status: response.status,
    statusText: response.statusText,
    headers,
  });
}
