<?php declare(strict_types=1);
$values = $section['values'];
$limit = $values['limit'];
/** Where a duration token is used; the application files fold into a disclosure, which can run long. */
$users = static function (array $duration) use ($escape): string {
    if ($duration['website'] === [] && $duration['application'] === []) {
        return 'No stylesheet uses it';
    }
    $html = '';
    if ($duration['website'] !== []) {
        $html .= '<p>Website: ' . implode(', ', array_map(static fn (string $path): string => '<code>' . $escape($path) . '</code>', $duration['website'])) . '</p>';
    }
    if ($duration['application'] !== []) {
        $count = count($duration['application']);
        $html .= '<details><summary>Application: ' . $count . ($count === 1 ? ' file' : ' files') . '</summary><ul>'
            . implode('', array_map(static fn (string $path): string => '<li><code>' . $escape($path) . '</code></li>', $duration['application']))
            . '</ul></details>';
    }

    return $html;
};
?>
<section class="ds-motion" aria-labelledby="motion-transitions">
  <h2 id="motion-transitions">Transitions</h2>
  <div class="ds-table ds-motion-transitions" role="region" tabindex="0" aria-label="Transitions and their durations">
    <table>
      <thead>
        <tr><th scope="col">Transition</th><th scope="col">Duration</th><th scope="col">Easing</th></tr>
      </thead>
      <tbody>
        <?php foreach ($values['rows'] as $row): ?>
          <tr>
            <th scope="row"><?= $escape($row['transition']) ?></th>
            <td class="ds-value" data-pagefind-ignore><?= $escape($row['duration']) ?></td>
            <td class="ds-value" data-pagefind-ignore><?= $escape($row['easing']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</section>
<?= $borrowed('motion') ?>
<section class="ds-motion" aria-labelledby="motion-tokens">
  <h2 id="motion-tokens">Duration tokens</h2>
  <p>The Hover state transition takes its duration from <code>--duration-hover</code>. Each token lists the website stylesheets and the application files that use it.</p>
  <div class="ds-table ds-motion-tokens" role="region" tabindex="0" aria-label="Duration tokens and where they are used">
    <table>
      <thead>
        <tr><th scope="col">Token</th><th scope="col">Duration</th><th scope="col">Used in</th></tr>
      </thead>
      <tbody>
        <?php foreach ($values['durations'] as $duration): ?>
          <tr data-token="<?= $escape($duration['name']) ?>">
            <th scope="row"><code><?= $escape($duration['name']) ?></code></th>
            <td class="ds-value" data-pagefind-ignore><?= $escape($duration['value']) ?></td>
            <td class="ds-motion-users" data-pagefind-ignore><?= $users($duration) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p>The <?= $escape($limit['duration']) ?> limit governs the application’s interface motion: in place of <?= $escape(lcfirst($limit['antiPattern'])) ?>, the design language’s anti-patterns ask for “<?= $escape($limit['instead']) ?>”.</p>
</section>
