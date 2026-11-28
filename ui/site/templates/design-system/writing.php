<?php declare(strict_types=1);
// The Writing section shares the page's title, so its rules follow the opening under the page's own
// heading.
$rules = $section['borrowed']['writing']['html'] ?? throw new RuntimeException('The writing page borrows no Writing section.');
$following = array_diff(array_keys($section['borrowed']), ['writing']);
?>
<div class="ds-borrowed"><?= $rules ?></div>
<?php foreach ($following as $id): ?>
  <?= $borrowed($id) ?>
<?php endforeach; ?>
<p>Scripts, glyph forms and script rules are on the <a href="/typography/">typography page</a>.</p>
