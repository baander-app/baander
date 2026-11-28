<?php declare(strict_types=1);
// Each mark at the sizes its file's comment names, largest first, with where the file is used.
$marks = $section['values']['marks'];
$uses = [
    'logo.svg' => 'The application shows it in its sidebar, the admin sidebar and the sign-in page. This website shows it in the header, the footer and the social card that link previews display.',
    'icon.svg' => 'The desktop application’s icon, <code>ui/electron/build/app-icons/icon.png</code>, is rendered from it, and electron-builder derives each platform’s icon format from that image.',
    'favicon.svg' => 'This website uses it as the site icon that browsers show in tabs and bookmarks.',
];
$adapting = array_values(array_filter($marks, static fn(array $mark): bool => $mark['darkVersion']));
$fixed = array_values(array_filter($marks, static fn(array $mark): bool => !$mark['darkVersion']));
$files = static fn(array $list): string => implode(' and ', array_map(static fn(array $mark): string => '<code>' . $escape($mark['file']) . '</code>', $list));
$verb = static fn(array $list, string $one, string $many): string => count($list) === 1 ? $one : $many;
$id = static fn(array $mark): string => 'mark-' . pathinfo($mark['file'], PATHINFO_FILENAME);
?>
<section class="ds-logo" aria-labelledby="marks">
  <h2 id="marks">Marks and sizes</h2>
  <p>Each file lives in <code>ui/web/public/</code> and opens with a comment naming the sizes it is drawn for. Below, each mark appears at the sizes at either end of its range.</p>
  <?php foreach ($marks as $mark): ?>
    <section class="ds-logo-mark" aria-labelledby="<?= $escape($id($mark)) ?>">
      <h3 id="<?= $escape($id($mark)) ?>"><code><?= $escape($mark['file']) ?></code></h3>
      <p><?= $uses[$mark['file']] ?? throw new RuntimeException('The logo page does not say where ' . $mark['file'] . ' is used.') ?></p>
      <figure class="ds-logo-figure" data-pagefind-ignore>
        <div class="ds-logo-sizes">
          <?php foreach ($mark['sizes'] as $size): ?>
            <div class="ds-logo-size">
              <img src="<?= $escape($mark['url']) ?>" width="<?= $size ?>" height="<?= $size ?>" alt="The cassette mark from <?= $escape($mark['file']) ?> at <?= $size ?> pixels">
              <span class="ds-logo-label"><?= $size ?> px</span>
            </div>
          <?php endforeach; ?>
        </div>
        <figcaption><?= $escape($mark['comment']) ?></figcaption>
      </figure>
    </section>
  <?php endforeach; ?>
</section>
<section aria-labelledby="light-and-dark">
  <h2 id="light-and-dark">Light and dark</h2>
  <p><?= $files($adapting) ?> <?= $verb($adapting, 'draws', 'draw') ?> a second version for dark color schemes, chosen by a <code>prefers-color-scheme</code> media query inside the file<?php if ($fixed !== []): ?>; <?= $files($fixed) ?> <?= $verb($fixed, 'has', 'have') ?> one version for both<?php endif; ?>.</p>
  <p>Shown as an image, a mark takes its color scheme from the page around it rather than from the operating system, so on this website it changes with the theme toggle in the header.</p>
</section>
