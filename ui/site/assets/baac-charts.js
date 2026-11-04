(() => {
  const SVG = 'http://www.w3.org/2000/svg';
  const number = (value, digits = 3) => Number(value).toLocaleString('en-US', { minimumFractionDigits: digits, maximumFractionDigits: digits });
  const make = (name, attributes = {}) => {
    const element = document.createElementNS(SVG, name);
    for (const [key, value] of Object.entries(attributes)) element.setAttribute(key, String(value));
    return element;
  };
  const html = (name, className, text) => {
    const element = document.createElement(name);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  // A short stroke in the series color and dash pattern identifies each tooltip row.
  const lineKey = (color, dash) => {
    const key = make('svg', { class: 'baac-tooltip-key', width: 18, height: 8, viewBox: '0 0 18 8', 'aria-hidden': 'true' });
    key.style.color = color;
    key.append(make('line', { x1: 1, x2: 17, y1: 4, y2: 4, stroke: 'currentColor', 'stroke-width': 2, 'stroke-linecap': 'round', 'stroke-dasharray': dash || 'none' }));
    return key;
  };

  let hintCount = 0;

  for (const panel of document.querySelectorAll('.baac-chart-panel')) {
    const svg = panel.querySelector('svg.baac-chart');
    const region = panel.querySelector('.baac-plot-scroll');
    if (!svg || !region) continue;
    const quality = svg.querySelector('.baac-point') !== null;
    const metric = panel.querySelector('thead th:last-child')?.textContent ?? '';

    const marks = quality
      ? [...svg.querySelectorAll('.baac-point')].map((element) => {
        const series = element.closest('.baac-series');
        return {
          element, series, codec: series.dataset.codec, dash: series.dataset.dash,
          x: Number(element.dataset.x), y: Number(element.dataset.y), value: Number(element.dataset.value),
          label: element.dataset.label, nominal: Number(element.dataset.nominal), kbps: Number(element.dataset.kbps),
        };
      })
      : [...svg.querySelectorAll('.baac-bar')].map((element) => ({
        element, codec: element.dataset.codec,
        x: Number(element.getAttribute('x')) + Number(element.getAttribute('width')),
        y: Number(element.getAttribute('y')) + Number(element.getAttribute('height')) / 2,
        value: Number(element.dataset.value), label: element.dataset.label, cpu: Number(element.dataset.cpu),
      }));
    if (!marks.length || marks.some((mark) => !Number.isFinite(mark.x) || !Number.isFinite(mark.y) || !Number.isFinite(mark.value))) continue;

    const hidden = new Set();
    const visible = () => marks.filter((mark) => !hidden.has(mark.codec));
    const seriesColor = (codec) => `var(--baac-series-${codec}, currentColor)`;

    // Hover marks draw above the data; the transparent hit area above them takes the pointer,
    // so the pointer only has to be nearest to a mark, never on its painted pixels.
    const layer = make('g', { class: 'baac-hover', 'aria-hidden': 'true' });
    const hit = quality
      ? make('rect', { class: 'baac-hit', x: 40, y: 20, width: 660, height: 312, fill: 'transparent' })
      : make('rect', { class: 'baac-hit', x: 0, y: 30, width: 720, height: Math.max(...marks.map((mark) => mark.y)) + 22 - 30, fill: 'transparent' });
    const legend = svg.querySelector('.baac-legend');
    svg.insertBefore(layer, legend);
    svg.insertBefore(hit, legend);

    const tooltip = html('div', 'baac-tooltip');
    tooltip.hidden = true;
    tooltip.setAttribute('aria-hidden', 'true');
    const announcer = html('p', 'visually-hidden');
    announcer.setAttribute('aria-live', 'polite');
    const hint = html('p', 'visually-hidden', quality
      ? 'Interactive chart. Use the left and right arrow keys to move along an encoder, and the up and down arrow keys to compare encoders at the same setting. Legend buttons after the chart show or hide encoders.'
      : 'Interactive chart. Use the arrow keys to move between codecs.');
    hint.id = `baac-chart-hint-${++hintCount}`;
    panel.append(tooltip, announcer, hint);

    svg.setAttribute('tabindex', '0');
    svg.setAttribute('role', 'group');
    svg.setAttribute('aria-roledescription', 'chart');
    svg.setAttribute('aria-describedby', hint.id);

    let active = null;

    const describe = (mark) => quality
      ? `${mark.label}, nominal ${number(mark.nominal, 0)} kbit/s: ${metric} ${number(mark.value)} at ${number(mark.kbps)} kbit/s measured.`
      : `${mark.label}: ${number(mark.value)} audio seconds per CPU second, ${number(mark.cpu)} CPU seconds.`;

    const fillTooltip = (mark) => {
      tooltip.replaceChildren();
      if (quality) {
        tooltip.append(html('p', 'baac-tooltip-heading', `Nominal ${number(mark.nominal, 0)} kbit/s · ${metric}`));
        const peers = visible().filter((peer) => peer.nominal === mark.nominal).sort((a, b) => b.value - a.value);
        const list = html('ul', 'baac-tooltip-rows');
        for (const peer of peers) {
          const row = html('li', peer === mark ? 'is-active' : '');
          row.append(lineKey(seriesColor(peer.codec), peer.dash), html('strong', '', number(peer.value)), html('span', '', peer.label), html('span', 'baac-tooltip-muted', `${number(peer.kbps, 1)} kbit/s`));
          list.append(row);
        }
        tooltip.append(list);
      } else {
        const value = html('p', 'baac-tooltip-value');
        value.append(html('strong', '', number(mark.value)), html('span', 'baac-tooltip-muted', ' audio s / CPU s'));
        tooltip.append(value, html('p', '', mark.label), html('p', 'baac-tooltip-muted', `${number(mark.cpu)} CPU seconds`));
      }
    };

    const place = (mark) => {
      const matrix = svg.getScreenCTM();
      if (!matrix) return;
      const bounds = panel.getBoundingClientRect();
      // Bars anchor past their value label so the tooltip never covers it. Text length is
      // measured here because a closed profile panel reports zero until it opens.
      const label = quality ? null : mark.element.nextElementSibling;
      const x = label?.localName === 'text' ? Number(label.getAttribute('x')) + label.getComputedTextLength() : mark.x;
      const anchorX = matrix.a * x + matrix.e - bounds.left;
      const anchorY = matrix.d * mark.y + matrix.f - bounds.top;
      tooltip.hidden = false;
      const width = tooltip.offsetWidth;
      const height = tooltip.offsetHeight;
      const gap = 16;
      let left = anchorX + gap;
      if (left + width > bounds.width) left = anchorX - gap - width;
      left = Math.max(0, Math.min(left, bounds.width - width));
      const top = Math.max(0, anchorY - height / 2);
      tooltip.style.transform = `translate(${Math.round(left)}px, ${Math.round(top)}px)`;
    };

    const drawHover = (mark) => {
      layer.replaceChildren();
      if (quality) {
        layer.append(make('line', { class: 'baac-crosshair', x1: mark.x, x2: mark.x, y1: 35, y2: 298 }));
        for (const peer of visible().filter((candidate) => candidate.nominal === mark.nominal)) {
          const ring = make('circle', { class: peer === mark ? 'baac-ring is-active' : 'baac-ring', cx: peer.x, cy: peer.y, r: peer === mark ? 9 : 7 });
          ring.style.color = seriesColor(peer.codec);
          layer.append(ring);
        }
      } else {
        for (const bar of marks) bar.element.classList.toggle('is-active', bar === mark);
      }
    };

    const keepVisible = (mark) => {
      const matrix = svg.getScreenCTM();
      if (!matrix) return;
      const x = matrix.a * mark.x + matrix.e;
      const bounds = region.getBoundingClientRect();
      if (x < bounds.left + 24) region.scrollLeft -= bounds.left + 24 - x;
      else if (x > bounds.right - 24) region.scrollLeft += x - (bounds.right - 24);
    };

    const show = (mark, { announce = false } = {}) => {
      if (!mark) return clear();
      active = mark;
      svg.classList.add('has-active');
      drawHover(mark);
      fillTooltip(mark);
      if (announce) {
        keepVisible(mark);
        announcer.textContent = describe(mark);
      }
      place(mark);
    };

    function clear() {
      active = null;
      svg.classList.remove('has-active');
      layer.replaceChildren();
      for (const mark of marks) mark.element.classList.remove('is-active');
      tooltip.hidden = true;
    }

    const toChart = (event) => {
      const matrix = svg.getScreenCTM();
      if (!matrix) return null;
      const point = new DOMPoint(event.clientX, event.clientY).matrixTransform(matrix.inverse());
      return { x: point.x, y: point.y };
    };

    const nearest = (position) => {
      if (!position) return null;
      let best = null;
      let distance = Infinity;
      for (const mark of visible()) {
        // Bars stack vertically, so only the row matters; points use plain distance.
        const candidate = quality ? Math.hypot(mark.x - position.x, mark.y - position.y) : Math.abs(mark.y - position.y);
        if (candidate < distance) {
          best = mark;
          distance = candidate;
        }
      }
      return best;
    };

    hit.addEventListener('pointermove', (event) => show(nearest(toChart(event))));
    hit.addEventListener('pointerdown', (event) => show(nearest(toChart(event))));
    hit.addEventListener('pointerleave', (event) => {
      if (event.pointerType === 'mouse' && !svg.matches(':focus-visible')) clear();
    });
    document.addEventListener('pointerdown', (event) => {
      if (active && !svg.contains(event.target)) clear();
    });
    region.addEventListener('scroll', () => {
      if (active) place(active);
    }, { passive: true });
    window.addEventListener('resize', () => {
      if (active) place(active);
    });

    const ordered = () => [...visible()].sort((a, b) => a.x - b.x);
    const step = (key) => {
      const candidates = visible();
      if (!candidates.length) return null;
      if (!active || hidden.has(active.codec)) return quality ? ordered()[0] : candidates[0];
      if (!quality) {
        const index = candidates.indexOf(active);
        if (key === 'Home') return candidates[0];
        if (key === 'End') return candidates[candidates.length - 1];
        const offset = key === 'ArrowDown' || key === 'ArrowRight' ? 1 : -1;
        return candidates[Math.min(candidates.length - 1, Math.max(0, index + offset))];
      }
      if (key === 'ArrowLeft' || key === 'ArrowRight' || key === 'Home' || key === 'End') {
        const line = candidates.filter((mark) => mark.codec === active.codec).sort((a, b) => a.x - b.x);
        const index = line.indexOf(active);
        if (key === 'Home') return line[0];
        if (key === 'End') return line[line.length - 1];
        return line[Math.min(line.length - 1, Math.max(0, index + (key === 'ArrowRight' ? 1 : -1)))];
      }
      const peers = candidates.filter((mark) => mark.nominal === active.nominal).sort((a, b) => b.value - a.value);
      const index = peers.indexOf(active);
      return peers[Math.min(peers.length - 1, Math.max(0, index + (key === 'ArrowDown' ? 1 : -1)))];
    };

    svg.addEventListener('keydown', (event) => {
      if (event.target !== svg) return;
      if (event.key === 'Escape') {
        clear();
        return;
      }
      if (!['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'].includes(event.key)) return;
      event.preventDefault();
      show(step(event.key), { announce: true });
    });
    svg.addEventListener('focus', () => {
      if (!svg.matches(':focus-visible')) return;
      show(active && !hidden.has(active.codec) ? active : step('Home'), { announce: true });
    });
    svg.addEventListener('blur', (event) => {
      if (!svg.contains(event.relatedTarget)) clear();
    });

    if (!quality || !legend) continue;
    const items = [...legend.querySelectorAll('.baac-legend-item')];
    const emphasize = (codec) => {
      for (const series of svg.querySelectorAll('.baac-series')) series.classList.toggle('is-receded', codec !== null && series.dataset.codec !== codec);
    };
    for (const item of items) {
      const codec = item.dataset.series;
      const label = item.querySelector('text')?.textContent ?? codec;
      const series = svg.querySelector(`.baac-series[data-codec="${CSS.escape(codec)}"]`);
      if (!series) continue;
      item.setAttribute('tabindex', '0');
      item.setAttribute('role', 'button');
      item.setAttribute('aria-pressed', 'true');
      item.setAttribute('aria-label', `Show ${label}`);
      // A wider transparent target than the legend glyph and text.
      const box = item.getBBox();
      item.prepend(make('rect', { class: 'baac-legend-hit', x: box.x - 8, y: box.y - 6, width: box.width + 16, height: box.height + 12, rx: 6 }));
      const toggle = () => {
        const showing = !hidden.has(codec);
        if (showing && hidden.size === items.length - 1) return;
        if (showing) hidden.add(codec);
        else hidden.delete(codec);
        item.setAttribute('aria-pressed', String(!showing));
        item.classList.toggle('is-off', showing);
        series.classList.toggle('is-hidden', showing);
        emphasize(showing ? null : codec);
        announcer.textContent = `${label} ${showing ? 'hidden' : 'shown'}.`;
        if (active) show(hidden.has(active.codec) ? null : active);
      };
      item.addEventListener('click', toggle);
      item.addEventListener('keydown', (event) => {
        if (event.key !== 'Enter' && event.key !== ' ') return;
        event.preventDefault();
        toggle();
      });
      item.addEventListener('pointerenter', () => emphasize(hidden.has(codec) ? null : codec));
      item.addEventListener('pointerleave', () => emphasize(null));
      item.addEventListener('focus', () => emphasize(hidden.has(codec) ? null : codec));
      item.addEventListener('blur', () => emphasize(null));
    }
  }
})();
