(function () {
  'use strict';

  if (!window.wchTracker || !window.fetch) {
    return;
  }

  function getDeviceType() {
    var ua = (navigator.userAgent || '').toLowerCase();
    if (ua.indexOf('ipad') !== -1 || ua.indexOf('tablet') !== -1) return 'tablet';
    if (ua.indexOf('mobile') !== -1 || ua.indexOf('iphone') !== -1 || ua.indexOf('android') !== -1) return 'mobile';
    return 'desktop';
  }

  function clamp01(v) {
    if (v < 0) return 0;
    if (v > 1) return 1;
    return v;
  }

  function sendPoint(clientX, clientY) {
    var viewportW = window.innerWidth || document.documentElement.clientWidth || 1;
    var viewportH = window.innerHeight || document.documentElement.clientHeight || 1;
    var doc = document.documentElement;
    var docW = Math.max(doc.scrollWidth, doc.clientWidth, viewportW);
    var docH = Math.max(doc.scrollHeight, doc.clientHeight, viewportH);

    var xRatio = clamp01(clientX / viewportW);
    var yRatio = clamp01((clientY + (window.scrollY || window.pageYOffset || 0)) / docH);

    var payload = {
      path: window.wchTracker.path || '/',
      post_id: window.wchTracker.postId || null,
      x_ratio: xRatio,
      y_ratio: yRatio,
      viewport_w: viewportW,
      viewport_h: viewportH,
      doc_w: docW,
      doc_h: docH,
      device_type: getDeviceType()
    };

    fetch(window.wchTracker.restUrl, {
      method: 'POST',
      credentials: 'omit',
      keepalive: true,
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': window.wchTracker.nonce || ''
      },
      body: JSON.stringify(payload)
    }).catch(function () {});
  }

  document.addEventListener('click', function (event) {
    if (!event || typeof event.clientX !== 'number') return;
    sendPoint(event.clientX, event.clientY);
  }, { passive: true });

  document.addEventListener('touchend', function (event) {
    if (!event.changedTouches || !event.changedTouches.length) return;
    var touch = event.changedTouches[0];
    sendPoint(touch.clientX, touch.clientY);
  }, { passive: true });
})();
