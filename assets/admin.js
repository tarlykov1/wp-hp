(function () {
  'use strict';

  if (!window.wchAdmin) {
    return;
  }

  var pathInput = document.getElementById('wch-path');
  var loadBtn = document.getElementById('wch-load');
  var iframe = document.getElementById('wch-preview');
  var layer = document.getElementById('wch-heatmap-layer');
  var statusEl = document.getElementById('wch-status');
  var heatmapInstance = null;

  function normalizePath(path) {
    var value = (path || '').trim();
    if (!value) return '/';
    value = '/' + value.replace(/^\/+/, '');
    value = value.replace(/\/{2,}/g, '/');
    if (value.length > 1 && value.endsWith('/')) {
      value = value.slice(0, -1);
    }
    return value;
  }

  function setStatus(text, isError) {
    statusEl.textContent = text;
    statusEl.style.color = isError ? '#b32d2e' : '#50575e';
  }

  function buildPreviewUrl(path) {
    return window.location.origin + path;
  }

  function drawHeatmap(items) {
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
        value: item.weight
      };
    });

    var max = points.reduce(function (acc, p) {
      return p.value > acc ? p.value : acc;
    }, 1);

    heatmapInstance.setData({ max: max, data: points });
  }

  function loadHeatmap() {
    var path = normalizePath(pathInput.value);
    pathInput.value = path;

    iframe.src = buildPreviewUrl(path);
    setStatus('Загрузка данных…', false);

    fetch(window.wchAdmin.heatmapUrl + '?path=' + encodeURIComponent(path), {
      method: 'GET',
      credentials: 'same-origin',
      headers: {
        'X-WP-Nonce': window.wchAdmin.nonce || ''
      }
    })
      .then(function (response) {
        if (!response.ok) {
          throw new Error('HTTP ' + response.status);
        }
        return response.json();
      })
      .then(function (data) {
        var items = Array.isArray(data.items) ? data.items : [];
        drawHeatmap(items);
        setStatus('Точек на карте: ' + items.length, false);
      })
      .catch(function (err) {
        setStatus('Не удалось загрузить тепловую карту: ' + err.message, true);
      });
  }

  loadBtn.addEventListener('click', loadHeatmap);

  iframe.addEventListener('load', function () {
    var rect = iframe.getBoundingClientRect();
    layer.style.width = rect.width + 'px';
    layer.style.height = rect.height + 'px';
  });
})();
