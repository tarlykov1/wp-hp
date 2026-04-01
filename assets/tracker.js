(function () {
  'use strict';

  if (!window.wchTracker || !window.fetch) {
    return;
  }

  var lastTouch = null;
  var recentlySent = {};

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

  function cssEscapeSimple(str) {
    return (str || '').replace(/[^a-zA-Z0-9_-]/g, '');
  }

  function normalizeSelector(selector) {
    if (!selector) return null;
    var normalized = String(selector).replace(/\s+/g, ' ').trim();
    if (!normalized) return null;
    return normalized.slice(0, 255);
  }

  function isSelectorBlockedTarget(el) {
    if (!el || !el.matches) return false;
    return el.matches('input, textarea, select, option, [contenteditable="true"]');
  }

  function isSensitiveElement(target) {
    if (!target || !target.closest) return false;

    if (target.closest('input[type="password"], input[type="email"], input[type="tel"], input[type="number"]')) return true;
    if (target.closest('[autocomplete="cc-number"], [autocomplete="cc-csc"], [autocomplete="cc-exp"], [autocomplete="one-time-code"]')) return true;
    if (target.closest('[name*="password" i], [name*="pass" i], [name*="card" i], [name*="cvc" i], [name*="cvv" i], [name*="iban" i]')) return true;

    if (target.closest('form[action*="login" i], form[id*="login" i], form[class*="login" i], form[action*="checkout" i], form[action*="pay" i], form[id*="checkout" i], form[class*="checkout" i], form[class*="payment" i]')) {
      return true;
    }

    return false;
  }

  function detectSelector(target) {
    if (!target || !target.tagName) return null;
    if (isSelectorBlockedTarget(target)) return null;

    var chunks = [];
    var node = target;
    var depth = 0;

    while (node && node.tagName && depth < 3) {
      var part = node.tagName.toLowerCase();
      if (node.id) {
        part += '#' + cssEscapeSimple(node.id);
        chunks.unshift(part);
        break;
      }

      var className = (node.className && typeof node.className === 'string') ? node.className.split(/\s+/)[0] : '';
      if (className) part += '.' + cssEscapeSimple(className);

      chunks.unshift(part);
      node = node.parentElement;
      depth++;
    }

    return normalizeSelector(chunks.join(' > '));
  }

  function shouldTrack() {
    if (!window.wchTracker.requireConsent) return true;
    return !!window.wchConsentGranted;
  }

  function makeEventId(type, x, y) {
    var bucket = Math.round(Date.now() / 250);
    return [type, bucket, Math.round(x), Math.round(y), window.wchTracker.path || '/'].join(':');
  }

  function isDuplicateClientSide(eventId) {
    var now = Date.now();
    var found = recentlySent[eventId];
    recentlySent[eventId] = now;

    Object.keys(recentlySent).forEach(function (key) {
      if (now - recentlySent[key] > 3000) delete recentlySent[key];
    });

    return !!found;
  }

  function sendPoint(clientX, clientY, target, eventType) {
    if (!shouldTrack()) return;
    if (isSensitiveElement(target)) return;

    var viewportW = window.innerWidth || document.documentElement.clientWidth || 1;
    var viewportH = window.innerHeight || document.documentElement.clientHeight || 1;
    var doc = document.documentElement;
    var docW = Math.max(doc.scrollWidth, doc.clientWidth, viewportW);
    var docH = Math.max(doc.scrollHeight, doc.clientHeight, viewportH);

    var xRatio = clamp01(clientX / viewportW);
    var yRatio = clamp01((clientY + (window.scrollY || window.pageYOffset || 0)) / docH);

    var eventId = makeEventId(eventType, clientX, clientY);
    if (isDuplicateClientSide(eventId)) return;

    var payload = {
      path: window.wchTracker.path || '/',
      post_id: window.wchTracker.postId || null,
      x_ratio: xRatio,
      y_ratio: yRatio,
      viewport_w: viewportW,
      viewport_h: viewportH,
      doc_w: docW,
      doc_h: docH,
      device_type: getDeviceType(),
      target_selector: detectSelector(target),
      event_id: eventId,
      consent: shouldTrack()
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

    if (lastTouch) {
      var dt = Date.now() - lastTouch.time;
      var dx = Math.abs(lastTouch.x - event.clientX);
      var dy = Math.abs(lastTouch.y - event.clientY);
      if (dt < 700 && dx < 20 && dy < 20) return;
    }

    sendPoint(event.clientX, event.clientY, event.target, 'click');
  }, { passive: true });

  document.addEventListener('touchend', function (event) {
    if (!event.changedTouches || !event.changedTouches.length) return;
    var touch = event.changedTouches[0];
    lastTouch = { x: touch.clientX, y: touch.clientY, time: Date.now() };
    sendPoint(touch.clientX, touch.clientY, event.target, 'touch');
  }, { passive: true });
})();
