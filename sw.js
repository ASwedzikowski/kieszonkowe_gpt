// sw.js – najprostszy możliwy SW z obsługą fetch

self.addEventListener('install', (event) => {
  // od razu aktywuj nowego SW
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(self.clients.claim());
});

// Proste przekazywanie wszystkich żądań do sieci
self.addEventListener('fetch', (event) => {
  event.respondWith(fetch(event.request));
});
