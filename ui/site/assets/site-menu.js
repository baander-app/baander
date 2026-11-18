(() => {
  // The header menu's panels are native disclosures that open and close without this script. It
  // adds what a native disclosure lacks: opening a panel moves focus to its first entry, and Escape,
  // a click outside the panel or focus moving out of it closes the panel. The listeners sit on the
  // document, so they work from the first paint, before the body has parsed.
  const panels = 'details[data-site-menu]';
  const openPanels = () => document.querySelectorAll(`${panels}[open]`);
  const close = (panel) => {
    panel.open = false;
  };

  // A disclosure's toggle event does not bubble, so it is heard while it travels down to the panel.
  document.addEventListener('toggle', (event) => {
    if (!(event.target instanceof HTMLDetailsElement) || !event.target.matches(panels) || !event.target.open) return;
    event.target.querySelector(':scope > summary ~ * a[href]')?.focus();
  }, true);

  document.addEventListener('keydown', (event) => {
    if (event.key !== 'Escape') return;
    for (const panel of openPanels()) {
      close(panel);
      panel.querySelector(':scope > summary')?.focus();
    }
  });

  document.addEventListener('click', (event) => {
    if (!(event.target instanceof Node)) return;
    for (const panel of openPanels()) {
      if (!panel.contains(event.target)) close(panel);
    }
  });

  // Only a move to another element closes the panel: focus that leaves the window, or a click on
  // the panel's own text, has no new target and keeps it open.
  document.addEventListener('focusout', (event) => {
    if (!(event.relatedTarget instanceof Node) || !(event.target instanceof Element)) return;
    const panel = event.target.closest(`${panels}[open]`);
    if (panel && !panel.contains(event.relatedTarget)) close(panel);
  });
})();
