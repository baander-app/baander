<?php declare(strict_types=1);
/**
 * A borrowed section whose tables each scroll inside a focusable region named after the heading above
 * the table, plus " table", so its name differs from the section the heading labels. A cell that
 * holds only a component name is a value, so search leaves it out.
 */
$withTableRegions = static function (string $id) use ($borrowed): string {
    $document = \Dom\HTMLDocument::createFromString('<!doctype html><meta charset="utf-8"><body>' . $borrowed($id), LIBXML_NOERROR);
    foreach ($document->querySelectorAll('table') as $table) {
        $heading = $table->previousElementSibling;
        while ($heading !== null && !in_array($heading->localName, ['h2', 'h3'], true)) {
            $heading = $heading->previousElementSibling;
        }
        $region = $document->createElement('div');
        $region->className = 'ds-table ds-iconography-table';
        $region->setAttribute('role', 'region');
        $region->setAttribute('tabindex', '0');
        $region->setAttribute('aria-label', trim($heading?->textContent ?? $id) . ' table');
        $table->before($region);
        $region->append($table);
        foreach ($table->querySelectorAll('thead th') as $cell) {
            $cell->setAttribute('scope', 'col');
        }
        foreach ($table->querySelectorAll('td') as $cell) {
            if ($cell->childElementCount === 1 && $cell->firstElementChild?->localName === 'code' && trim($cell->textContent) === trim($cell->firstElementChild->textContent)) {
                $cell->setAttribute('data-pagefind-ignore', '');
            }
        }
    }

    return $document->body?->innerHTML ?? '';
};
?>
<div class="ds-iconography">
  <?php foreach (array_keys($section['borrowed']) as $id): ?>
    <?= $withTableRegions($id) ?>
  <?php endforeach; ?>
</div>
