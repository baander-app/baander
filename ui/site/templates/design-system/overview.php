<?php declare(strict_types=1); ?>
<?= $borrowed('philosophy') ?>
<section class="ds-index" aria-labelledby="pages">
  <h2 id="pages">The pages</h2>
  <ul class="ds-pages">
    <?php foreach ($section['values']['pages'] as $entry): ?>
      <li><a href="<?= $escape($entry['url']) ?>"><?= $escape($entry['title']) ?></a> <span><?= $escape($entry['summary']) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
