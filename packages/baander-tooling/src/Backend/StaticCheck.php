<?php

declare(strict_types=1);

namespace Baander\Tooling\Backend;

use Baander\Tooling\Build\ApplicationImage;
use Baander\Tooling\Configuration\ApplicationLayout;
use Baander\Tooling\Configuration\ToolingConfiguration;
use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\TargetResolver;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Resources\ResourceKind;
use Baander\Tooling\Resources\ResourceLedger;
use Baander\Tooling\Development\Build\Source;
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
    public static function runCheck(Client $docker, string $image, string $archive, array $command, ApplicationLayout $layout, ResourceLedger $ledger, float $cleanupSeconds): int
    {
        $inspection = json_decode($docker->run(['image', 'inspect', '--', $image])->stdout, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($inspection) || !array_is_list($inspection) || count($inspection) !== 1 || !is_array($inspection[0])
            || ApplicationLayout::fromImage($inspection[0])->toArray() !== $layout->toArray()) {
            throw new \RuntimeException('Static-check image layout differs from the selected source layout.');
        }
        $name = 'baander-check-' . bin2hex(random_bytes(6));
        return $ledger->scoped(static function () use ($docker, $image, $archive, $command, $layout, $ledger, $name): int {
            $ledger->create(ResourceKind::Container, $name, ['--user', '0:0', '--network', 'none',
                '--workdir', $layout->root, '--entrypoint', 'sh',
                '--env', 'APP_ENV=test', '--env', 'APP_DEBUG=0', '--env', 'XDEBUG_MODE=off',
                $image, '-ec', 'exec sleep infinity']);
            $docker->run(['start', $name]);
            $docker->run(['cp', $archive, $name . ':/tmp/checkout.tar'], timeout: null);
            $provision = <<<'SH'
                umask 077
                rm -rf APPLICATION_ROOT
                mkdir -p APPLICATION_ROOT
                tar -xf /tmp/checkout.tar -C APPLICATION_ROOT
                rm /tmp/checkout.tar
                mkdir -p APPLICATION_ROOT/config/secrets/oauth APPLICATION_ROOT/var
                openssl genrsa -out APPLICATION_ROOT/config/secrets/oauth/private.key 2048 2>/dev/null
                openssl rsa -in APPLICATION_ROOT/config/secrets/oauth/private.key -pubout -out APPLICATION_ROOT/config/secrets/oauth/public.key 2>/dev/null
                chown -R www-data:www-data APPLICATION_ROOT
                SH;
            $docker->run(['exec', $name, 'sh', '-ec', str_replace('APPLICATION_ROOT', escapeshellarg($layout->root), $provision)]);
            return $docker->run(
                ['exec', '--user', 'www-data', $name, ...$command],
                timeout: null,
                check: false,
                interactive: true,
            )->exitCode;
        }, $cleanupSeconds);
    }

    /** @param list<string> $arguments */
    public static function main(array $arguments, string $root): int
    {
        $image = null;
        $context = null;
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
        $environment = getenv();
        $configuration = ToolingConfiguration::resolve(flags: $context === null ? [] : ['docker' => ['context' => $context]], environment: $environment);
        $runner = new Runner();
        $directory = sys_get_temp_dir() . '/baander-check-' . bin2hex(random_bytes(12));
        $executor = null;
        $ownsDirectory = false;
        $primary = null;
        $status = 0;
        try {
            if (!mkdir($directory, 0700)) {
                throw new WorkflowError('Cannot create static-check workspace.');
            }
            $ownsDirectory = true;
            $executor = new EngineExecutor(ExecutionContext::bounded($configuration->budgets->seconds('backend'), $environment));
            $docker = new Client($executor, new TargetResolver($executor, $environment)->resolve($configuration->docker, $directory . '/transport'), $environment);
            $ledger = new ResourceLedger($directory . '/resources.json', $docker, $environment);
            [$source, $revision, $archive] = self::prepareCheckout($runner, $root, $directory);
            $image ??= new ApplicationImage($docker, $configuration->images)->build($source, 'ci', $revision, 'baander-check:' . $revision . '-' . basename($directory));
            $status = self::runCheck($docker, $image, $archive, $command, ApplicationLayout::load($source . '/packages/baander-tooling/resources/application-layout.json'), $ledger, $configuration->budgets->seconds('cleanup'));
            return $status;
        } catch (\Throwable $error) {
            $primary = $error;
            throw $error;
        } finally {
            $errors = [];
            try {
                $executor?->close();
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
            try {
                if ($ownsDirectory && !file_exists($directory . '/resources.json') && !is_link($directory . '/resources.json')) {
                    $runner->run(['rm', '-rf', '--', $directory], timeout: $configuration->budgets->seconds('cleanup'));
                }
            } catch (\Throwable $error) {
                $errors[] = $error->getMessage();
            }
            if ($errors !== []) {
                throw new WorkflowError(($primary !== null ? $primary->getMessage() . '; ' : '') . 'Static-check staging cleanup failed: ' . implode('; ', $errors), $primary !== null ? ($primary->getCode() ?: 1) : ($status ?: 1), $primary);
            }
        }
    }
}
