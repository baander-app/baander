<?php

$title = 'typography';
$samples = [['Body', 'Display']];
?>
<h1><?= htmlspecialchars(ucfirst(trim($title))) ?></h1>
<p><?= implode(', ', array_column($samples, 0)) ?></p>
