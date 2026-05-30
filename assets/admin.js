(function () {
  'use strict';

  if (!window.wchAdmin) return;

  var pathInput = document.getElementById('wch-path');
  var pageSelect = document.getElementById('wch-page-select');
  var dateFrom = document.getElementById('wch-date-from');
  var dateTo = document.getElementById('wch-date-to');
  var deviceType = document.getElementById('wch-device-type');
  var minWeight = document.getElementById('wch-min-weight');
  var modeSelect = document.getElementById('wch-mode');
  var loadBtn = document.getElementById('wch-load');
  var resetBtn = document.getElementById('wch-reset');
  var loader = document.getElementById('wch-loader');
  var iframe = document.getElementById('wch-preview');
  var layer = document.getElementById('wch-heatmap-layer');
  var statusEl = document.getElementById('wch-status');
  var totalClicksEl = document.getElementById('wch-total-clicks');
  var uniqueBucketsEl = document.getElementById('wch-unique-buckets');
  var hotZonesEl = document.getElementById('wch-hot-zones');
  var topSelectorsList = document.getElementById('wch-top-selectors-list');
  var previewNotice = document.getElementById('wch-preview-notice');

  var heatmapInstance = null;
  var heatmapContainer = layer;
  var iframeLoadTimer = null;
  var currentItems = [];
  var currentMode = 'heatmap';

  function normalizePath(path) {
    var value = (path || '').trim();
    if (!value) return '/';
    value = '/' + value.replace(/^\/+/, '');
    value = value.replace(/\/{2,}/g, '/');
    if (value.length > 1 && value.endsWith('/')) value = value.slice(0, -1);
    return value;
  }

  function setStatus(text, type) {
    statusEl.textContent = text;
    statusEl.className = 'description ' + (type || '');
  }

  function setPreviewNotice(text, type) {
    if (!previewNotice) return;
    previewNotice.textContent = text || '';
    previewNotice.className = 'wch-preview-notice ' + (type || '');
    previewNotice.hidden = !text;
  }

  function toggleLoading(on) {
    if (!loader) return;
    if (on) {
      loader.classList.add('is-active');
      loadBtn.disabled = true;
    } else {
      loader.classList.remove('is-active');
      loadBtn.disabled = false;
    }
  }

  function buildApiUrl(baseUrl, extraParams) {
    if (!baseUrl) return '';

    var base;
    try {
      base = new URL(baseUrl, window.location.origin);
    } catch (e) {
      if (!extraParams || !extraParams.toString()) return baseUrl;
      return baseUrl + (baseUrl.indexOf('?') === -1 ? '?' : '&') + extraParams.toString();
    }

    if (extraParams && typeof extraParams.forEach === 'function') {
      extraParams.forEach(function (value, key) {
        base.searchParams.set(key, value);
      });
    }

    return base.toString();
  }

  function buildPreviewUrl(path) {
    var baseUrl = window.wchAdmin.homeUrl || window.wchAdmin.siteUrl || window.location.origin;

    try {
      return new URL(path, baseUrl).toString();
    } catch (e) {
      return baseUrl + path;
    }
  }

  function getIframeDocument() {
    try {
      var doc = iframe.contentDocument;
      if (!doc || !doc.documentElement || !doc.body) return null;
      return doc;
    } catch (e) {
      return null;
    }
  }

  function getIframeLayerSize() {
    if (!iframe) return { width: 1, height: 1 };

    var rect = iframe.getBoundingClientRect();
    return {
      width: Math.max(1, Math.round(rect.width || iframe.clientWidth || iframe.offsetWidth || 1)),
      height: Math.max(1, Math.round(rect.height || iframe.clientHeight || iframe.offsetHeight || 1))
    };
  }

  function syncLayerToIframe() {
    if (!layer) return null;

    var size = getIframeLayerSize();
    layer.style.display = 'block';
    layer.style.width = size.width + 'px';
    layer.style.height = size.height + 'px';
    layer.style.top = iframe ? iframe.offsetTop + 'px' : '0';
    layer.style.left = iframe ? iframe.offsetLeft + 'px' : '0';

    return size;
  }

  function clearLayer() {
    if (!heatmapContainer) return;
    heatmapContainer.innerHTML = '';
    heatmapInstance = null;
  }

  function drawHeatmap(items) {
    if (!layer) return setPreviewNotice('Слой теплокарты #wch-heatmap-layer не найден. Карта не отрисована.', 'is-warning');

    var size = syncLayerToIframe();
    if (!size) return;

    heatmapContainer = layer;
    clearLayer();
    layer.style.display = 'block';

    if (!window.simpleHeatmap || typeof window.simpleHeatmap.create !== 'function') {
      return setPreviewNotice('Библиотека теплокарты не загружена. Карта не отрисована.', 'is-warning');
    }

    heatmapInstance = window.simpleHeatmap.create({ container: heatmapContainer, radius: 48, maxOpacity: 0.95, blur: 0.95 });

    var points = items.map(function (item) {
      return {
        x: Math.max(0, Math.min(size.width, item.x_ratio * size.width)),
        y: Math.max(0, Math.min(size.height, item.y_ratio * size.height)),
        value: Math.max(3, item.weight || 1)
      };
    });
    var max = points.reduce(function (acc, p) { return p.value > acc ? p.value : acc; }, 1);
    heatmapInstance.setData({ max: max, data: points });
  }

  function drawClickPoints(items) {
    if (!layer) return setPreviewNotice('Слой теплокарты #wch-heatmap-layer не найден. Точки не отрисованы.', 'is-warning');

    var size = syncLayerToIframe();
    if (!size) return;

    heatmapContainer = layer;
    clearLayer();
    layer.style.display = 'block';

    items.forEach(function (item) {
      var dot = document.createElement('span');
      dot.className = 'wch-click-dot';
      dot.style.left = Math.max(0, Math.min(size.width, item.x_ratio * size.width)) + 'px';
      dot.style.top = Math.max(0, Math.min(size.height, item.y_ratio * size.height)) + 'px';
      if (item.target_selector) dot.title = item.target_selector;
      heatmapContainer.appendChild(dot);
    });
  }

  function renderCurrentData() {
    if (!currentItems.length) return clearLayer();
    syncLayerToIframe();
    if (currentMode === 'click-points') drawClickPoints(currentItems); else drawHeatmap(currentItems);
  }

  function updateSummary(summary, selectors) {
    totalClicksEl.textContent = String((summary && summary.total_clicks) || 0);
    uniqueBucketsEl.textContent = String((summary && summary.unique_buckets) || 0);
    hotZonesEl.textContent = String((summary && summary.hottest_zones_count) || 0);
    topSelectorsList.innerHTML = '';

    var safeSelectors = Array.isArray(selectors) ? selectors : [];
    if (!safeSelectors.length) {
      var emptyLi = document.createElement('li');
      emptyLi.textContent = 'Нет данных';
      emptyLi.className = 'wch-empty-state';
      topSelectorsList.appendChild(emptyLi);
      return;
    }

    safeSelectors.forEach(function (item) {
      var li = document.createElement('li');
      li.textContent = item.selector + ' (' + item.clicks + ')';
      topSelectorsList.appendChild(li);
    });
  }

  function buildQuery(path) {
    var params = new URLSearchParams();
    params.set('path', path);
    params.set('mode', modeSelect.value || 'heatmap');
    params.set('min_weight', String(Math.max(1, parseInt(minWeight.value || '1', 10))));
    if (deviceType.value && deviceType.value !== 'all') params.set('device_type', deviceType.value);
    if (dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo.value) params.set('date_to', dateTo.value);
    return params;
  }

  function resetFilters() {
    pageSelect.value = '';
    pathInput.value = '/';
    dateFrom.value = '';
    dateTo.value = '';
    deviceType.value = 'all';
    minWeight.value = '1';
    modeSelect.value = 'heatmap';
    currentItems = [];
    currentMode = 'heatmap';
    clearLayer();
    setStatus('Фильтры сброшены.', 'is-success');
  }

  function loadData() {
    var path = normalizePath(pathInput.value);
    pathInput.value = path;
    setPreviewNotice('');
    clearLayer();

    if (iframeLoadTimer) clearTimeout(iframeLoadTimer);
    iframe.src = buildPreviewUrl(path);
    iframeLoadTimer = window.setTimeout(function () {
      setPreviewNotice('Предпросмотр страницы не загрузился. Возможна блокировка X-Frame-Options/CSP.', 'is-warning');
    }, 5000);

    toggleLoading(true);
    setStatus('Загрузка данных…');

    var apiUrl = buildApiUrl(window.wchAdmin.heatmapUrl, buildQuery(path));

    fetch(apiUrl, { method: 'GET', credentials: 'same-origin', headers: { 'X-WP-Nonce': window.wchAdmin.nonce || '' } })
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        var safeData = data && typeof data === 'object' ? data : {};
        var items = Array.isArray(safeData.items) ? safeData.items : [];
        currentItems = items;
        currentMode = safeData.mode || 'heatmap';

        if (!items.length) {
          clearLayer();
          setStatus('Нет данных для выбранных фильтров.', 'is-warning');
        } else {
          renderCurrentData();
          setStatus('Загружено точек: ' + items.length, 'is-success');
        }

        updateSummary(safeData.summary || {}, safeData.top_selectors || []);
      })
      .catch(function (err) { setStatus('Не удалось загрузить данные: ' + err.message, 'is-error'); })
      .finally(function () { toggleLoading(false); });
  }

  (window.wchAdmin.pages || []).forEach(function (item) {
    var opt = document.createElement('option');
    opt.value = item.path;
    opt.textContent = item.title + ' (' + item.path + ')';
    pageSelect.appendChild(opt);
  });

  pageSelect.addEventListener('change', function () { if (pageSelect.value) pathInput.value = pageSelect.value; });
  loadBtn.addEventListener('click', loadData);
  resetBtn.addEventListener('click', resetFilters);
  window.addEventListener('resize', renderCurrentData);

  iframe.addEventListener('load', function () {
    if (iframeLoadTimer) clearTimeout(iframeLoadTimer);
    setPreviewNotice('');
    syncLayerToIframe();

    if (!getIframeDocument()) {
      setPreviewNotice('Предпросмотр недоступен из-за ограничений встраивания (X-Frame-Options/CSP), но слой теплокарты отрисован поверх iframe.', 'is-warning');
    }

    renderCurrentData();
  });
})();
