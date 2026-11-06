<?php declare(strict_types=1);
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
?>
<div class="manual-layout">
  <aside class="chapter-sidebar" aria-label="Manual navigation">
    <?php // The form is rendered here so the sidebar has its final layout before the search script enables it. ?>
    <section id="manual-search" aria-label="Search manuals">
      <label for="manual-search-input">Search manuals</label>
      <input id="manual-search-input" type="search" placeholder="Find a task or topic" autocomplete="off" spellcheck="false" aria-describedby="manual-search-status" aria-controls="manual-search-results" disabled>
      <noscript>Search needs JavaScript. Browse the chapters below to find a topic.</noscript>
      <p id="manual-search-status" role="status" aria-live="polite">Search the operator and developer guides.</p>
      <ol id="manual-search-results" hidden></ol>
    </section>
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
    <nav class="manual-breadcrumb" aria-label="Breadcrumb">
      <?php foreach ($page['meta']->breadcrumbs as $index => $crumb): ?>
        <?php if ($index > 0): ?><span aria-hidden="true">/</span><?php endif; ?>
        <a href="<?= $escape($crumb['url']) ?>"><?= $escape($crumb['name']) ?></a>
      <?php endforeach; ?>
    </nav>
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
    <div class="manual-source"><a href="<?= $escape(preg_replace('~/tree/~', '/blob/', $site['revisionUrl']) . '/docs-book/' . $page['source']) ?>">Read this page's source <span class="arrow" aria-hidden="true">↗</span></a></div>
  </main>
</div>
<?php include __DIR__ . '/footer.php'; ?>
