const PREFIX = 'flunkyball-';
const CACHE = PREFIX + 'v2';
const SHELL = ['./', 'index.html', 'style.css', 'script.js', 'manifest.webmanifest', 'icons/icon-192.png', 'icons/icon-512.png'];

self.addEventListener('install', e => {
    e.waitUntil(caches.open(CACHE).then(c => c.addAll(SHELL)).then(() => self.skipWaiting()));
});

self.addEventListener('activate', e => {
    e.waitUntil(
        caches.keys()
            // Caches are shared by every app on the origin, so only touch our own.
            .then(keys => Promise.all(keys.filter(k => k.startsWith(PREFIX) && k !== CACHE).map(k => caches.delete(k))))
            .then(() => self.clients.claim()),
    );
});

// Network first so deploys show up immediately; the cache only helps when offline.
// 'no-cache' revalidates with the server, because it sends no cache headers and browsers
// would otherwise guess a freshness period and keep serving an old script.js.
// API calls and the admin page are never cached.
self.addEventListener('fetch', e => {
    const req = e.request;
    const url = new URL(req.url);
    if (req.method !== 'GET' || url.origin !== self.location.origin) return;
    if (url.pathname.includes('/api/') || /\/admin\.(html|js)$/.test(url.pathname)) return;
    e.respondWith(
        fetch(req, { cache: 'no-cache' })
            .then(res => {
                if (res.status === 200 && res.type === 'basic') {
                    const copy = res.clone();
                    caches.open(CACHE).then(c => c.put(req, copy));
                }
                return res;
            })
            .catch(() => caches.match(req, { ignoreSearch: true })),
    );
});
