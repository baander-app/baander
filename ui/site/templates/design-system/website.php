<?php declare(strict_types=1);
// The website's departures from the application, read from the site's stylesheets and theme, then the
// design language's rules for the website.
$values = $section['values'];
$cell = static fn(string $value): string => preg_match('/^#[0-9a-f]{6}$/i', $value) === 1 ? '<span class="ds-value">' . $swatch($value) . '</span>' : $escape($value);
?>
<section class="ds-website" aria-labelledby="departures">
  <h2 id="departures">Departures</h2>
  <p>The theme toggle in the header switches between the <?= $escape($values['moodLabels']['dark']) ?> mood and the <?= $escape($values['moodLabels']['light']) ?> mood. Until a visitor chooses, the site follows the operating system’s light or dark setting. The application lets its user select any of the moods.</p>
  <div class="ds-table ds-website-table" role="region" tabindex="0" aria-label="Where the website departs from the application">
    <table>
      <thead>
        <tr>
          <th scope="col">Subject</th>
          <th scope="col">Website</th>
          <th scope="col">Application</th>
          <th scope="col">Read from</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($values['departures'] as $departure): ?>
          <tr>
            <th scope="row"><?= $escape($departure['subject']) ?></th>
            <td data-pagefind-ignore><?= $cell($departure['website']) ?></td>
            <td data-pagefind-ignore><?= $cell($departure['application']) ?></td>
            <td data-pagefind-ignore><code><?= $escape($departure['source']) ?></code></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <p>Near-white text on the dark mood’s black background glares on long reading pages, so the website’s dark theme sets its text a little softer. Surfaces stay flat and pictures keep the size they were captured at, as the rules below state.</p>
</section>
<?= $borrowed('static-public-website') ?>
