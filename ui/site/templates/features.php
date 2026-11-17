<?php declare(strict_types=1);
$features = $data['features'];
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
?>
<main id="main" class="features-page">
  <section class="hero" aria-labelledby="features-title">
    <div class="hero-copy">
      <p class="eyebrow">Features</p>
      <h1 id="features-title"><?= $features['opening']['headline'] ?></h1>
      <?php foreach ($features['opening']['lead'] as $paragraph): ?>
        <p class="hero-description"><?= $paragraph ?></p>
      <?php endforeach; ?>
    </div>
  </section>

  <?php foreach ($features['groups'] as $group): ?>
    <?php $title = $group['id'] . '-title'; ?>
    <section id="<?= $escape($group['id']) ?>" class="home-section feature-columns" aria-labelledby="<?= $escape($title) ?>">
      <div class="section-intro">
        <h2 id="<?= $escape($title) ?>"><?= $escape($group['title']) ?></h2>
        <p><?= $group['lead'] ?></p>
      </div>
      <div class="feature-cards">
        <?php foreach ($group['pages'] as $card): ?>
          <a href="<?= $escape($card['url']) ?>"><h3><?= $escape($card['name']) ?></h3><p><?= $escape($card['summary']) ?></p><span class="arrow" aria-hidden="true">→</span></a>
        <?php endforeach; ?>
      </div>
    </section>
  <?php endforeach; ?>

  <?php if ($features['more'] !== []): ?>
    <section class="home-section feature-columns" aria-labelledby="more-title">
      <div class="section-intro">
        <h2 id="more-title">More in Bånder</h2>
      </div>
      <ul class="feature-more">
        <?php foreach ($features['more'] as $entry): ?>
          <li>
            <h3><?= $escape($entry['name']) ?></h3>
            <p><?= $entry['text'] ?></p>
            <a class="text-action" href="<?= $escape($entry['manual']['href']) ?>"><?= $escape($entry['manual']['label']) ?> <span class="arrow" aria-hidden="true">→</span></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
</main>
<?php include __DIR__ . '/footer.php'; ?>
