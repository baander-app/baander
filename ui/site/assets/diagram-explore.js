// Interactive diagrams: swaps each built diagram image for its SVG so a reader can
// follow one connection at a time by pointer, touch or keyboard, with announcements
// for screen readers. Without JavaScript, or when a fetch fails, the static image and
// its full-size link stay as built.
(() => {
  const SVG = 'http://www.w3.org/2000/svg';
  const VARIANTS = ['diagram-light', 'diagram-dark'];
  const STATES = ['is-active', 'is-related', 'is-receded'];
  const RING = 'var(--color-ring)';
  const HIGHLIGHT = 'var(--color-highlight)';
  const SHAPES = 'rect, polygon, circle, ellipse, path';
  const TARGETS = [
    '.diagram-hit', 'g.node[data-node]', 'g.cluster[data-cluster]', 'g.edgeLabel[data-from][data-to]',
    'path[data-et="edge"][data-id]', '[data-et="message"][data-from][data-to]', 'text.messageText[data-from][data-to]',
  ].join(', ');
  const STEP_KEYS = ['ArrowLeft', 'ArrowRight', 'ArrowUp', 'ArrowDown', 'Home', 'End'];
  const TAP_SLOP = 8;
  // Page controls a pointer may land on without releasing a pinned element.
  const CONTROLS = 'a[href], button, input, select, textarea, summary';
  const HINT_IDLE = 'Enter steps through connections';
  const HINT_STEPPING = 'Escape leaves';
  const HINT_LONG = 'Interactive diagram. Press Enter to step through nodes with the arrow keys, Up and Down for a node’s connections, Escape to leave.';

  const html = (name, className, text) => {
    const element = document.createElement(name);
    if (className) element.className = className;
    if (text !== undefined) element.textContent = text;
    return element;
  };
  // Label text with one space between wrapped rows, which Mermaid draws as separate tspans.
  const words = (element) => {
    if (!element) return '';
    const rows = [...element.querySelectorAll('.text-outer-tspan')];
    const lines = rows.length ? rows : [...element.children].filter((child) => child.localName === 'tspan');
    const raw = lines.length ? lines.map((line) => line.textContent).join(' ') : element.textContent;
    return raw.replace(/\s+/g, ' ').trim();
  };
  const shown = (element) => element instanceof Element && getComputedStyle(element).display !== 'none';
  const clamp = (index, length) => Math.min(length - 1, Math.max(0, index));
  const markerId = (reference) => reference?.match(/url\(["']?#([^"')]+)["']?\)/)?.[1] ?? null;
  const setState = (elements, state) => {
    for (const element of elements) {
      if (!element) continue;
      element.classList.remove(...STATES);
      if (state) element.classList.add(state);
    }
  };

  let hintCount = 0;

  // The built asset is accepted only when it really is an SVG document.
  const fetchSvg = async (src) => {
    const response = await fetch(src);
    if (!response.ok || !(response.headers.get('content-type') ?? '').startsWith('image/svg+xml')) return null;
    const parsed = new DOMParser().parseFromString(await response.text(), 'image/svg+xml');
    const root = parsed.documentElement;
    if (parsed.querySelector('parsererror') || root?.localName !== 'svg' || root.namespaceURI !== SVG) return null;
    return root;
  };

  // The inlined root takes the image's size and accessible name, keeps Mermaid's
  // id-scoped styles and the embedded font, and sits before the hidden image.
  const inlineSvg = (image, root, variant) => {
    const svg = document.importNode(root, true);
    for (const name of ['width', 'height']) svg.setAttribute(name, image.getAttribute(name));
    for (const name of ['aria-labelledby', 'aria-describedby', 'aria-roledescription']) svg.removeAttribute(name);
    svg.setAttribute('role', 'img');
    svg.setAttribute('aria-label', image.alt);
    svg.classList.add(variant, 'diagram-interactive');
    image.before(svg);
    image.hidden = true;
    // The theme rules in the stylesheet set the image's display, so the attribute alone would not hide it.
    image.style.display = 'none';
    return svg;
  };

  // A transparent, wide copy of a line or edge that takes the pointer for it.
  const addHit = (element, key) => {
    const hit = element.cloneNode(false);
    for (const { name } of [...hit.attributes]) {
      if (!['d', 'x1', 'y1', 'x2', 'y2', 'transform'].includes(name)) hit.removeAttribute(name);
    }
    hit.setAttribute('class', 'diagram-hit');
    hit.setAttribute('data-hit-for', key);
    hit.setAttribute('aria-hidden', 'true');
    element.after(hit);
    return hit;
  };

  // Arrowheads are markers with theme fills of their own, so each marker an edge
  // references gets a ring-colored twin that the active edge points at.
  const activeMarkers = (svg, lines) => {
    const twins = new Map();
    for (const line of lines) {
      for (const name of ['marker-start', 'marker-end']) {
        const id = markerId(line.getAttribute(name));
        if (!id || twins.has(id)) continue;
        const marker = svg.querySelector(`marker#${CSS.escape(id)}`);
        if (!marker) continue;
        const twin = marker.cloneNode(true);
        twin.id = `${id}-active`;
        for (const element of [twin, ...twin.querySelectorAll('*')]) {
          element.style.fill = RING;
          element.style.stroke = RING;
        }
        marker.after(twin);
        twins.set(id, `url(#${twin.id})`);
      }
    }
    return twins;
  };

  const lineItem = (kind, key, element, name) => ({
    kind, key, element, name, hit: addHit(element, key),
    markers: { 'marker-start': element.getAttribute('marker-start'), 'marker-end': element.getAttribute('marker-end') },
  });

  const flowchartModel = (svg) => {
    const byName = new Map();
    const nodes = [];
    const place = (element, kind, key, labelSelector, shape) => {
      const item = { kind, key, element, name: words(element.querySelector(labelSelector)) || key, shape, edges: [] };
      byName.set(key, item);
      nodes.push(item);
    };
    for (const element of svg.querySelectorAll('g.node[data-node]')) {
      place(element, 'node', element.dataset.node, '.label', element.querySelector(SHAPES));
    }
    for (const element of svg.querySelectorAll('g.cluster[data-cluster]')) {
      place(element, 'cluster', element.dataset.cluster, '.cluster-label', element.querySelector(':scope > rect'));
    }
    const labels = new Map();
    for (const label of svg.querySelectorAll('g.edgeLabel[data-from][data-to]')) {
      const id = label.querySelector('g.label[data-id]')?.dataset.id;
      if (id) labels.set(id, label);
    }
    const edges = [];
    for (const path of svg.querySelectorAll('path[data-et="edge"][data-id][data-from][data-to]')) {
      if (path.classList.contains('edge-thickness-invisible')) continue;
      const from = byName.get(path.dataset.from);
      const to = byName.get(path.dataset.to);
      if (!from || !to) continue;
      const label = labels.get(path.dataset.id) ?? null;
      const edge = { ...lineItem('edge', path.dataset.id, path, words(label)), label, from, to };
      from.edges.push(edge);
      if (to !== from) to.edges.push(edge);
      edges.push(edge);
    }
    return {
      kind: 'flowchart', nodes, edges,
      items: [...nodes, ...edges],
      steps: nodes.filter((item) => item.kind === 'node'),
      twins: activeMarkers(svg, edges.map((edge) => edge.element)),
    };
  };

  const sequenceModel = (svg) => {
    const actors = new Map();
    const names = new Map();
    for (const element of svg.querySelectorAll('g[data-et="participant"][data-id], line[data-et="life-line"][data-id]')) {
      const id = element.dataset.id;
      if (!actors.has(id)) actors.set(id, []);
      actors.get(id).push(element);
      if (!names.has(id) && element.dataset.et === 'participant') names.set(id, words(element.querySelector('text')) || id);
    }
    const texts = [...svg.querySelectorAll('text.messageText[data-from][data-to]')];
    const messages = [...svg.querySelectorAll('[data-et="message"][data-from][data-to]')].map((element, index) => {
      const { from, to } = element.dataset;
      const position = texts.findIndex((text) => text.dataset.from === from && text.dataset.to === to);
      const own = position < 0 ? [] : texts.splice(position, 1);
      return { ...lineItem('message', String(index), element, words(own[0]) || 'message'), texts: own, from, to };
    });
    return {
      kind: 'sequence', messages, actors, names,
      items: messages, steps: messages,
      twins: activeMarkers(svg, messages.map((message) => message.element)),
    };
  };

  const buildView = (svg) => {
    const model = svg.querySelector('[data-et="message"]') ? sequenceModel(svg) : flowchartModel(svg);
    const byElement = new Map();
    for (const item of model.items) {
      for (const element of [item.element, item.hit, item.label, ...(item.texts ?? [])]) {
        if (element) byElement.set(element, item);
      }
    }
    return { svg, ...model, byElement, active: null };
  };

  const ref = (item) => ({ kind: item.kind, key: item.key });
  const find = (view, reference) => reference && view
    ? view.items.find((item) => item.kind === reference.kind && item.key === reference.key) ?? null
    : null;

  const describe = (view, item) => {
    if (item.kind === 'edge') return `${item.name || 'edge'} from ${item.from.name} to ${item.to.name}`;
    if (item.kind === 'message') return `${item.name} from ${view.names.get(item.from) ?? item.from} to ${view.names.get(item.to) ?? item.to}`;
    if (!item.edges.length) return `${item.name}. No connections.`;
    const ends = item.edges.map((edge) => {
      const outgoing = edge.from === item;
      const far = outgoing ? edge.to : edge.from;
      return `${outgoing ? 'to' : 'from'} ${far.name}${edge.name ? ` (${edge.name})` : ''}`;
    });
    return `${item.name}. ${ends.length} connection${ends.length === 1 ? '' : 's'}: ${ends.join(', ')}`;
  };

  // The active element and, for a node, every edge attached to it take the ring color,
  // so the lines that belong together are colored together; only the active element's
  // label box takes the highlight fill.
  const style = (view, item, isActive) => {
    if (item.kind === 'node' || item.kind === 'cluster') {
      if (!item.shape) return;
      item.shape.style.fill = HIGHLIGHT;
      item.shape.style.stroke = RING;
      return;
    }
    item.element.style.stroke = RING;
    item.element.style.strokeWidth = '2.5px';
    for (const [name, value] of Object.entries(item.markers)) {
      const twin = view.twins.get(markerId(value));
      if (twin) item.element.setAttribute(name, twin);
    }
    const box = isActive ? item.label?.querySelector('rect.background') : null;
    if (box) box.style.fill = HIGHLIGHT;
  };

  const unstyle = (item) => {
    if (item.kind === 'node' || item.kind === 'cluster') {
      item.shape?.style.removeProperty('fill');
      item.shape?.style.removeProperty('stroke');
      return;
    }
    item.element.style.removeProperty('stroke');
    item.element.style.removeProperty('stroke-width');
    for (const [name, value] of Object.entries(item.markers)) {
      if (value === null) item.element.removeAttribute(name);
      else item.element.setAttribute(name, value);
    }
    item.label?.querySelector('rect.background')?.style.removeProperty('fill');
  };

  const paintFlowchart = (view, active) => {
    const states = new Map([[active, 'is-active']]);
    if (active.kind === 'edge') {
      states.set(active.from, 'is-related');
      states.set(active.to, 'is-related');
    } else {
      for (const edge of active.edges) {
        states.set(edge, 'is-related');
        states.set(edge.from === active ? edge.to : edge.from, 'is-related');
      }
    }
    for (const item of view.items) setState([item.element, item.label], states.get(item) ?? 'is-receded');
  };

  const paintSequence = (view, active) => {
    for (const message of view.messages) {
      setState([message.element, ...message.texts], message === active ? 'is-active' : 'is-receded');
    }
    for (const [id, elements] of view.actors) {
      setState(elements, id === active.from || id === active.to ? 'is-related' : 'is-receded');
    }
  };

  const clearView = (view) => {
    if (!view?.active) return;
    for (const styled of view.styled) unstyle(styled);
    view.active = null;
    view.styled = [];
    for (const item of view.items) setState([item.element, item.label, ...(item.texts ?? [])], null);
    for (const elements of view.actors?.values() ?? []) setState(elements, null);
  };

  const paintView = (view, item) => {
    if (view.active === item) return;
    for (const styled of view.styled ?? []) unstyle(styled);
    view.active = item;
    view.styled = item.kind === 'node' || item.kind === 'cluster' ? [item, ...item.edges] : [item];
    (view.kind === 'sequence' ? paintSequence : paintFlowchart)(view, item);
    style(view, item, true);
    for (const edge of view.styled.slice(1)) style(view, edge, false);
  };

  const setup = (figure) => {
    const region = figure.querySelector('.diagram-viewport');
    const images = Object.fromEntries(VARIANTS.map((variant) => [variant, figure.querySelector(`img.${variant}`)]));
    if (!region || VARIANTS.some((variant) => !images[variant])) return;
    const views = {};
    const loading = {};
    let current = null;
    let ready = false;
    let scrollLeft = region.scrollLeft;
    // One active element per figure: stepping wins over a pin, a pin over hover.
    let hover = null;
    let pin = null;
    let step = null;
    let touch = null;
    let lastTap = -Infinity;
    let announcer = null;
    let hint = null;

    const visibleVariant = () => VARIANTS.find((variant) => shown(views[variant]?.svg ?? images[variant])) ?? null;

    const stepItem = () => {
      if (!step || !current) return null;
      const owner = find(current, step.ref);
      if (!owner) return null;
      return step.edge >= 0 ? owner.edges?.[step.edge] ?? owner : owner;
    };

    const render = () => {
      if (!current) return;
      const item = stepItem() ?? find(current, pin) ?? hover;
      if (item) paintView(current, item);
      else clearView(current);
    };

    const announce = (item) => {
      if (announcer && item) announcer.textContent = describe(current, item);
    };

    const syncHint = () => {
      if (!hint) return;
      hint.hidden = !region.matches(':focus-visible');
      hint.textContent = step ? HINT_STEPPING : HINT_IDLE;
    };

    const clearAll = () => {
      pin = null;
      step = null;
      render();
      syncHint();
    };

    const itemAt = (view, target) => {
      const element = target instanceof Element ? target.closest(TARGETS) : null;
      return element ? view.byElement.get(element) ?? null : null;
    };

    const togglePin = (item) => {
      if (!item) return;
      step = null;
      pin = pin && pin.kind === item.kind && pin.key === item.key ? null : ref(item);
      render();
      if (pin) announce(item);
      syncHint();
    };

    const bindPointer = (view) => {
      const { svg } = view;
      svg.addEventListener('pointerover', (event) => {
        if (event.pointerType === 'touch' || view !== current) return;
        const item = itemAt(view, event.target);
        if (item === hover) return;
        hover = item;
        if (!pin && !step) render();
      });
      svg.addEventListener('pointerleave', (event) => {
        if (event.pointerType === 'touch' || hover === null) return;
        hover = null;
        if (!pin && !step) render();
      });
      svg.addEventListener('pointerdown', (event) => {
        touch = event.pointerType === 'touch' ? { x: event.clientX, y: event.clientY } : null;
      });
      svg.addEventListener('pointercancel', () => {
        touch = null;
      });
      svg.addEventListener('pointerup', (event) => {
        if (event.pointerType !== 'touch' || !touch) return;
        const moved = Math.hypot(event.clientX - touch.x, event.clientY - touch.y);
        touch = null;
        if (moved >= TAP_SLOP || view !== current) return;
        lastTap = event.timeStamp;
        togglePin(itemAt(view, event.target));
      });
      svg.addEventListener('click', (event) => {
        // A tap already handled its pin on pointerup.
        if (event.pointerType === 'touch' || event.timeStamp - lastTap < 700 || view !== current) return;
        togglePin(itemAt(view, event.target));
      });
    };

    const move = (key) => {
      const steps = current.steps;
      if (!steps.length) return;
      const owner = find(current, step.ref) ?? steps[0];
      const index = steps.indexOf(owner);
      const vertical = key === 'ArrowUp' || key === 'ArrowDown';
      if (current.kind === 'flowchart' && vertical) {
        const count = owner.edges.length;
        if (!count) {
          announce(owner);
          return;
        }
        const forward = key === 'ArrowDown';
        step.edge = step.edge < 0 ? (forward ? 0 : count - 1) : (step.edge + (forward ? 1 : -1) + count) % count;
      } else {
        const next = key === 'Home' ? steps[0]
          : key === 'End' ? steps[steps.length - 1]
            : steps[clamp(index + (key === 'ArrowRight' || key === 'ArrowDown' ? 1 : -1), steps.length)];
        step = { ref: ref(next), edge: -1 };
      }
      const item = stepItem();
      render();
      announce(item);
      item?.element.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    };

    const startStepping = () => {
      const pinned = find(current, pin);
      const first = current.steps[0];
      if (!pinned && !first) return;
      if (pinned?.kind === 'edge') {
        const owner = pinned.from.kind === 'node' ? pinned.from : pinned.to;
        step = { ref: ref(owner), edge: owner.edges.indexOf(pinned) };
      } else {
        step = { ref: ref(pinned ?? first), edge: -1 };
      }
      const item = stepItem();
      render();
      announce(item);
      syncHint();
      item?.element.scrollIntoView({ block: 'nearest', inline: 'nearest' });
    };

    const bindRegion = () => {
      announcer = html('p', 'visually-hidden');
      announcer.setAttribute('aria-live', 'polite');
      const description = html('p', 'visually-hidden', HINT_LONG);
      description.id = `diagram-hint-${++hintCount}`;
      hint = html('p', 'diagram-hint', HINT_IDLE);
      hint.hidden = true;
      region.after(hint);
      figure.append(announcer, description);
      region.setAttribute('aria-describedby', description.id);

      region.addEventListener('keydown', (event) => {
        if (event.target !== region || !current) return;
        if (event.key === 'Escape') {
          clearAll();
          return;
        }
        if (event.key === 'Enter') {
          event.preventDefault();
          if (!step) startStepping();
          return;
        }
        if (!step || !STEP_KEYS.includes(event.key)) return;
        event.preventDefault();
        move(event.key);
      });
      region.addEventListener('focus', syncHint);
      // Stepping belongs to the focused region, so leaving it ends stepping; a pin is
      // a pointer choice and stays until the pointer releases it.
      region.addEventListener('blur', () => {
        if (hint) hint.hidden = true;
        if (!step) return;
        step = null;
        render();
        syncHint();
      });
      region.addEventListener('scroll', () => {
        scrollLeft = region.scrollLeft;
      }, { passive: true });
      // A pointer that lands on plain content, inside the figure or outside it, releases
      // the pin and stepping; one that lands on a diagram element or a page control
      // such as the theme toggle leaves them, so a pinned element survives the switch.
      document.addEventListener('pointerdown', (event) => {
        if (!pin && !step) return;
        const target = event.target instanceof Element ? event.target : null;
        if (!target || target.closest(CONTROLS)) return;
        if (current && figure.contains(target) && itemAt(current, target)) return;
        clearAll();
      });
      const observer = new MutationObserver(() => show(visibleVariant()));
      observer.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });
    };

    // Shows the variant the theme asks for, fetching it on first use; the active
    // element and scroll position carry over to the newly visible copy.
    const show = async (variant) => {
      if (!variant) return;
      if (!views[variant] && !loading[variant]) {
        const image = images[variant];
        loading[variant] = fetchSvg(image.getAttribute('src'))
          .then((root) => {
            if (!root) return null;
            const view = buildView(inlineSvg(image, root, variant));
            bindPointer(view);
            views[variant] = view;
            return view;
          })
          .catch((error) => {
            console.debug('Diagram stays static', image.getAttribute('src'), error);
            return null;
          });
      }
      await loading[variant];
      const next = visibleVariant();
      const view = views[next] ?? null;
      if (view === current) return;
      clearView(current);
      current = view;
      hover = null;
      if (!current) return;
      if (!ready) {
        ready = true;
        bindRegion();
      }
      region.scrollLeft = scrollLeft;
      render();
    };

    show(visibleVariant());
  };

  for (const figure of document.querySelectorAll('figure.diagram')) setup(figure);
})();
