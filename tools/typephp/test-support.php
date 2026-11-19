<?php

declare(strict_types=1);

(static function (): void {
    // Initialize the embedded loader without marking vendor/autoload.php as included:
    // Symfony Runtime relies on that file's first require returning the loader.
    require_once '/var/www/html/vendor/composer/autoload_real.php';

    $initializers = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'ComposerAutoloaderInit'),
    ));

    if (1 !== count($initializers)) {
        throw new RuntimeException('Expected one embedded Composer autoloader initializer.');
    }

    $loader = $initializers[0]::getLoader();
    $metadata = require '/usr/local/lib/typephp/test-autoload.php';

    $loader->addClassMap(array_diff_key($metadata['classmap'], $loader->getClassMap()));

    foreach (array_diff_key($metadata['psr4'], $loader->getPrefixesPsr4()) as $prefix => $paths) {
        $loader->addPsr4($prefix, $paths);
    }

    foreach (array_diff_key($metadata['psr0'], $loader->getPrefixes()) as $prefix => $paths) {
        $loader->add($prefix, $paths);
    }

    $loader->setClassMapAuthoritative(false);

    $installedDatasets = Composer\InstalledVersions::getAllRawData();
    $installed = array_shift($installedDatasets);

    foreach ($installedDatasets as $dataset) {
        $installed['versions'] += $dataset['versions'];
    }

    foreach ($metadata['installed']['versions'] as $package => $version) {
        if ($version['dev_requirement'] ?? false) {
            $installed['versions'] += [$package => $version];
        }
    }

    Composer\InstalledVersions::reload($installed);

    foreach ($metadata['files'] as $identifier => $path) {
        if (empty($GLOBALS['__composer_autoload_files'][$identifier])) {
            $GLOBALS['__composer_autoload_files'][$identifier] = true;
            require $path;
        }
    }
})();
