<?php declare(strict_types=1);
// One figure of a feature page: $figure is an application picture or a block borrowed from the
// manual, and $hero says whether it opens the page.
?>
<?php if (isset($figure['picture'])): ?>
  <?php
  // A picture is captured at twice the size it is shown at. The page reserves that half size, and
  // the figure carries the width so the stylesheet never shows the picture larger.
  $screenshot = [
      'name' => $figure['picture'],
      'alt' => $figure['alt'],
      'width' => intdiv($figure['width'], 2),
      'height' => intdiv($figure['height'], 2),
      ...($hero ? [] : ['loading' => 'lazy']),
  ];
  ?>
  <figure class="app-screenshot <?= $hero ? 'hero-screenshot' : 'feature-screenshot' ?>" style="--picture-width: <?= $screenshot['width'] ?>px">
    <?php include __DIR__ . '/screenshot.php'; ?>
    <figcaption><?= $escape($figure['caption']) ?></figcaption>
  </figure>
<?php else: ?>
  <div class="feature-manual-block manual-article"><?= $figure['html'] ?></div>
<?php endif; ?>
