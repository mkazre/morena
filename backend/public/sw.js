// Morena CRM service worker: makes the portal installable and quick to open on phones.
// Static files (pages, scripts, styles, icons) are served from cache and refreshed in the background.
// The API (/api, /sanctum) and file downloads are NEVER cached — client data stays on the server (POPIA).
const VERSION = 'morena-v1';
const OFFLINE = '/offline.html';

self.addEventListener('install', (e) => {
  e.waitUntil(caches.open(VERSION).then((c) => c.addAll([OFFLINE, '/assets/app.js', '/assets/ui.css', '/assets/logo.png', '/assets/icons/icon-192.png'])).then(() => self.skipWaiting()));
});

self.addEventListener('activate', (e) => {
  e.waitUntil(caches.keys().then((keys) => Promise.all(keys.filter((k) => k !== VERSION).map((k) => caches.delete(k)))).then(() => self.clients.claim()));
});

self.addEventListener('fetch', (e) => {
  const req = e.request;
  const url = new URL(req.url);
  if (req.method !== 'GET' || url.origin !== location.origin) return;
  if (/^\/(api|sanctum|calendar|u)\//.test(url.pathname) || url.pathname.includes('/download')) return; // always live

  if (req.mode === 'navigate') {
    // Pages: network first (always the latest), cached copy if offline, else the offline page.
    e.respondWith(fetch(req).then((res) => { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); return res; })
      .catch(() => caches.match(req).then((r) => r || caches.match(OFFLINE))));
    return;
  }
  if (/\.(js|css|png|jpg|svg|woff2?|webmanifest)$/.test(url.pathname)) {
    // Assets: network first too, so a deploy never mixes an old script with a new page; cache only when offline.
    e.respondWith(fetch(req).then((res) => { if (res.ok) { const copy = res.clone(); caches.open(VERSION).then((c) => c.put(req, copy)); } return res; })
      .catch(() => caches.match(req)));
  }
});
