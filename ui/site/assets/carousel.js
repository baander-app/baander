(() => {
  for (const carousel of document.querySelectorAll('[data-carousel]')) {
    const slides = [...carousel.querySelectorAll('[data-carousel-slide]')];
    const controls = carousel.querySelector('[data-carousel-controls]');
    const previous = carousel.querySelector('[data-carousel-previous]');
    const next = carousel.querySelector('[data-carousel-next]');
    const dots = [...carousel.querySelectorAll('[data-carousel-index]')];
    const status = carousel.querySelector('[data-carousel-status]');
    if (!slides.length || !controls || !previous || !next || !status || dots.length !== slides.length) continue;

    const reducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let active = 0;
    let clearAnimation = () => {};

    function animateIncoming(slide, direction) {
      slide.dataset.enterDirection = direction < 0 ? 'backward' : 'forward';
      // Commit a fresh starting state when rapid navigation returns to this slide.
      slide.getBoundingClientRect();
      slide.classList.add('carousel-slide-entering');
      const finish = (event) => {
        if (event && event.target !== slide) return;
        slide.classList.remove('carousel-slide-entering');
        delete slide.dataset.enterDirection;
        slide.removeEventListener('animationend', finish);
        slide.removeEventListener('animationcancel', finish);
        clearTimeout(fallback);
      };
      const fallback = setTimeout(() => finish(), 260);
      slide.addEventListener('animationend', finish);
      slide.addEventListener('animationcancel', finish);
      clearAnimation = () => finish();
    }

    function show(index, animate = true) {
      const destination = (index + slides.length) % slides.length;
      if (animate && destination === active) return;
      const direction = index < active ? -1 : 1;
      clearAnimation();
      active = destination;
      slides.forEach((slide, position) => {
        slide.hidden = position !== active;
        slide.setAttribute('role', 'group');
        slide.setAttribute('aria-roledescription', 'slide');
        slide.setAttribute('aria-label', `${position + 1} of ${slides.length}: ${slide.dataset.slideTitle}`);
      });
      dots.forEach((dot, position) => {
        if (position === active) dot.setAttribute('aria-current', 'true');
        else dot.removeAttribute('aria-current');
      });
      status.textContent = `${active + 1} of ${slides.length} · ${slides[active].dataset.slideTitle}`;
      if (animate && !reducedMotion.matches) animateIncoming(slides[active], direction);
    }

    reducedMotion.addEventListener('change', () => {
      if (reducedMotion.matches) clearAnimation();
    });

    previous.addEventListener('click', () => show(active - 1));
    next.addEventListener('click', () => show(active + 1));
    dots.forEach((dot, index) => dot.addEventListener('click', () => show(index)));
    carousel.addEventListener('keydown', (event) => {
      if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
      if (event.altKey || event.ctrlKey || event.metaKey || event.shiftKey) return;
      if (!carousel.contains(document.activeElement)) return;
      if (event.target.closest('input, textarea, select, [contenteditable]:not([contenteditable="false"])')) return;
      event.preventDefault();
      const dotFocused = dots.includes(document.activeElement);
      show(active + (event.key === 'ArrowRight' ? 1 : -1));
      if (dotFocused) dots[active].focus();
    });

    carousel.setAttribute('aria-roledescription', 'carousel');
    carousel.tabIndex = 0;
    show(0, false);
    controls.hidden = false;
  }
})();
