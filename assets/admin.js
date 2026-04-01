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
  var iframeLoadTimer = null;

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

  function buildPreviewUrl(path) {
    return window.location.origin + path;
  }

  function clearLayer() {
    layer.innerHTML = '';
    if (heatmapInstance && typeof heatmapInstance.setData === 'function') {
      heatmapInstance.setData({ max: 1, data: [] });
    }
  }

  function drawHeatmap(items) {
    clearLayer();

    var rect = iframe.getBoundingClientRect();
    layer.style.width = rect.width + 'px';
    layer.style.height = rect.height + 'px';

    if (!heatmapInstance) {
      heatmapInstance = window.simpleHeatmap.create({
        container: layer,
        radius: 36,
        maxOpacity: 0.75,
        blur: 0.9
      });
    }

    var points = items.map(function (item) {
      return {
        x: Math.max(0, Math.min(rect.width, item.x_ratio * rect.width)),
        y: Math.max(0, Math.min(rect.height, item.y_ratio * rect.height)),
        value: item.weight || 1
      };
    });

    var max = points.reduce(function (acc, p) {
      return p.value > acc ? p.value : acc;
    }, 1);

    heatmapInstance.setData({ max: max, data: points });
  }

  function drawClickPoints(items) {
    clearLayer();

    var rect = iframe.getBoundingClientRect();
    layer.style.width = rect.width + 'px';
    layer.style.height = rect.height + 'px';

    items.forEach(function (item) {
      var dot = document.createElement('span');
      dot.className = 'wch-click-dot';
      dot.style.left = Math.max(0, Math.min(rect.width, item.x_ratio * rect.width)) + 'px';
      dot.style.top = Math.max(0, Math.min(rect.height, item.y_ratio * rect.height)) + 'px';
      if (item.target_selector) {
        dot.title = item.target_selector;
      }
      layer.appendChild(dot);
    });
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

    return params.toString();
  }

  function resetFilters() {
    pageSelect.value = '';
    pathInput.value = '/';
    dateFrom.value = '';
    dateTo.value = '';
    deviceType.value = 'all';
    minWeight.value = '1';
    modeSelect.value = 'heatmap';
    clearLayer();
    setStatus('Фильтры сброшены.', 'is-success');
  }

  function loadData() {
    var path = normalizePath(pathInput.value);
    pathInput.value = path;
    setPreviewNotice('');
    if (iframeLoadTimer) clearTimeout(iframeLoadTimer);
    iframe.src = buildPreviewUrl(path);
    iframeLoadTimer = window.setTimeout(function () {
      setPreviewNotice('Предпросмотр страницы не загрузился. Возможна блокировка X-Frame-Options/CSP.', 'is-warning');
    }, 5000);

    toggleLoading(true);
    setStatus('Загрузка данных…');

    fetch(window.wchAdmin.heatmapUrl + '?' + buildQuery(path), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': window.wchAdmin.nonce || ''
      }
    })
      .then(function (response) {
        if (!response.ok) throw new Error('HTTP ' + response.status);
        return response.json();
      })
      .then(function (data) {
        var items = Array.isArray(data.items) ? data.items : [];
        var mode = data.mode || 'heatmap';

        if (!items.length) {
          clearLayer();
          setStatus('Нет данных для выбранных фильтров.', 'is-warning');
        } else {
          if (mode === 'click-points') {
            drawClickPoints(items);
          } else {
            drawHeatmap(items);
          }
          setStatus('Загружено точек: ' + items.length, 'is-success');
        }

        updateSummary(data.summary || {}, data.top_selectors || []);
      })
      .catch(function (err) {
        setStatus('Не удалось загрузить данные: ' + err.message, 'is-error');
      })
      .finally(function () {
        toggleLoading(false);
      });
  }

  (window.wchAdmin.pages || []).forEach(function (item) {
    var opt = document.createElement('option');
    opt.value = item.path;
    opt.textContent = item.title + ' (' + item.path + ')';
    pageSelect.appendChild(opt);
  });

  pageSelect.addEventListener('change', function () {
    if (pageSelect.value) {
      pathInput.value = pageSelect.value;
    }
  });

  loadBtn.addEventListener('click', loadData);
  resetBtn.addEventListener('click', resetFilters);

  iframe.addEventListener('load', function () {
    if (iframeLoadTimer) clearTimeout(iframeLoadTimer);
    var rect = iframe.getBoundingClientRect();
    layer.style.width = rect.width + 'px';
    layer.style.height = rect.height + 'px';

    try {
      var doc = iframe.contentDocument;
      if (!doc || !doc.body) {
        setPreviewNotice('Предпросмотр недоступен. Проверьте X-Frame-Options/CSP на странице.', 'is-warning');
      } else {
        setPreviewNotice('');
      }
    } catch (e) {
      setPreviewNotice('Предпросмотр недоступен из-за ограничений встраивания (X-Frame-Options/CSP).', 'is-warning');
    }
  });
})();
