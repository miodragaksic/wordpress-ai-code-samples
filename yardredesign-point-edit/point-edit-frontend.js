/*
 * YardRedesign Point Edit - sanitized portfolio sample
 * No credentials, customer data, billing logic or production endpoints.
 */

(() => {
  'use strict';

  const imageWrap = document.getElementById('yardPointImgWrap');
  const image = document.getElementById('yardPointImg');
  const markerLayer = document.getElementById('yardPointMarkers');
  const pointList = document.getElementById('yardPointList');
  const applyButton = document.getElementById('yardPointApply');

  if (!imageWrap || !image || !markerLayer || !pointList || !applyButton) return;

  let points = [];
  let nextId = 1;
  let draggingId = null;

  const clamp = value => Math.max(0, Math.min(1, value));

  function normalizedPosition(clientX, clientY) {
    const rect = image.getBoundingClientRect();
    if (!rect.width || !rect.height) return null;

    return {
      x: clamp((clientX - rect.left) / rect.width),
      y: clamp((clientY - rect.top) / rect.height)
    };
  }

  function addPoint(clientX, clientY) {
    const pos = normalizedPosition(clientX, clientY);
    if (!pos) return;

    points.push({
      id: nextId++,
      x: pos.x,
      y: pos.y,
      text: ''
    });

    render();
  }

  function updatePointPosition(id, clientX, clientY) {
    const pos = normalizedPosition(clientX, clientY);
    if (!pos) return;

    const point = points.find(item => item.id === id);
    if (!point) return;

    point.x = pos.x;
    point.y = pos.y;
    renderMarkers();
  }

  function removePoint(id) {
    points = points.filter(item => item.id !== id);
    render();
  }

  function renderMarkers() {
    markerLayer.innerHTML = '';

    points.forEach(point => {
      const marker = document.createElement('button');
      marker.type = 'button';
      marker.className = 'yard-point-marker';
      marker.textContent = String(point.id);
      marker.style.left = `${point.x * 100}%`;
      marker.style.top = `${point.y * 100}%`;
      marker.dataset.pointId = String(point.id);
      marker.setAttribute('aria-label', `Point ${point.id}`);

      marker.addEventListener('pointerdown', event => {
        event.preventDefault();
        event.stopPropagation();
        draggingId = point.id;
        marker.setPointerCapture?.(event.pointerId);
      });

      markerLayer.appendChild(marker);
    });
  }

  function renderList() {
    pointList.innerHTML = '';

    points.forEach(point => {
      const row = document.createElement('div');
      row.className = 'yard-point-row';

      const label = document.createElement('strong');
      label.textContent = `Point ${point.id}`;

      const input = document.createElement('input');
      input.type = 'text';
      input.value = point.text;
      input.placeholder = 'Describe the change at this point';
      input.addEventListener('input', () => {
        point.text = input.value;
      });

      const remove = document.createElement('button');
      remove.type = 'button';
      remove.textContent = 'Remove';
      remove.addEventListener('click', () => removePoint(point.id));

      row.append(label, input, remove);
      pointList.appendChild(row);
    });
  }

  function render() {
    renderMarkers();
    renderList();
  }

  imageWrap.addEventListener('click', event => {
    if (event.target.closest('.yard-point-marker')) return;
    addPoint(event.clientX, event.clientY);
  });

  window.addEventListener('pointermove', event => {
    if (draggingId === null) return;
    updatePointPosition(draggingId, event.clientX, event.clientY);
  });

  window.addEventListener('pointerup', () => {
    draggingId = null;
  });

  function buildPayload() {
    return points
      .map(point => ({
        id: point.id,
        x: Number(point.x.toFixed(5)),
        y: Number(point.y.toFixed(5)),
        text: point.text.trim()
      }))
      .filter(point => point.text !== '');
  }

  applyButton.addEventListener('click', async () => {
    const pointPayload = buildPayload();
    if (!pointPayload.length) return;

    const body = new FormData();
    body.append('action', 'yard_portfolio_point_edit');
    body.append('points', JSON.stringify(pointPayload));

    // In a real WordPress implementation, add a server-generated nonce.
    // Never expose API keys or private credentials in browser JavaScript.

    const response = await fetch('/wp-admin/admin-ajax.php', {
      method: 'POST',
      credentials: 'same-origin',
      body
    });

    const result = await response.json();
    console.log('Point Edit result:', result);
  });
})();
