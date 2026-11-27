<?php declare(strict_types=1);
$roles = $section['values']['roles'];
// Tracking as the design language writes it: a true minus sign, a plus sign on positive values and a
// bare 0 for none.
$tracking = static fn (string $value): string => match (true) {
    (float) $value === 0.0 => '0',
    str_starts_with($value, '-') => '−' . substr($value, 1),
    default => '+' . $value,
};
?>
<section class="ds-type" aria-labelledby="type-values">
  <h2 id="type-values">Values</h2>
  <div class="ds-table ds-type-roles" role="region" tabindex="0" aria-label="Type roles and their values">
    <table>
      <thead>
        <tr><th scope="col">Role</th><th scope="col">Desktop</th><th scope="col">Phone</th><th scope="col">Weight</th><th scope="col">Tracking</th><th scope="col">Line-height</th><th scope="col">Use</th></tr>
      </thead>
      <tbody>
        <?php foreach ($roles as $role => $values): ?>
          <tr data-role="<?= $escape($role) ?>">
            <th scope="row"><code><?= $escape($role) ?></code></th>
            <td class="ds-value" data-pagefind-ignore><?= $escape($values['desktop']) ?></td>
            <td class="ds-value" data-pagefind-ignore><?= $escape($values['phone']) ?></td>
            <td class="ds-value" data-pagefind-ignore><?= $escape($values['weight'] . ($values['mono'] ? ', mono' : '')) ?></td>
            <td class="ds-value" data-pagefind-ignore><?= $escape($tracking($values['tracking']) . ($values['transform'] === null ? '' : ', ' . $values['transform'])) ?></td>
            <td class="ds-value" data-pagefind-ignore><?= $escape($values['lineHeight']) ?></td>
            <td><?= $escape($values['use']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<section class="ds-type" aria-labelledby="type-samples">
  <h2 id="type-samples">Samples</h2>
  <p>Each sample is set in its role and follows your window’s width: a phone-width window shows the phone sizes.</p>
  <ol class="ds-type-samples">
    <?php foreach ($roles as $role => $values): ?>
      <li data-role="<?= $escape($role) ?>">
        <p class="ds-type-name"><code><?= $escape($role) ?></code></p>
        <p class="type type-<?= $escape($role) ?>" lang="en" data-pagefind-ignore><?= $escape($values['sample']) ?></p>
      </li>
    <?php endforeach; ?>
  </ol>
</section>
<?= $borrowed('roles') ?>
<section class="ds-type" aria-labelledby="type-scripts">
  <h2 id="type-scripts">Scripts</h2>
  <p>Script rules depend on the characters an element contains, and they override a role where the two conflict. The typography page shows them in live text:</p>
  <ul class="ds-type-links">
    <li><a href="/typography/#scripts">Scripts</a>: each supported script in its bundled font.</li>
    <li><a href="/typography/#glyph-forms">Glyph forms</a>: the same Han characters in their Japanese, Simplified Chinese and Traditional Chinese forms.</li>
    <li><a href="/typography/#script-rules">Script rules</a>: how Thai, Burmese, Hangul and CJK characters change a role’s tracking, line-height and size.</li>
  </ul>
</section>
