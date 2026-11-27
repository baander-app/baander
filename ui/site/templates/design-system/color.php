<?php declare(strict_types=1);
// Every documented color role, a row per variable, with its value in each mood at the default accent.
// Variables that share one use share its row header.
$values = $section['values'];
$value = static fn(string $color): string => '<span class="ds-value" data-pagefind-ignore>' . $swatch($color) . '</span>';
?>
<section class="ds-color" aria-labelledby="roles-and-values">
  <h2 id="roles-and-values">Roles and values</h2>
  <div class="ds-color-note">
    <p>The swatches show the application’s values with the default accent, <?= $escape($values['defaultAccent']) ?>. This website’s dark theme softens the application’s text color, and this page uses the website’s; the <a href="/design-system/website/">website page</a> explains why.</p>
    <dl class="ds-color-foregrounds">
      <dt>Application</dt><dd><?= $value($values['appForeground']) ?></dd>
      <dt>This website</dt><dd><?= $value($values['siteForeground']) ?></dd>
    </dl>
  </div>
  <div class="ds-table ds-color-table" role="region" tabindex="0" aria-label="Color roles in each mood">
    <table>
      <thead>
        <tr>
          <th scope="col">Use</th>
          <th scope="col">Variable</th>
          <?php foreach ($values['moods'] as $label): ?>
            <th scope="col"><?= $escape($label) ?></th>
          <?php endforeach; ?>
        </tr>
      </thead>
      <?php foreach ($values['roles'] as $role): ?>
        <tbody>
          <?php foreach ($role['variables'] as $position => $variable): ?>
            <tr>
              <?php if ($position === 0): ?>
                <th scope="rowgroup" rowspan="<?= count($role['variables']) ?>"><?= $escape($role['use']) ?><?php if ($role['detail'] !== null): ?><span class="ds-color-detail"><?= $escape($role['detail']) ?></span><?php endif; ?></th>
              <?php endif; ?>
              <th scope="row"><code><?= $escape($variable['name']) ?></code></th>
              <?php foreach (array_keys($values['moods']) as $mood): ?>
                <td class="ds-value" data-pagefind-ignore><?= $swatch($variable['values'][$mood]) ?></td>
              <?php endforeach; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      <?php endforeach; ?>
    </table>
  </div>
</section>
<?= $borrowed('token-authority') ?>
