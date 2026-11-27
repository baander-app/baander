<?php declare(strict_types=1);
// Stub body: it shows the borrowed sections in the data file's order until this page's own body,
// with the values in $section['values'], is written.
foreach (array_keys($section['borrowed']) as $id) {
    echo $borrowed($id);
}
