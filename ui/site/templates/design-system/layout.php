<?php declare(strict_types=1);
$scales = [
    ['id' => 'spacing-scale', 'title' => 'Spacing scale', 'steps' => $section['values']['spacing'], 'sample' => 'space'],
    ['id' => 'radius-scale', 'title' => 'Radius scale', 'steps' => $section['values']['radii'], 'sample' => 'radius'],
];
?>
<p>A token’s documented use is the label of each item in the Border radius list below that names its pixel value, and of each item in the Spacing list that names it and describes a padding or a gap in its label or first sentence. A token that no such item names has none.</p>
<?php foreach ($scales as $scale): ?>
  <section class="ds-layout" aria-labelledby="<?= $escape($scale['id']) ?>">
    <h2 id="<?= $escape($scale['id']) ?>"><?= $escape($scale['title']) ?></h2>
    <div class="ds-table ds-layout-scale" role="region" tabindex="0" aria-label="<?= $escape($scale['title']) ?>">
      <table>
        <thead>
          <tr><th scope="col">Token</th><th scope="col">rem</th><th scope="col">px</th><th scope="col"><span class="visually-hidden">Sample</span></th><th scope="col">Documented use</th></tr>
        </thead>
        <tbody>
          <?php foreach ($scale['steps'] as $step): ?>
            <tr data-token="<?= $escape($step['name']) ?>">
              <th scope="row"><code><?= $escape($step['name']) ?></code></th>
              <td class="ds-value" data-pagefind-ignore><?= $escape($step['value']) ?></td>
              <td class="ds-value" data-pagefind-ignore><?= $escape((string) $step['px'] . 'px') ?></td>
              <td data-pagefind-ignore><span class="ds-layout-<?= $escape($scale['sample']) ?>" style="--sample: var(<?= $escape($step['name']) ?>)" aria-hidden="true"></span></td>
              <td><?= $step['uses'] === [] ? 'No documented use' : $escape(implode('; ', $step['uses'])) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
<?php endforeach; ?>
<?php foreach (array_keys($section['borrowed']) as $id): ?>
  <?= $borrowed($id) ?>
<?php endforeach; ?>
<section class="ds-layout" aria-labelledby="layout-well">
  <h2 id="layout-well">The well in the application</h2>
  <p>This site shows the well only in screenshots of the application. The footer player’s centerpiece holds it by default; the <a href="/features/player/">player feature page</a> shows the player bar along the bottom of the window.</p>
</section>
