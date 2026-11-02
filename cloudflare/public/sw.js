const CACHE_NAME = 'semester-countdown-v3';
const STATIC_URLS = [
    'icons/icon-72.png',
    'icons/icon-96.png',
    'icons/icon-128.png',
    'icons/icon-144.png',
    'icons/icon-152.png',
    'icons/icon-192.png',
    'icons/icon-384.png',
    'icons/icon-512.png',
    'manifest.json'
];

// 安装：缓存静态资源（不含页面和接口）
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_URLS);
        })
    );
    self.skipWaiting();
});

// 激活：清理旧缓存并立即接管页面，避免用户需要关掉所有标签页才更新
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        }).then(() => self.clients.claim())
    );
});

// 拦截请求
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);

    // 只接管同源 GET 请求
    if (url.origin !== self.location.origin) return;
    if (event.request.method !== 'GET') return;

    // 接口请求一律交给浏览器处理：
    // /api/* 携带登录态和实时配置，缓存会导致读到过期数据，甚至干扰登录流程
    if (url.pathname.startsWith('/api/')) return;

    const isStatic = STATIC_URLS.some(s => url.pathname.endsWith(s));

    if (isStatic) {
        // 静态资源：缓存优先
        event.respondWith(
            caches.match(event.request).then(cached => cached || fetch(event.request))
        );
    } else if (event.request.mode === 'navigate' || url.pathname.endsWith('.html')) {
        // 页面导航：网络优先，缓存兜底（离线可看）
        event.respondWith(
            fetch(event.request).then(response => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                return response;
            }).catch(() => caches.match(event.request))
        );
    }
    // 其余请求不拦截，走浏览器默认行为
});
