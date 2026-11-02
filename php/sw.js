const CACHE_NAME = 'semester-countdown-v2';
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

// 安装：缓存静态资源（不含 PHP）
self.addEventListener('install', event => {
    event.waitUntil(
        caches.open(CACHE_NAME).then(cache => {
            return cache.addAll(STATIC_URLS);
        })
    );
});

// 激活：清理旧缓存
self.addEventListener('activate', event => {
    event.waitUntil(
        caches.keys().then(keys => {
            return Promise.all(
                keys.filter(key => key !== CACHE_NAME)
                    .map(key => caches.delete(key))
            );
        })
    );
});

// 拦截请求
self.addEventListener('fetch', event => {
    const url = new URL(event.request.url);
    const isStatic = STATIC_URLS.some(s => url.pathname.endsWith(s));

    if (isStatic) {
        // 静态资源：缓存优先
        event.respondWith(
            caches.match(event.request).then(cached => cached || fetch(event.request))
        );
    } else if (event.request.mode === 'navigate' || url.pathname.endsWith('.php')) {
        // PHP 页面 / 导航：网络优先，缓存兜底
        event.respondWith(
            fetch(event.request).then(response => {
                const clone = response.clone();
                caches.open(CACHE_NAME).then(cache => cache.put(event.request, clone));
                return response;
            }).catch(() => caches.match(event.request))
        );
    } else {
        // 其他：网络优先
        event.respondWith(
            fetch(event.request).catch(() => caches.match(event.request))
        );
    }
});
