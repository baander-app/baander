<?php declare(strict_types=1); ?>
<footer class="site-footer">
  <div class="footer-intro">
    <a class="footer-brand" href="/"><img src="/assets/site/logo.svg" width="32" height="32" alt="">Bånder</a>
    <p>A media server you host.</p>
  </div>
  <nav aria-label="Footer navigation">
    <?php foreach ($site['menu'] as $panel): ?>
      <div class="footer-panel">
        <p class="footer-panel-title"><?= $escape($panel['title']) ?></p>
        <ul class="footer-group">
          <?php foreach ($panel['columns'] as $column): ?>
            <?php foreach ($column['groups'] as $group): ?>
              <?php foreach ($group['entries'] as $entry): ?>
                <li><a href="<?= $escape($entry['url']) ?>"><?= $escape($entry['title']) ?></a></li>
              <?php endforeach; ?>
            <?php endforeach; ?>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php endforeach; ?>
  </nav>
  <div class="footer-bottom">
    <a href="https://www.juul.xyz/">Made by Martin Juul</a>
    <a href="<?= $escape($site['repositoryUrl']) ?>">Source</a>
  </div>
</footer>
</body>
</html>
