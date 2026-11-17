<?php declare(strict_types=1);
$feature = $data['feature'];
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
?>
<main id="main" class="feature-page">
  <nav class="manual-breadcrumb feature-breadcrumb" aria-label="Breadcrumb">
    <?php foreach (array_slice($page['meta']->breadcrumbs, 1) as $crumb): ?>
      <a href="<?= $escape($crumb['url']) ?>"><?= $escape($crumb['name']) ?></a>
      <span aria-hidden="true">/</span>
    <?php endforeach; ?>
    <span><?= $escape($data['group']['title']) ?></span>
  </nav>

  <section class="hero" aria-labelledby="feature-title">
    <div class="hero-copy">
      <p class="eyebrow"><?= $escape($feature['name']) ?></p>
      <h1 id="feature-title"><?= $feature['headline'] ?></h1>
      <?php foreach ($feature['lead'] as $paragraph): ?>
        <p class="hero-description"><?= $paragraph ?></p>
      <?php endforeach; ?>
    </div>
    <?php
    $figure = $feature['hero'];
    $hero = true;
    include __DIR__ . '/feature-figure.php';
    ?>
  </section>

  <?php
  // Sections with a figure beside the text take turns: text left first, then text right.
  $hero = false;
  $textRight = true;
  ?>
  <?php foreach ($feature['tour'] as $section): ?>
    <?php
    $figure = $section['figure'];
    $wide = $figure !== null && $section['layout'] === 'wide';
    $beside = $figure !== null && !$wide;
    $textRight = $beside ? !$textRight : $textRight;
    $layout = match (true) {
        $wide => 'wide-feature',
        $beside && $textRight => 'experience-feature experience-feature-reversed',
        $beside => 'experience-feature',
        default => 'feature-text',
    };
    $title = $section['id'] . '-title';
    ?>
    <section id="<?= $escape($section['id']) ?>" class="home-section <?= $layout ?>" aria-labelledby="<?= $escape($title) ?>">
      <?php if ($wide): ?>
        <div class="section-intro wide-feature-intro">
          <div>
            <h2 id="<?= $escape($title) ?>"><?= $section['heading'] ?></h2>
          </div>
          <div>
            <?php foreach ($section['text'] as $paragraph): ?>
              <p><?= $paragraph ?></p>
            <?php endforeach; ?>
          </div>
        </div>
      <?php else: ?>
        <div class="section-intro">
          <h2 id="<?= $escape($title) ?>"><?= $section['heading'] ?></h2>
          <?php foreach ($section['text'] as $paragraph): ?>
            <p><?= $paragraph ?></p>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <?php if ($figure !== null) {
          include __DIR__ . '/feature-figure.php';
      } ?>
    </section>
  <?php endforeach; ?>

  <section class="home-section feature-columns" aria-labelledby="try-it-title">
    <div class="section-intro">
      <h2 id="try-it-title">Try it</h2>
      <p><?= $feature['guide']['needs'] ?></p>
    </div>
    <div>
      <ol class="process-list">
        <?php foreach ($feature['guide']['steps'] as $position => $step): ?>
          <li><span class="step-number" aria-hidden="true"><?= sprintf('%02d', $position + 1) ?></span><div><h3><?= $escape($step['title']) ?></h3><p><?= $step['text'] ?></p></div></li>
        <?php endforeach; ?>
      </ol>
      <?php foreach ($feature['planned'] as $note): ?>
        <aside class="feature-note" aria-label="<?= $escape($note['title']) ?>">
          <p><strong><?= $escape($note['title']) ?></strong> <?= $note['text'] ?></p>
        </aside>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="home-section feature-columns" aria-labelledby="good-to-know-title">
    <div class="section-intro">
      <h2 id="good-to-know-title">Good to know</h2>
    </div>
    <ul class="process-list feature-limits">
      <?php foreach ($feature['limits'] as $limit): ?>
        <li><p><?= $limit ?></p></li>
      <?php endforeach; ?>
    </ul>
  </section>

  <?php if ($feature['manual'] !== []): ?>
    <section class="home-section feature-columns" aria-labelledby="in-the-manual-title">
      <div class="section-intro">
        <h2 id="in-the-manual-title">In the manual</h2>
      </div>
      <ul class="process-list">
        <?php foreach ($feature['manual'] as $link): ?>
          <li><a class="text-action" href="<?= $escape($link['href']) ?>"><?= $escape($link['label']) ?> <span class="arrow" aria-hidden="true">→</span></a></li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

  <nav class="feature-pager" aria-label="Feature pages">
    <?php if ($data['previous'] !== null): ?>
      <a class="text-action" rel="prev" href="<?= $escape($data['previous']['url']) ?>"><span class="arrow" aria-hidden="true">←</span><span><span class="visually-hidden">Previous: </span><?= $escape($data['previous']['name']) ?></span></a>
    <?php endif; ?>
    <a class="text-action feature-pager-overview" href="<?= $escape($data['overview']) ?>">All features</a>
    <?php if ($data['next'] !== null): ?>
      <a class="text-action" rel="next" href="<?= $escape($data['next']['url']) ?>"><span><span class="visually-hidden">Next: </span><?= $escape($data['next']['name']) ?></span><span class="arrow" aria-hidden="true">→</span></a>
    <?php endif; ?>
  </nav>
</main>
<?php include __DIR__ . '/footer.php'; ?>
