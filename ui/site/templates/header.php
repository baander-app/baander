<?php declare(strict_types=1); ?>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="/" aria-label="Bånder home"><img src="/assets/site/logo.svg" width="40" height="40" alt="">Bånder</a>
    <nav aria-label="Main navigation">
      <a href="/#how-it-works">How it works</a>
      <a href="/docs/operator-guide/"<?= $page['guide'] === 'operator' ? ' aria-current="true"' : '' ?>>Manual</a>
      <a href="<?= $escape($site['revisionUrl']) ?>">Source <span aria-hidden="true">↗</span></a>
    </nav>
  </div>
</header>
