<?php declare(strict_types=1); ?>
<footer class="site-footer">
  <a class="footer-brand" href="/">Bånder</a>
  <p>A media server you host. <a href="https://www.juul.xyz/">Made by Martin Juul</a>.</p>
  <nav aria-label="Footer navigation">
    <?php foreach ($site['menu'] as $panel): ?>
      <div class="footer-panel">
        <p class="footer-panel-title"><?= $escape($panel['title']) ?></p>
        <?php foreach ($panel['columns'] as $column): ?>
          <?php foreach ($column['groups'] as $position => $group): ?>
            <div class="footer-group">
              <?php $label = $group['title'] ?? ($position === 0 ? $column['title'] : null); ?>
              <?php if ($label !== null): ?>
                <p class="footer-group-title"><?= $escape($label) ?></p>
              <?php endif; ?>
              <ul>
                <?php foreach ($group['entries'] as $entry): ?>
                  <li><a href="<?= $escape($entry['url']) ?>"><?= $escape($entry['title']) ?></a></li>
                <?php endforeach; ?>
              </ul>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    <?php endforeach; ?>
    <a class="footer-source" href="<?= $escape($site['repositoryUrl']) ?>">Source</a>
  </nav>
</footer>
</body>
</html>
