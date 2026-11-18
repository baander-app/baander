<?php declare(strict_types=1);
$reference = $data['reference'];
$presets = $reference['presets'];
include __DIR__ . '/head.php';
include __DIR__ . '/header.php';
$sections = array_values(array_filter($page['headings'], static fn (array $heading): bool => $heading['level'] === 2));
if ($presets !== null) {
    $sections[] = ['id' => 'example-presets', 'title' => 'Example presets compared', 'level' => 2];
}
?>
<main id="main" class="reference-page">
  <article class="manual-article" data-pagefind-body>
    <span class="visually-hidden" data-pagefind-meta="label"><?= $escape($reference['label']) ?></span>
    <?= $page['heading'] ?>
    <p class="reference-opening"><?= $reference['opening'] ?></p>
    <?php if ($sections !== []): ?>
      <details class="section-navigation" data-pagefind-ignore="all">
        <summary>On this page</summary>
        <nav aria-label="On this page">
          <ul>
            <?php foreach ($sections as $heading): ?>
              <li><a href="#<?= $escape($heading['id']) ?>"><?= $escape($heading['title']) ?></a></li>
            <?php endforeach; ?>
          </ul>
        </nav>
      </details>
    <?php endif; ?>
    <?= $page['html'] ?>
    <?php if ($presets !== null): ?>
      <h2 id="example-presets">Example presets compared</h2>
      <p>The six example presets in <code>deploy/preset/</code> set these values under <code>size</code>. A dash marks a setting the preset's capability does not use.</p>
      <div class="preset-table" role="region" tabindex="0" aria-label="Size settings of the example presets">
        <table>
          <thead>
            <tr>
              <th scope="col">Setting</th>
              <?php foreach ($presets['columns'] as $column): ?>
                <th scope="col"><?= $escape($column) ?></th>
              <?php endforeach; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($presets['rows'] as $row): ?>
              <tr>
                <th scope="row"><code><?= $escape($row['setting']) ?></code></th>
                <?php foreach ($row['values'] as $value): ?>
                  <?php if ($value === null): ?>
                    <td><span aria-hidden="true">—</span><span class="visually-hidden">not used</span></td>
                  <?php else: ?>
                    <td><?= $value ?></td>
                  <?php endif; ?>
                <?php endforeach; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </article>
</main>
<?php include __DIR__ . '/footer.php'; ?>
