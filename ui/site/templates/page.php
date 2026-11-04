<?php declare(strict_types=1);
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
?>
<div class="manual-layout">
  <aside class="chapter-sidebar" aria-label="Manual navigation">
    <section id="manual-search" aria-label="Search manuals"><noscript>Search needs JavaScript. Browse the chapters below to find a topic.</noscript></section>
    <details class="chapter-navigation" open>
      <summary>Manual chapters</summary>
      <nav aria-label="Manual chapters">
        <?php foreach (['operator' => 'Operator manual', 'developer' => 'Developer guide'] as $guide => $label): ?>
          <div class="chapter-group">
            <p class="eyebrow"><?= $label ?></p>
            <ul>
              <?php foreach ($navigation[$guide] as $chapter): ?>
                <?php if (isset($chapter['children'])): ?>
                  <?php $inChapter = in_array($page['url'], [$chapter['url'], ...array_column($chapter['children'], 'url')], true); ?>
                  <li class="chapter-fold">
                    <details<?= $inChapter ? ' open' : '' ?>>
                      <summary><?= $escape($chapter['title']) ?></summary>
                      <ul>
                        <li><a href="<?= $escape($chapter['url']) ?>"<?= $chapter['url'] === $page['url'] ? ' aria-current="page"' : '' ?>>Overview</a></li>
                        <?php
                        $groups = [];
                        foreach ($chapter['children'] as $child) {
                            $label = $child['group'] ?? null;
                            if ($groups === [] || end($groups)['label'] !== $label) {
                                $groups[] = ['label' => $label, 'children' => []];
                            }
                            $groups[array_key_last($groups)]['children'][] = $child;
                        }
                        ?>
                        <?php foreach ($groups as $group): ?>
                          <?php if ($group['label'] === null): ?>
                            <?php foreach ($group['children'] as $child): ?>
                              <li><a href="<?= $escape($child['url']) ?>"<?= $child['url'] === $page['url'] ? ' aria-current="page"' : '' ?>><?= str_replace(':', ':<wbr>', $escape($child['title'])) ?></a></li>
                            <?php endforeach; ?>
                          <?php else: ?>
                            <li class="chapter-fold-group">
                              <span class="chapter-fold-label"><?= $escape($group['label']) ?></span>
                              <ul>
                                <?php foreach ($group['children'] as $child): ?>
                                  <li><a href="<?= $escape($child['url']) ?>"<?= $child['url'] === $page['url'] ? ' aria-current="page"' : '' ?>><?= str_replace(':', ':<wbr>', $escape($child['title'])) ?></a></li>
                                <?php endforeach; ?>
                              </ul>
                            </li>
                          <?php endif; ?>
                        <?php endforeach; ?>
                      </ul>
                    </details>
                  </li>
                <?php else: ?>
                  <li><a href="<?= $escape($chapter['url']) ?>"<?= $chapter['url'] === $page['url'] ? ' aria-current="page"' : '' ?>><?= $escape($chapter['title']) ?></a></li>
                <?php endif; ?>
              <?php endforeach; ?>
            </ul>
          </div>
        <?php endforeach; ?>
      </nav>
    </details>
  </aside>
  <main id="main" class="manual-main">
    <div class="manual-breadcrumb"><a href="/">Bånder</a><span aria-hidden="true">/</span><a href="<?= $page['guide'] === 'developer' ? '/docs/developer-guide/' : '/docs/operator-guide/' ?>"><?= $page['guide'] === 'developer' ? 'Developer guide' : 'Operator manual' ?></a></div>
    <?php if (count($page['headings']) > 1): ?>
      <details class="section-navigation">
        <summary>On this page</summary>
        <nav aria-label="On this page">
          <ul>
            <?php foreach ($page['headings'] as $heading): ?>
              <?php if ($heading['level'] === 2): ?>
                <li><a href="#<?= $escape($heading['id']) ?>"><?= $escape($heading['title']) ?></a></li>
              <?php endif; ?>
            <?php endforeach; ?>
          </ul>
        </nav>
      </details>
    <?php endif; ?>
    <article class="manual-article" data-pagefind-body>
      <span class="visually-hidden" data-pagefind-meta="guide"><?= $page['guide'] === 'developer' ? 'Developer guide' : 'Operator manual' ?></span>
      <?= $page['html'] ?>
    </article>
    <div class="manual-source"><a href="<?= $escape(preg_replace('~/tree/~', '/blob/', $site['revisionUrl']) . '/docs-book/' . $page['source']) ?>">Read this page's source <span aria-hidden="true">↗</span></a></div>
  </main>
</div>
<?php include __DIR__ . '/footer.php'; ?>
