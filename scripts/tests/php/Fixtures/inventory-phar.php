<?php

declare(strict_types=1);

$argv = $_SERVER['argv'];
$archive = new Phar($argv[1]);
$archive->startBuffering();
if ($argv[6] !== 'metadata') {
    $archive->setAlias($argv[5]);
}
$archive[$argv[2]] = $argv[3];
$archive['vendor/dependency/LICENSE'] = 'dependency terms';
if ($argv[6] === 'metadata') {
    $archive['LICENSE'] = 'tool terms';
    $archive['vendor/composer/installed.php'] = '<?php file_put_contents(' . var_export($argv[4], true) . ', "metadata executed");';
}
$archive->setStub('<?php file_put_contents(' . var_export($argv[4], true) . ', "entrypoint executed"); __HALT_COMPILER();');
$archive->stopBuffering();
