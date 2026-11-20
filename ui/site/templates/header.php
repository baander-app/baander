<?php declare(strict_types=1);
$markCurrent = static fn (?string $mark): string => $mark === null ? '' : ' aria-current="' . $mark . '"';
$menuColumns = static function (array $columns) use ($escape, $markCurrent): void {
    foreach ($columns as $column): ?>
      <div class="menu-column<?= count($column['groups']) > 1 ? ' menu-column-grouped' : '' ?>">
        <?php if ($column['title'] !== null): ?>
          <p class="menu-column-title"><?= $escape($column['title']) ?></p>
        <?php endif; ?>
        <?php foreach ($column['groups'] as $group): ?>
          <div class="menu-subgroup<?= $group['title'] !== null ? ' menu-subgroup-compact' : '' ?>">
            <?php if ($group['title'] !== null): ?>
              <p class="menu-group-title"><?= $escape($group['title']) ?></p>
            <?php endif; ?>
            <ul class="menu-group">
              <?php foreach ($group['entries'] as $entry): ?>
                <li class="menu-entry">
                  <a href="<?= $escape($entry['url']) ?>"<?= $markCurrent($entry['current']) ?>><?= $escape($entry['title']) ?></a>
                  <?php if ($group['title'] === null): ?>
                    <span class="menu-entry-description"><?= $escape($entry['description']) ?></span>
                  <?php endif; ?>
                </li>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endforeach;
};
?>
<header class="site-header">
  <div class="header-inner">
    <a class="brand" href="/" aria-label="Bånder home"><img src="/assets/site/logo.svg" width="40" height="40" alt="">Bånder</a>
    <div class="header-actions">
      <nav aria-label="Main navigation">
        <?php foreach ($site['menu'] as $panel): ?>
          <details class="menu-panel" name="site-menu" data-site-menu>
            <summary<?= $panel['current'] ? ' aria-current="true"' : '' ?>><?= $escape($panel['title']) ?></summary>
            <div class="menu-panel-body menu-columns-<?= count($panel['columns']) ?>"><?php $menuColumns($panel['columns']); ?></div>
          </details>
        <?php endforeach; ?>
        <a class="menu-source" href="<?= $escape($site['repositoryUrl']) ?>">Source <span class="arrow" aria-hidden="true">↗</span></a>
        <details class="menu-phone" name="site-menu" data-site-menu>
          <summary<?= array_any($site['menu'], static fn (array $panel): bool => $panel['current']) ? ' aria-current="true"' : '' ?>>Menu</summary>
          <div class="menu-panel-body">
            <?php foreach ($site['menu'] as $panel): ?>
              <div class="menu-section">
                <p class="menu-panel-title"><?= $escape($panel['title']) ?></p>
                <?php $menuColumns($panel['columns']); ?>
              </div>
            <?php endforeach; ?>
            <a class="menu-phone-source" href="<?= $escape($site['repositoryUrl']) ?>">Source <span class="arrow" aria-hidden="true">↗</span></a>
          </div>
        </details>
      </nav>
      <button class="theme-toggle" type="button" data-appearance-toggle aria-label="Switch between light and dark mode">
        <svg class="theme-icon-sun" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" aria-hidden="true"><circle cx="12" cy="12" r="4"/><path d="M12 2v2m0 16v2M2 12h2m16 0h2M5 5l1.5 1.5m11 11L19 19M5 19l1.5-1.5m11-11L19 5"/></svg>
        <svg class="theme-icon-moon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M20.5 13.5A8.5 8.5 0 0 1 10.5 3a8.5 8.5 0 1 0 10 10.5Z"/></svg>
      </button>
    </div>
  </div>
</header>
<span id="header-end" hidden></span>
