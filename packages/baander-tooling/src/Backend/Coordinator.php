<?php

declare(strict_types=1);

namespace Baander\Tooling\Backend;

use InvalidArgumentException;
use RuntimeException;
use Throwable;

final class Coordinator
{
    public static function positiveInteger(string $value, string $name, ?int $maximum = null): int
    {
        if (preg_match('/^[1-9][0-9]*$/D', $value) !== 1 || filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException($name . ' must be a positive integer');
        }
        $integer = (int) $value;
        if ($maximum !== null && $integer > $maximum) {
            throw new InvalidArgumentException($name . ' must be between 1 and ' . $maximum);
        }
        return $integer;
    }

    /** @param list<string> $files
     * @param array<string,string> $native
     * @return array<string,array{files:list<string>,processes:int,batch:int,services:bool,privileged:bool}>
     */
    public static function partition(array $files, array $native, int $workers): array
    {
        if ($files === [] || count(array_unique($files)) !== count($files)) {
            throw new InvalidArgumentException('Discovery must contain unique, nonempty test files');
        }
        $ordinary = $serial = $services = [];
        sort($files, SORT_STRING);
        foreach ($files as $file) {
            $relative = str_starts_with($file, Runtime::CONTAINER_ROOT . '/') ? substr($file, strlen(Runtime::CONTAINER_ROOT) + 1) : $file;
            if (isset($native[$relative])) {
                $serial[] = $file;
            } elseif (str_starts_with($relative, 'tests/Functional/') || str_starts_with($relative, 'tests/Integration/')) {
                $services[] = $file;
            } else {
                $ordinary[] = $file;
            }
        }
        $lanes = [];
        if ($ordinary !== []) {
            $lanes['ordinary'] = [
                'files' => $ordinary,
                'processes' => min($workers, count($ordinary)),
                'batch' => 25,
                'services' => false,
                'privileged' => false,
            ];
        }
        if ($serial !== []) {
            $lanes['native'] = [
                'files' => $serial,
                'processes' => 1,
                'batch' => 1,
                'services' => false,
                'privileged' => true,
            ];
        }
        $count = min($workers, count($services));
        for ($index = 0; $index < $count; ++$index) {
            $selected = [];
            for ($position = $index; $position < count($services); $position += $count) {
                $selected[] = $services[$position];
            }
            $lanes['stack-' . ($index + 1)] = [
                'files' => $selected,
                'processes' => 1,
                'batch' => 1,
                'services' => true,
                'privileged' => true,
            ];
        }
        return $lanes;
    }

    /** @param list<string> $arguments
     * @param array<string,string>|null $environment
     */
    public static function main(array $arguments, string $root, ?array $environment = null): int
    {
        $environment ??= getenv();
        $workers = self::positiveInteger($environment['BAANDER_TEST_PROCESSES'] ?? '2', 'BAANDER_TEST_PROCESSES', 8);
        $timeout = self::positiveInteger($environment['BAANDER_TEST_TIMEOUT'] ?? '3600', 'BAANDER_TEST_TIMEOUT');
        $disable = $environment['BAANDER_TEST_DISABLE_SWOOLE'] ?? '0';
        if (!in_array($disable, ['0', '1'], true)) {
            throw new InvalidArgumentException('BAANDER_TEST_DISABLE_SWOOLE must be 0 or 1');
        }
        $artifacts = $environment['BAANDER_TEST_ARTIFACTS'] ?? $root . '/var/test-runs';
        if (!str_starts_with($artifacts, '/')) {
            $artifacts = getcwd() . '/' . $artifacts;
        }
        $artifacts .= '/' . date('Ymd-His') . '-' . bin2hex(random_bytes(4));
        if (!mkdir($artifacts, 0700, true)) {
            throw new RuntimeException('Cannot create backend artifacts directory');
        }
        printf("Backend artifacts: %s\n", $artifacts);
        $started = Runtime::now();
        $runtime = new Runtime($artifacts, $timeout, $root, $environment);
        $pending = [];
        $status = 1;
        try {
            $runtime->connect();
            $runtime->prepareArchive();
            $discovery = $runtime->createApp('discovery');
            $runtime->helper($discovery, ['discover', Runtime::RUN_DIRECTORY, '--no-progress', '--colors=never',
                '--display-all-issues', '--fail-on-phpunit-notice', '--fail-on-skipped', ...$arguments], $artifacts . '/discovery.log');
            $run = $artifacts . '/run';
            mkdir($run, 0700);
            $runtime->docker(['cp', $discovery . ':' . Runtime::RUN_DIRECTORY . '/.', $run]);
            $selected = json_decode(file_get_contents($run . '/discovery.json'), true, flags: JSON_THROW_ON_ERROR);
            $native = json_decode(file_get_contents($root . '/scripts/backend-native-tests.json'), true, flags: JSON_THROW_ON_ERROR);
            $lanes = self::partition(array_keys($selected['files']), $native, $workers);
            foreach ($lanes as $lane => $spec) {
                file_put_contents($run . '/' . $lane . '.json', json_encode($spec, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
            }
            $testCount = $selected['files']
                |> (static fn(array $files): array => array_map(count(...), $files))
                |> array_sum(...);
            printf(
                "Selected %d tests in %d files; %d lanes, %d workers\n",
                $testCount,
                count($selected['files']),
                count($lanes),
                $workers,
            );
            $statuses = [];
            foreach (['ordinary', 'native'] as $lane) {
                if (isset($lanes[$lane])) {
                    $statuses[] = $runtime->finishLane($runtime->startLane($lane, $lanes[$lane], $run, $selected['coverage']));
                }
            }
            foreach ($lanes as $lane => $spec) {
                if ($spec['services']) {
                    $pending[] = $runtime->startLane($lane, $spec, $run, $selected['coverage']);
                }
            }
            while ($pending !== []) {
                $lane = array_shift($pending);
                $statuses[] = $runtime->finishLane($lane);
            }
            $runtime->docker(['cp', $run . '/.', $discovery . ':' . Runtime::RUN_DIRECTORY]);
            $result = $runtime->helper($discovery, ['finalize', Runtime::RUN_DIRECTORY], $artifacts . '/finalize.log', false);
            $runtime->docker(['cp', $discovery . ':' . Runtime::RUN_DIRECTORY . '/.', $run]);
            if ($result->exitCode !== 0 || array_any($statuses, static fn(int $code): bool => $code !== 0)) {
                throw new RuntimeException('Backend validation failed; inspect lane and finalize logs');
            }
            foreach ($selected['reports'] ?? [] as $option => $target) {
                $destination = str_starts_with($target, '/') ? $target : $root . '/' . $target;
                if (!is_dir(dirname($destination))) {
                    mkdir(dirname($destination), 0700, true);
                }
                $staging = $destination . '.' . $runtime->prefix;
                try {
                    $runtime->docker(['cp', $discovery . ':' . (str_starts_with($target, '/') ? $target : Runtime::CONTAINER_ROOT . '/' . $target), $staging]);
                    if (!is_dir($artifacts . '/reports')) {
                        mkdir($artifacts . '/reports', 0700);
                    }
                    if (!copy($staging, $artifacts . '/reports/' . ($option === '--coverage-text' ? 'report.txt' : 'clover.xml')) || !rename($staging, $destination)) {
                        throw new RuntimeException('Cannot publish backend report: ' . $destination);
                    }
                } finally {
                    if (is_file($staging)) {
                        unlink($staging);
                    }
                }
            }
            $status = 0;
        } catch (Throwable $error) {
            fwrite(STDERR, $error->getMessage() . "\n");
        } finally {
            foreach ($pending as $lane) {
                $lane['process']->cancel();
                try {
                    $runtime->finishLane($lane);
                } catch (Throwable $error) {
                    fwrite(STDERR, $error->getMessage() . "\n");
                }
            }
            try {
                if (!$runtime->cleanup()) {
                    $status = 1;
                }
            } catch (Throwable $error) {
                $status = 1;
                fwrite(STDERR, 'Cleanup failed: ' . $error->getMessage() . "\n");
            }
            $runtime->close();
            $elapsed = Runtime::now() - $started;
            file_put_contents($artifacts . '/timing.json', json_encode(['status' => $status, 'elapsed' => $elapsed, 'workers' => $workers], JSON_THROW_ON_ERROR) . "\n");
            printf("Backend exit %d; elapsed %.1fs; artifacts %s\n", $status, $elapsed, $artifacts);
        }
        return $status;
    }
}
