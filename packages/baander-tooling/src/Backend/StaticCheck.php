<?php

declare(strict_types=1);

namespace Baander\Tooling\Backend;

use Baander\Tooling\Development\Build\Builder;
use Baander\Tooling\Development\Build\Source;
use Baander\Tooling\Development\Cli;
use Baander\Tooling\Development\Docker;
use Baander\Tooling\Development\Runner;
use Baander\Tooling\Development\WorkflowError;

final class StaticCheck
{
    public static function validateDependencies(string $root): void
    {
        try {
            $lock = self::readJson($root . '/composer.lock');
            $installed = self::readJson($root . '/vendor/composer/installed.json');
            if (isset($lock['packages'], $lock['packages-dev'], $installed['packages'])
                && is_array($lock['packages']) && is_array($lock['packages-dev']) && is_array($installed['packages'])
                && self::inventory([...$lock['packages'], ...$lock['packages-dev']]) === self::inventory($installed['packages'])) {
                return;
            }
        } catch (\JsonException|WorkflowError) {
        }
        throw new WorkflowError('Composer dependencies do not match composer.lock; run composer install with development dependencies first.');
    }

    /** @return array<string,mixed> */
    private static function readJson(string $path): array
    {
        $contents = is_file($path) ? file_get_contents($path) : false;
        if ($contents === false) {
            throw new WorkflowError('Cannot read Composer metadata.');
        }
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new WorkflowError('Invalid Composer metadata.');
        }
        return $decoded;
    }

    /** @param array<mixed> $packages
     * @return array<string,array{string,?string,?string}>
     */
    private static function inventory(array $packages): array
    {
        $inventory = [];
        foreach ($packages as $package) {
            if (!is_array($package) || !is_string($package['name'] ?? null) || !is_string($package['version'] ?? null)) {
                throw new WorkflowError('Invalid Composer package metadata.');
            }
            $references = [];
            foreach (['source', 'dist'] as $kind) {
                $section = $package[$kind] ?? [];
                if (!is_array($section) || (!is_null($section['reference'] ?? null) && !is_string($section['reference']))) {
                    throw new WorkflowError('Invalid Composer reference metadata.');
                }
                $references[] = $section['reference'] ?? null;
            }
            $inventory[$package['name']] = [$package['version'], $references[0], $references[1]];
        }
        ksort($inventory, SORT_STRING);
        return $inventory;
    }

    /** @return array{string,string,string} */
    public static function prepareCheckout(Runner $runner, string $root, string $directory): array
    {
        if (!is_file($root . '/vendor/autoload.php')) {
            throw new WorkflowError('Install Composer dependencies first.');
        }
        if (!is_file($root . '/scripts/vendor/autoload.php')) {
            throw new WorkflowError('Install workflow tooling first: composer --working-dir=scripts install --no-dev');
        }
        $dependencies = ['vendor', 'scripts/vendor'];
        if (is_file($root . '/relay/composer.json')) {
            if (!is_file($root . '/relay/vendor/autoload.php')) {
                throw new WorkflowError('Install registry tooling first: composer --working-dir=relay install --no-dev');
            }
            $dependencies[] = 'relay/vendor';
        }
        self::validateDependencies($root);
        $source = $directory . '/source';
        $revision = Source::snapshotWorktree($runner, $root, $source);
        $archive = $directory . '/checkout.tar';
        $runner->run(['tar', '--exclude=__pycache__', '--exclude=.phpunit.cache', '-cf', $archive,
            '-C', $source, '.', '-C', $root, ...$dependencies], timeout: null);
        return [$source, $revision, $archive];
    }

    /** @param non-empty-list<string> $command */
    public static function runCheck(Docker $docker, string $image, string $archive, array $command): int
    {
        $name = 'baander-check-' . bin2hex(random_bytes(6));
        try {
            $docker->run(['create', '--name', $name, '--user', '0:0', '--network', 'none',
                '--workdir', '/var/www/html', '--entrypoint', 'sh',
                '--env', 'APP_ENV=test', '--env', 'APP_DEBUG=0', '--env', 'XDEBUG_MODE=off',
                $image, '-ec', 'exec sleep infinity']);
            $docker->run(['start', $name]);
            $docker->run(['cp', $archive, $name . ':/tmp/checkout.tar'], timeout: null);
            $docker->run(['exec', $name, 'sh', '-ec', <<<'SH'
                umask 077
                rm -rf /var/www/html
                mkdir -p /var/www/html
                tar -xf /tmp/checkout.tar -C /var/www/html
                rm /tmp/checkout.tar
                mkdir -p /var/www/html/config/secrets/oauth /var/www/html/var
                openssl genrsa -out /var/www/html/config/secrets/oauth/private.key 2048 2>/dev/null
                openssl rsa -in /var/www/html/config/secrets/oauth/private.key -pubout -out /var/www/html/config/secrets/oauth/public.key 2>/dev/null
                chown -R www-data:www-data /var/www/html
                SH], timeout: null);
            return $docker->run(
                ['exec', '--user', 'www-data', $name, ...$command],
                timeout: null,
                check: false,
                interactive: true,
            )->exitCode;
        } finally {
            $docker->run(['rm', '--force', $name], check: false);
        }
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        $image = null;
        $context = 'default';
        $command = [];
        while ($arguments !== []) {
            $argument = array_shift($arguments);
            if (in_array($argument, ['--help', '-h'], true)) {
                fwrite(STDOUT, "Usage: check-backend-container.php [--image IMAGE] [--docker-context CONTEXT] -- COMMAND [ARG ...]\n");
                return 0;
            }
            if ($argument === '--') {
                $command = $arguments;
                break;
            }
            if (str_starts_with($argument, '--image=') || str_starts_with($argument, '--docker-context=')) {
                [$argument, $value] = explode('=', $argument, 2);
            } elseif (in_array($argument, ['--image', '--docker-context'], true)) {
                $value = array_shift($arguments);
                if ($value === null) {
                    throw new \InvalidArgumentException('Missing value for ' . $argument . '.');
                }
            } elseif (str_starts_with($argument, '-')) {
                throw new \InvalidArgumentException('Unknown option: ' . $argument);
            } else {
                $command = [$argument, ...$arguments];
                break;
            }
            if ($argument === '--image') {
                $image = $value;
            } else {
                $context = $value;
            }
        }
        if ($command === []) {
            throw new \InvalidArgumentException('Supply a check command after --.');
        }
        $runner = new Runner();
        $docker = new Docker($runner, Cli::dockerTarget($runner, $context));
        $directory = sys_get_temp_dir() . '/baander-check-' . bin2hex(random_bytes(12));
        if (!mkdir($directory, 0700)) {
            throw new WorkflowError('Cannot create static-check workspace.');
        }
        try {
            [$source, $revision, $archive] = self::prepareCheckout($runner, $root, $directory);
            $image ??= new Builder(null, $runner)->build($docker, $source, 'ci', $revision);
            return self::runCheck($docker, $image, $archive, $command);
        } finally {
            $runner->run(['rm', '-rf', '--', $directory], check: false);
        }
    }
}
