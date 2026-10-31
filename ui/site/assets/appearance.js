(() => {
  const preference = window.matchMedia('(prefers-color-scheme: light)');
  const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const storageKey = 'baander-site-appearance';
  let choice = null;
  let button = null;
  let clearCrossfade = () => {};
  try {
    const saved = localStorage.getItem(storageKey);
    if (saved === 'light' || saved === 'dark') choice = saved;
  } catch {
    // System preference and the current page's toggle still work without storage.
  }
  const beginCrossfade = () => {
    const cleanups = [];
    for (const figure of document.querySelectorAll('.app-screenshot')) {
      if (!figure.getClientRects().length) continue;
      figure.classList.add('screenshot-theme-transitioning');
      const finish = (event) => {
        if (event && (!event.target.matches('.screenshot-dark, .screenshot-light') || event.propertyName !== 'opacity')) return;
        figure.classList.remove('screenshot-theme-transitioning');
        figure.removeEventListener('transitionend', finish);
        figure.removeEventListener('transitioncancel', finish);
        clearTimeout(fallback);
      };
      const fallback = setTimeout(() => finish(), 260);
      figure.addEventListener('transitionend', finish);
      figure.addEventListener('transitioncancel', finish);
      cleanups.push(() => finish());
    }
    clearCrossfade = () => cleanups.forEach(cleanup => cleanup());
  };
  const apply = (animate = false) => {
    const mode = choice ?? (preference.matches ? 'light' : 'dark');
    const theme = mode === 'light' ? 'balanced' : 'dark';
    clearCrossfade();
    if (animate && theme !== document.documentElement.dataset.theme && !reducedMotion.matches) beginCrossfade();
    document.documentElement.dataset.theme = theme;
    for (const image of document.querySelectorAll('.app-screenshot .screenshot-dark, .app-screenshot .screenshot-light')) {
      image.setAttribute('aria-hidden', String(image.classList.contains('screenshot-light') !== (mode === 'light')));
    }
    if (button) {
      const label = `Switch to ${mode === 'light' ? 'dark' : 'light'} mode`;
      button.setAttribute('aria-label', label);
      button.title = label;
    }
  };
  apply();
  preference.addEventListener('change', () => apply(true));
  reducedMotion.addEventListener('change', () => {
    if (reducedMotion.matches) clearCrossfade();
  });

  const connect = () => {
    button = document.querySelector('[data-appearance-toggle]');
    if (!button) return;
    apply();
    button.addEventListener('click', () => {
      choice = document.documentElement.dataset.theme === 'balanced' ? 'dark' : 'light';
      apply(true);
      try {
        localStorage.setItem(storageKey, choice);
      } catch {
        // Keep the manual choice for this page when storage is unavailable.
      }
    });
    button.hidden = false;
  };
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', connect, { once: true });
  else connect();
})();
