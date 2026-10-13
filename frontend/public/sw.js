const CACHE = 'trackforge-qm-v1';
const APP_SHELL = ['/', '/manifest.webmanifest'];
self.addEventListener('install', event => { event.waitUntil(caches.open(CACHE).then(cache => cache.addAll(APP_SHELL))); self.skipWaiting(); });
self.addEventListener('activate', event => { event.waitUntil(self.clients.claim()); });
self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  event.respondWith(fetch(req).then(res => { const copy=res.clone(); caches.open(CACHE).then(cache=>cache.put(req, copy)); return res; }).catch(()=>caches.match(req).then(cached=>cached || caches.match('/'))));
});
self.addEventListener('push', event => {
  const data = event.data ? event.data.json() : {title:'TrackForge QM', body:'New notification'};
  event.waitUntil(self.registration.showNotification(data.title || 'TrackForge QM', {body:data.body || data.message || '', icon:'/icons/icon-192.png', data}));
});
self.addEventListener('notificationclick', event => { event.notification.close(); event.waitUntil(clients.openWindow(event.notification.data?.url || '/notifications')); });
