(() => {
  // Long chapters can push the current page's link below the visible part of the sidebar.
  // Scroll only the sidebar's own scroll area so the link is centred; the page stays put.
  const current = document.querySelector('.chapter-navigation a[aria-current="page"]');
  if (!current) return;
  let container = current.parentElement;
  while (container && container !== document.body) {
    const { overflowY } = getComputedStyle(container);
    if ((overflowY === 'auto' || overflowY === 'scroll') && container.scrollHeight > container.clientHeight) break;
    container = container.parentElement;
  }
  if (!container || container === document.body) return;
  const link = current.getBoundingClientRect();
  const area = container.getBoundingClientRect();
  if (link.top >= area.top && link.bottom <= area.bottom) return;
  container.scrollTop += link.top - area.top - (container.clientHeight - link.height) / 2;
})();
