<?php declare(strict_types=1);
// The accent identities, then a table per mood of what it derives from each accent. A measured cell
// holds the derived color, the color it is measured against and the ratio between the two.
use Baander\DevWebsite\Content\Contrast;

$values = $section['values'];
$value = static fn(string $color): string => '<span class="ds-value">' . $swatch($color) . '</span>';
$ratio = static fn(float $ratio): string => '<span class="ds-accents-ratio">' . Contrast::format($ratio) . ':1</span>';
$target = static fn(float $target): string => '<span class="ds-value" data-pagefind-ignore>' . $escape((string) $target) . ':1</span>';
$surfaceNames = array_keys($values['surfaces'][array_key_first($values['surfaces'])][$values['defaultAccent']]);
$label = static fn(string $accent): string => $escape(ucfirst($accent))
    . ($accent === $values['defaultAccent'] ? ' <span class="ds-accents-default">default</span>' : '');
/** A derived color with the lowest ratio it reaches and the surface that ratio falls on. */
$lowest = static fn(string $color, array $contrast, array $surfaces): string => $value($color)
    . '<span class="ds-accents-measure">' . $ratio($contrast['ratio']) . ' on ' . $escape($contrast['surface']) . ' ' . $value($surfaces[$contrast['surface']]) . '</span>';
?>
<section class="ds-accents" aria-labelledby="identity-colors">
  <h2 id="identity-colors">Identity colors</h2>
  <ul class="ds-accents-identities">
    <?php foreach ($values['accents'] as $accent => $identity): ?>
      <li><span class="ds-accents-name"><?= $label($accent) ?></span><span class="ds-value" data-pagefind-ignore><?= $swatch($identity) ?></span></li>
    <?php endforeach; ?>
  </ul>
</section>
<section class="ds-accents" aria-labelledby="derived-colors">
  <h2 id="derived-colors">Derived colors</h2>
  <p>Each table below takes one mood and shows what it derives from each accent: the fill of a filled action with its label, accent text, and the focus ring. The label is measured against its fill. Accent text and the ring are measured against every surface the token generator checks, <?= $escape(implode(', ', array_slice($surfaceNames, 0, -1)) . ' and ' . $surfaceNames[array_key_last($surfaceNames)]) ?>, and the table gives the lowest ratio with the surface it falls on.</p>
  <p class="ds-accents-targets">This site’s build fails when a ratio falls below the target the design tokens set: <?= $target($values['targets']['text']) ?> for the label and for accent text, and <?= $target($values['targets']['indicator']) ?> for the ring. The generator also holds each fill at <?= $target($values['targets']['action']) ?> against the surfaces; the tables leave that ratio out. Ratios are truncated to two decimals, so a ratio just below its target never reads as meeting it.</p>
  <?php foreach ($values['moods'] as $mood => $moodLabel): ?>
    <h3 id="derived-<?= $escape($mood) ?>"><?= $escape($moodLabel) ?></h3>
    <div class="ds-table ds-accents-table" role="region" tabindex="0" aria-label="<?= $escape($moodLabel) ?> mood: colors derived from each accent">
      <table>
        <thead>
          <tr>
            <th scope="col">Accent</th>
            <th scope="col">Primary and its label</th>
            <th scope="col">Accent text</th>
            <th scope="col">Focus ring</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($values['derivations'][$mood] as $accent => $derivation): ?>
            <?php $surfaces = $values['surfaces'][$mood][$accent]; ?>
            <tr>
              <th scope="row"><span class="ds-accents-name"><?= $label($accent) ?></span><span class="ds-value" data-pagefind-ignore><?= $swatch($derivation['identity']) ?></span></th>
              <td data-pagefind-ignore><?= $value($derivation['primary']) ?><span class="ds-accents-measure"><?= $ratio($derivation['fill']) ?> with label <?= $value($derivation['primaryForeground']) ?></span></td>
              <td data-pagefind-ignore><?= $lowest($derivation['accentText'], $derivation['text'], $surfaces) ?></td>
              <td data-pagefind-ignore><?= $lowest($derivation['ring'], $derivation['ringContrast'], $surfaces) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endforeach; ?>
</section>
<?= $borrowed('dynamic-accent') ?>
<?= $borrowed('accent-contrast') ?>
