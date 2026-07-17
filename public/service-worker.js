const CACHE_NAME = 'mytakii-intranet-v45';
const STATIC_ASSETS = [
  '/offline.html',
  '/assets/app.css',
  '/assets/app.js',
  '/assets/pwa.js',
  '/assets/templates-editor.js',
  '/assets/icon.svg',
  '/assets/icon-maskable.svg',
  '/assets/icon-192.png',
  '/assets/icon-512.png',
  '/assets/icon-maskable-512.png',
  '/assets/apple-touch-icon.png',
  '/vendor/grapesjs/0.23.2/grapes.min.css',
  '/vendor/grapesjs/0.23.2/grapes.min.js',
  '/vendor/html2canvas/1.4.1/html2canvas.min.js',
  '/vendor/jspdf/4.2.1/jspdf.umd.min.js',
  '/manifest.webmanifest'
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(STATIC_ASSETS))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) => Promise.all(
      keys.filter((key) => key !== CACHE_NAME).map((key) => caches.delete(key))
    ))
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);

  if (request.method !== 'GET' || url.origin !== self.location.origin) {
    return;
  }

  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/vendor/') || url.pathname === '/manifest.webmanifest') {
    event.respondWith(
      fetch(request)
        .then((response) => {
          if (response.ok) {
            const copy = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(request, copy));
          }

          return response;
        })
        .catch(() => caches.match(request))
    );
    return;
  }

  if (request.mode === 'navigate') {
    event.respondWith(
      fetch(request).catch(() => caches.match('/offline.html'))
    );
  }
});

self.addEventListener('push', (event) => {
  const fallback = {
    title: 'MyTakii Intranet',
    body: 'Yeni bir bildiriminiz var.',
    url: '/',
    tag: 'mytakii-notification'
  };
  let payload = fallback;

  if (event.data) {
    try {
      payload = event.data.json();
    } catch (error) {
      payload = {
        ...fallback,
        body: event.data.text() || fallback.body
      };
    }
  }
  const targetUrl = new URL(payload.url || fallback.url, self.location.origin).href;

  event.waitUntil(
    self.registration.showNotification(payload.title || fallback.title, {
      body: payload.body || fallback.body,
      icon: '/assets/icon-192.png',
      badge: '/assets/icon-192.png',
      tag: payload.tag || fallback.tag,
      renotify: true,
      data: {
        url: targetUrl
      }
    })
  );
});

self.addEventListener('notificationclick', (event) => {
  event.notification.close();
  const targetUrl = new URL(event.notification.data?.url || '/', self.location.origin).href;

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ('focus' in client && client.url.includes(self.location.origin)) {
          client.navigate(targetUrl);
          return client.focus();
        }
      }

      return clients.openWindow(targetUrl);
    })
  );
});
