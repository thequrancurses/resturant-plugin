/* Restaurant Menu - service worker: order notifications ("accepted" / "ready") and loyalty notifications (stamps, rewards, offers) */
self.addEventListener("install", function () { self.skipWaiting(); });
self.addEventListener("activate", function (e) { e.waitUntil(self.clients.claim()); });

self.addEventListener("push", function (e) {
  var d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) { d = { body: e.data ? e.data.text() : "" }; }
  e.waitUntil(self.registration.showNotification(d.title || "Order", {
    body: d.body || "",
    tag: d.tag || ("gh-" + Date.now()),   // order pushes share a tag per order: "ready" replaces "accepted"
    renotify: !!d.tag,
    icon: d.icon || undefined,
    badge: d.icon || undefined,
    vibrate: [300, 150, 300, 150, 300],
    requireInteraction: d.event === "ready",
    data: { url: d.url || "/" }
  }));
});

self.addEventListener("notificationclick", function (e) {
  e.notification.close();
  var url = (e.notification.data && e.notification.data.url) || "/";
  e.waitUntil(self.clients.matchAll({ type: "window", includeUncontrolled: true }).then(function (list) {
    for (var i = 0; i < list.length; i++) { if ("focus" in list[i]) return list[i].focus(); }
    return self.clients.openWindow(url);
  }));
});
