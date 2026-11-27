<?php declare(strict_types=1);
// One page of the design system section. The page's body comes from design-system/<slug>.php, which
// receives $section, $borrowed and $swatch besides the variables every template has.
$section = $data['designSystem'];
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
/** A section borrowed from a design document, under its own heading. */
$borrowed = static function (string $id) use ($section, $escape): string {
    $part = $section['borrowed'][$id] ?? throw new RuntimeException('The design system page ' . $section['slug'] . ' borrows no section ' . $id . '.');

    return '<section class="ds-borrowed" aria-labelledby="' . $escape($id) . '"><h2 id="' . $escape($id) . '">' . $escape($part['title']) . '</h2>'
        . $part['html'] . '</section>';
};
/** A color shown as a swatch beside its code; the code carries the value. */
$swatch = static fn (string $color): string => '<span class="ds-swatch" style="--swatch: ' . $escape($color) . '" aria-hidden="true"></span><code>' . $escape($color) . '</code>';
?>
<div class="manual-layout design-system">
  <aside class="chapter-sidebar" aria-label="Design system pages">
    <details class="chapter-navigation" open>
      <summary>Design system</summary>
      <nav aria-label="Design system">
        <div class="chapter-group">
          <ul>
            <?php foreach ($section['navigation'] as $link): ?>
              <li><a href="<?= $escape($link['url']) ?>"<?= $link['current'] ? ' aria-current="page"' : '' ?>><?= $escape($link['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="chapter-group">
          <p class="eyebrow">Related</p>
          <ul>
            <?php foreach ($section['related'] as $link): ?>
              <li><a href="<?= $escape($link['url']) ?>"><?= $escape($link['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </div>
      </nav>
    </details>
  </aside>
  <main id="main" class="manual-main">
    <nav class="manual-breadcrumb" aria-label="Breadcrumb">
      <?php foreach ($page['meta']->breadcrumbs as $index => $crumb): ?>
        <?php if ($index > 0): ?><span aria-hidden="true">/</span><?php endif; ?>
        <a href="<?= $escape($crumb['url']) ?>"><?= $escape($crumb['name']) ?></a>
      <?php endforeach; ?>
      <span aria-hidden="true">/</span>
      <span aria-current="page"><?= $escape($section['title']) ?></span>
    </nav>
    <article class="manual-article ds-article" data-pagefind-body>
      <span class="visually-hidden" data-pagefind-meta="label">Design system</span>
      <h1><?= $escape($section['title']) ?></h1>
      <p class="ds-opening"><?= $section['opening'] ?></p>
      <?php include __DIR__ . '/design-system/' . $section['slug'] . '.php'; ?>
    </article>
  </main>
</div>
<?php include __DIR__ . '/footer.php'; ?>
