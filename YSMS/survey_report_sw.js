/* Offline support for Survey Reports (survey_reports.php, survey_report_public.php only — registered
   with scope "./survey_report", so no other YSMS page is touched).
   - Pages and the data of a report (ajax/survey_reports.php?a=get / list): network first, the
     last copy when offline.
   - Scripts, styles, fonts, images (incl. the CDN ones the page uses): cached, refreshed in the
     background.
   - The template workbook (a=template): kept once fetched (a template version never changes).
   POST requests (saving, making files) always go to the network; the editor keeps unsaved changes
   on the device itself (localStorage) and sends them when the connection is back. */
'use strict';
var CACHE = 'ysms-sr-v1';

self.addEventListener('install', function () { self.skipWaiting(); });
self.addEventListener('activate', function (ev) {
  ev.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k.indexOf('ysms-sr-v') === 0 && k !== CACHE; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

function networkFirst(req) {
  return fetch(req).then(function (res) {
    if (res && res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
    return res;
  }).catch(function () { return caches.match(req).then(function (hit) { return hit || Response.error(); }); });
}
function staleWhileRevalidate(req) {
  return caches.match(req).then(function (hit) {
    var net = fetch(req).then(function (res) {
      if (res && (res.ok || res.type === 'opaque')) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
      return res;
    }).catch(function () { return hit || Response.error(); });
    return hit || net;
  });
}

self.addEventListener('fetch', function (ev) {
  var req = ev.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  var same = url.origin === self.location.origin;
  if (same && /\/ajax\/survey_reports\.php$/.test(url.pathname)) {
    var a = url.searchParams.get('a');
    if (a === 'template') { ev.respondWith(caches.match(req).then(function (hit) { return hit || networkFirst(req); })); return; }
    if (a === 'get' || a === 'list') { ev.respondWith(networkFirst(req)); return; }
    return; // files (downloads) and the rest: network only
  }
  if (req.mode === 'navigate') {
    // ?draft=<x> (a report started without internet) and a landing page with a query: the copy kept on the device
    var shell = same && url.searchParams.has('draft') ? new URL('survey_report_public.php?draft=shell', self.registration.scope).href : null;
    ev.respondWith(fetch(req).then(function (res) {
      if (res && res.ok && !shell) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
      return res;
    }).catch(function () {
      var landing = !shell && !url.searchParams.has('t') && !url.searchParams.has('id'); // never another report's page
      return caches.match(shell || req)
        .then(function (hit) { return hit || (landing ? caches.match(new URL('survey_report_public.php', self.registration.scope).href) : undefined); })
        .then(function (hit) { return hit || Response.error(); });
    }));
    return;
  }
  if (['script', 'style', 'font', 'image'].indexOf(req.destination) >= 0) { ev.respondWith(staleWhileRevalidate(req)); return; }
});
