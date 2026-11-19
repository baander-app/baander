<?php

declare(strict_types=1);

$compiler = $argv[1] ?? dirname(__DIR__) . '/vendor/swoole/typephp';
spl_autoload_register(static function (string $class) use ($compiler): void {
    if (str_starts_with($class, 'TypePhp\\')) {
        require $compiler . '/src/' . str_replace('\\', '/', substr($class, strlen('TypePhp\\'))) . '.php';
    }
});

use TypePhp\Build\OfficialPhpSource;
use TypePhp\Build\PeclExtensionSource;
use TypePhp\Build\RuntimeLock;
use TypePhp\Build\SapiExtensionConfiguration;

function check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function rejects(callable $action, string $message): void
{
    try {
        $action();
    } catch (RuntimeException $exception) {
        check(str_contains($exception->getMessage(), $message), $exception->getMessage());
        return;
    }
    throw new RuntimeException('Expected rejection: ' . $message);
}

$temporary = sys_get_temp_dir() . '/typephp-runtime-lock-' . bin2hex(random_bytes(6));
mkdir($temporary, 0700, true);
$lockPath = $temporary . '/runtime-lock.json';
$originalEnvironment = getenv('TYPEPHP_RUNTIME_LOCK');
try {
    $data = json_decode(file_get_contents(dirname(__DIR__) . '/runtime-lock.json'), true, flags: JSON_THROW_ON_ERROR);
    file_put_contents($lockPath, json_encode($data, JSON_THROW_ON_ERROR));
    putenv('TYPEPHP_RUNTIME_LOCK=' . $lockPath);
    $lock = RuntimeLock::load();
    check($lock->php('8.5')['version'] === '8.5.12', 'PHP branch must resolve to the exact locked patch');
    check($lock->php('8.5.0')['version'] === '8.5.12', 'Normalized PHP branch must resolve to the locked patch');
    rejects(fn () => $lock->php('8.5.11'), 'does not match locked PHP');
    rejects(fn () => $lock->extension('unlocked'), 'absent from the runtime lock');
    $archive = $temporary . '/archive';
    file_put_contents($archive, 'verified source');
    $lock->verify($archive, hash('sha256', 'verified source'));
    rejects(fn () => $lock->verify($archive, str_repeat('0', 64)), 'SHA-256 verification failed');
    $data['php']['sha256'] = 'invalid';
    file_put_contents($lockPath, json_encode($data, JSON_THROW_ON_ERROR));
    rejects(fn () => RuntimeLock::load()->php('8.5'), 'Invalid locked source release');
    $data['php']['sha256'] = hash('sha256', 'cached PHP archive');
    $data['extensions']['igbinary']['sha256'] = hash('sha256', 'cached PECL archive');
    file_put_contents($lockPath, json_encode($data, JSON_THROW_ON_ERROR));
    $lock = RuntimeLock::load();
    $cache = $temporary . '/runtime-lock-' . $lock->hash;
    mkdir($cache . '/archives', 0700, true);
    file_put_contents($cache . '/archives/' . $data['php']['filename'], 'cached PHP archive');
    file_put_contents($cache . '/archives/' . $data['extensions']['igbinary']['filename'], 'cached PECL archive');
    $source = $cache . '/src/php-8.5.12';
    foreach (['configure', 'main/php.h', 'main/php_version.h', 'Zend/zend.h', 'ext/standard/fsock.h'] as $file) {
        $path = $source . '/' . $file;
        if (!is_dir(dirname($path))) {
            mkdir(dirname($path), 0700, true);
        }
        file_put_contents($path, 'source fixture');
    }
    $peclSource = $cache . '/pecl/igbinary-3.2.17RC1';
    mkdir($peclSource, 0700, true);
    file_put_contents($peclSource . '/config.m4', 'source fixture');
    $output = static function (string $message): void {
        throw new RuntimeException('Cached source unexpectedly attempted work: ' . $message);
    };
    $php = new OfficialPhpSource($temporary, $output);
    check($php->prepare('8.5') === $source, 'PHP source cache must use the lock identity');
    $pecl = new PeclExtensionSource($temporary, $output);
    check($pecl->prepare('igbinary')['directory'] === $peclSource, 'Locked prerelease must resolve without querying PECL stable.txt');
    file_put_contents($cache . '/archives/' . $data['php']['filename'], 'corrupted archive');
    rejects(fn () => $php->prepare('8.5'), 'SHA-256 verification failed');
    file_put_contents($cache . '/archives/' . $data['extensions']['igbinary']['filename'], 'corrupted archive');
    rejects(fn () => $pecl->prepare('igbinary'), 'SHA-256 verification failed');
    foreach (['gd', 'redis', 'swoole'] as $extension) {
        mkdir($source . '/ext/' . $extension, 0700, true);
        file_put_contents($source . '/ext/' . $extension . '/config.m4', 'PHP_ARG_ENABLE([' . $extension . '], [enable])');
    }
    $options = SapiExtensionConfiguration::configureOptions($source, ['gd', 'redis', 'swoole']);
    foreach (array_merge(...array_values($data['configure'])) as $option) {
        check(in_array($option, $options, true), 'Missing configure feature: ' . $option);
    }
    $previousHash = $lock->hash;
    $data['configure']['gd'][] = '--enable-test-fixture';
    file_put_contents($lockPath, json_encode($data, JSON_THROW_ON_ERROR));
    check(RuntimeLock::load()->hash !== $previousHash, 'Configure changes must invalidate the lock identity');
    putenv('TYPEPHP_RUNTIME_LOCK');
    rejects(RuntimeLock::load(...), 'TYPEPHP_RUNTIME_LOCK must name');
    echo "Runtime lock checks passed\n";
} finally {
    putenv($originalEnvironment === false ? 'TYPEPHP_RUNTIME_LOCK' : 'TYPEPHP_RUNTIME_LOCK=' . $originalEnvironment);
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($temporary);
}
