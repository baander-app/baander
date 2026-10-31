<?php declare(strict_types=1); ?>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="/" aria-label="Bånder home"><img src="/assets/site/logo.svg" width="40" height="40" alt="">Bånder</a>
    <div class="header-actions">
      <nav aria-label="Main navigation">
        <a href="/#how-it-works">How it works</a>
        <a href="/docs/operator-guide/"<?= $page['guide'] === 'operator' ? ' aria-current="true"' : '' ?>>Manual</a>
        <a href="/baac/"<?= $page['url'] === '/baac/' ? ' aria-current="page"' : '' ?>>bAAC</a>
        <a href="<?= $escape($site['repositoryUrl']) ?>">Source <span aria-hidden="true">↗</span></a>
      </nav>
      <button class="theme-toggle" type="button" data-appearance-toggle hidden>
        <svg class="theme-icon-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
        <svg class="theme-icon-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 13.5A8.5 8.5 0 0 1 10.5 3a8.5 8.5 0 1 0 10 10.5Z"/></svg>
      </button>
    </div>
  </div>
</header>
