<?php declare(strict_types=1);
// A picture reserves the size of its files unless the page that shows it states another.
$screenshot += $site['screenshots'][$screenshot['name']];
?>
<?php foreach (['dark' => '', 'light' => '-light'] as $appearance => $suffix): ?>
  <img
    class="screenshot-<?= $appearance ?>"
    src="/assets/site/screenshots/<?= $escape($screenshot['name'] . $suffix) ?>.webp"
    width="<?= (int) $screenshot['width'] ?>"
    height="<?= (int) $screenshot['height'] ?>"
    <?php if (isset($screenshot['loading'])): ?>loading="<?= $escape($screenshot['loading']) ?>"<?php endif; ?>
    alt="<?= $escape($screenshot['alt']) ?>"
  >
<?php endforeach; ?>
