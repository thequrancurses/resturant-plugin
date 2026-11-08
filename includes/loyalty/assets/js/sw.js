/* Loyalty Stamps — push service worker (served from /ls-sw.js) */
self.addEventListener('push', function (event) {
	var data = { title: 'Loyalty', body: '', url: '/' };
	try {
		if (event.data) data = Object.assign(data, event.data.json());
	} catch (e) { /* ignore */ }
	event.waitUntil(
		self.registration.showNotification(data.title, {
			body: data.body,
			data: { url: data.url || '/' }
		})
	);
});

self.addEventListener('notificationclick', function (event) {
	event.notification.close();
	var url = (event.notification.data && event.notification.data.url) || '/';
	event.waitUntil(clients.openWindow(url));
});
