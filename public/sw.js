const CACHE_NAME = 'rss-reader-v1.0';
const STATIC_CACHE_NAME = 'rss-reader-static-v1.0';
const DYNAMIC_CACHE_NAME = 'rss-reader-dynamic-v1.0';

// Static assets to cache immediately
const STATIC_ASSETS = [
  '/',
  '/manifest.json',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css',
  'https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css',
  'https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js'
];

// Routes that should be cached with network-first strategy
const DYNAMIC_ROUTES = [
  '/dashboard',
  '/feeds',
  '/login'
];

// Install event - cache static assets
self.addEventListener('install', event => {
  console.log('Service Worker installing...');
  event.waitUntil(
    caches.open(STATIC_CACHE_NAME)
      .then(cache => {
        console.log('Caching static assets...');
        return cache.addAll(STATIC_ASSETS);
      })
      .then(() => {
        console.log('Static assets cached successfully');
        return self.skipWaiting();
      })
      .catch(error => {
        console.error('Failed to cache static assets:', error);
      })
  );
});

// Activate event - clean up old caches
self.addEventListener('activate', event => {
  console.log('Service Worker activating...');
  event.waitUntil(
    caches.keys().then(cacheNames => {
      return Promise.all(
        cacheNames.map(cacheName => {
          if (cacheName !== STATIC_CACHE_NAME && 
              cacheName !== DYNAMIC_CACHE_NAME &&
              cacheName !== CACHE_NAME) {
            console.log('Deleting old cache:', cacheName);
            return caches.delete(cacheName);
          }
        })
      );
    }).then(() => {
      console.log('Service Worker activated');
      return self.clients.claim();
    })
  );
});

// Fetch event - implement caching strategies
self.addEventListener('fetch', event => {
  const { request } = event;
  const url = new URL(request.url);

  // Skip non-GET requests
  if (request.method !== 'GET') {
    return;
  }

  // Skip chrome-extension requests
  if (url.protocol === 'chrome-extension:') {
    return;
  }

  // Cache-first strategy for static assets
  if (isStaticAsset(request.url)) {
    event.respondWith(cacheFirstStrategy(request));
    return;
  }

  // Network-first strategy for API and dynamic content
  if (isDynamicContent(request.url)) {
    event.respondWith(networkFirstStrategy(request));
    return;
  }

  // Default: try network first, fallback to cache
  event.respondWith(networkFirstStrategy(request));
});

// Cache-first strategy for static assets
async function cacheFirstStrategy(request) {
  try {
    const cachedResponse = await caches.match(request);
    if (cachedResponse) {
      return cachedResponse;
    }

    const networkResponse = await fetch(request);
    if (networkResponse.ok) {
      const cache = await caches.open(STATIC_CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    return networkResponse;
  } catch (error) {
    console.error('Cache-first strategy failed:', error);
    const cachedResponse = await caches.match(request);
    return cachedResponse || new Response('Offline - Resource not available', {
      status: 503,
      statusText: 'Service Unavailable'
    });
  }
}

// Network-first strategy for dynamic content
async function networkFirstStrategy(request) {
  try {
    const networkResponse = await fetch(request);
    
    if (networkResponse.ok) {
      const cache = await caches.open(DYNAMIC_CACHE_NAME);
      cache.put(request, networkResponse.clone());
    }
    
    return networkResponse;
  } catch (error) {
    console.log('Network failed, trying cache:', error);
    const cachedResponse = await caches.match(request);
    
    if (cachedResponse) {
      return cachedResponse;
    }

    // Return offline page for navigation requests
    if (request.mode === 'navigate') {
      return new Response(`
        <!DOCTYPE html>
        <html>
        <head>
          <meta charset="UTF-8">
          <title>Offline - RSS Reader</title>
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
        </head>
        <body class="bg-light">
          <div class="container mt-5">
            <div class="text-center">
              <h1 class="display-4">📱</h1>
              <h2>You're Offline</h2>
              <p class="lead">Please check your internet connection and try again.</p>
              <button onclick="window.location.reload()" class="btn btn-primary">Retry</button>
            </div>
          </div>
        </body>
        </html>
      `, {
        headers: { 'Content-Type': 'text/html' }
      });
    }

    return new Response('Offline - Resource not available', {
      status: 503,
      statusText: 'Service Unavailable'
    });
  }
}

// Check if request is for static assets
function isStaticAsset(url) {
  return url.includes('bootstrap') || 
         url.includes('.css') || 
         url.includes('.js') || 
         url.includes('.png') || 
         url.includes('.jpg') || 
         url.includes('.svg') ||
         url.includes('.ico') ||
         url.includes('manifest.json');
}

// Check if request is for dynamic content
function isDynamicContent(url) {
  return DYNAMIC_ROUTES.some(route => url.includes(route)) ||
         url.includes('/api/') ||
         url.includes('/feeds/') ||
         url.includes('/dashboard');
}

// Background sync for offline actions (when available)
self.addEventListener('sync', event => {
  console.log('Background sync triggered:', event.tag);
  
  if (event.tag === 'sync-offline-actions') {
    event.waitUntil(syncOfflineActions());
  }
});

// Sync offline actions when connectivity returns
async function syncOfflineActions() {
  try {
    console.log('Syncing offline actions...');
    // This will be expanded when implementing IndexedDB storage
    // For now, just log that sync would happen
    console.log('Offline actions sync completed');
  } catch (error) {
    console.error('Failed to sync offline actions:', error);
    throw error; // This will cause the sync to retry later
  }
}

// Push notification handling (foundation for future implementation)
self.addEventListener('push', event => {
  console.log('Push notification received:', event);
  
  const options = {
    body: event.data ? event.data.text() : 'New RSS content available',
    icon: "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 192 192'><rect width='192' height='192' fill='%23212529'/><text x='96' y='120' font-size='96' text-anchor='middle' fill='white'>📰</text></svg>",
    badge: "data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 128 128'><rect width='128' height='128' fill='%23212529'/><text x='64' y='80' font-size='64' text-anchor='middle' fill='white'>📰</text></svg>",
    tag: 'rss-update',
    requireInteraction: false,
    data: {
      url: '/'
    }
  };

  event.waitUntil(
    self.registration.showNotification('RSS Reader', options)
  );
});

// Handle notification clicks
self.addEventListener('notificationclick', event => {
  console.log('Notification clicked:', event);
  event.notification.close();

  const urlToOpen = event.notification.data?.url || '/';

  event.waitUntil(
    clients.matchAll({ type: 'window', includeUncontrolled: true })
      .then(clientList => {
        // Try to focus existing window
        for (const client of clientList) {
          if (client.url === urlToOpen && 'focus' in client) {
            return client.focus();
          }
        }
        // Open new window if none found
        if (clients.openWindow) {
          return clients.openWindow(urlToOpen);
        }
      })
  );
});

console.log('Service Worker script loaded');