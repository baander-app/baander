(() => {
  const preference = window.matchMedia('(prefers-color-scheme: light)');
  const apply = () => {
    document.documentElement.dataset.theme = preference.matches ? 'balanced' : 'dark';
  };
  apply();
  preference.addEventListener('change', apply);
})();
