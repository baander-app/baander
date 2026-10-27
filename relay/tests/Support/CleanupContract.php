<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class CleanupContract
{
    public static function cluster(): void
    {
        $calls = [];
        $first = new \RuntimeException('API cleanup failure');
        $second = new \RuntimeException('observer cleanup failure');
        $resource = static function (string $name, ?\Throwable $error = null) use (&$calls): object {
            return new class ($calls, $name, $error) {
                /**
                 * @param list<string> $calls
                 */

                public function __construct(public array &$calls, private string $name, private ?\Throwable $failure) {}
                public function close(): void
                {
                    $this->calls[] = $this->name;
                    if ($this->failure !== null) {
                        throw $this->failure;
                    }
                }
            };
        };
        try {
            Runtime::closeResources([
                $resource('api-1', $first),
                $resource('api-2'),
                null,
                $resource('observer', $second),
                $resource('cluster'),
            ]);
            throw new \LogicException('Cleanup failures were swallowed.');
        } catch (CleanupFailure $failure) {
            Runtime::requireThat(
                $failure->failures === [$first, $second] && $calls === ['api-1', 'api-2', 'observer', 'cluster'],
                'Cleanup did not retain failures or attempt every resource.',
            );
        }
        $directory = Runtime::temporaryDirectory('baander-registry-cleanup-');
        try {
            $cluster = new Cluster($directory, '/unused', 2);
            try {
                foreach ([...$cluster->httpPorts, ...$cluster->network->realPorts] as $port) {
                    $competitor = @stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
                    Runtime::requireThat($competitor === false, 'Voter port was not reserved.');
                }
                try {
                    new Api($cluster, __DIR__ . '/cleanup_fixture.php', 'failed', [[0, 4001]]);
                    throw new \LogicException('Readiness unexpectedly succeeded.');
                } catch (\RuntimeException $failure) {
                    Runtime::requireThat(str_contains($failure->getMessage(), 'ready'), 'Unexpected API readiness failure.');
                }
                $pid = (int) file_get_contents($directory . '/cleanup-child.pid');
                Runtime::requireThat(!\Swoole\Process::kill($pid, 0), 'Failed constructor leaked a subprocess.');
                $port = json_decode((string) file_get_contents($directory . '/failed.json'), true, flags: JSON_THROW_ON_ERROR)['api']['port'];
                $replacement = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
                Runtime::requireThat($replacement !== false, 'Failed constructor retained its port.');
                fclose($replacement);
            } finally {
                $cluster->close();
            }
            foreach ([...$cluster->httpPorts, ...$cluster->network->realPorts, ...$cluster->network->ports] as $port) {
                $replacement = stream_socket_server('tcp://127.0.0.1:' . $port, $errno, $error);
                Runtime::requireThat($replacement !== false, 'Cluster teardown retained a port.');
                fclose($replacement);
            }
            $process = new Process([PHP_BINARY, __DIR__ . '/cleanup_fixture.php', 'normal', $directory . '/child.pid']);
            $until = Runtime::now() + 3;
            while (!is_file($directory . '/child.pid') && Runtime::now() < $until) {
                usleep(1000);
            }
            Runtime::requireThat(is_file($directory . '/child.pid'), 'Cleanup child did not start.');
            $process->close(false);
            Runtime::requireThat(
                $process->poll() !== null && !\Swoole\Process::kill($process->pid(), 0),
                'Child shutdown did not reap direct child.',
            );
            $output = Runtime::checked([
                PHP_BINARY,
                '-r',
                'echo json_encode(array_slice($argv,1));',
                'literal space',
                '$(touch /unused)',
                '*',
                'quote"',
            ]);
            Runtime::requireThat(
                json_decode($output, true, flags: JSON_THROW_ON_ERROR) === ['literal space', '$(touch /unused)', '*', 'quote"'],
                'Process argv was expanded.',
            );
        } finally {
            Runtime::removeTree($directory);
        }
        echo "PASS: cluster port reservations, failed readiness, exhaustive cleanup, reaping and literal argv\n";
    }
    public static function storage(): void
    {
        $directory = Runtime::temporaryDirectory('baander-registry-storage-cleanup-');
        try {
            file_put_contents($directory . '/sentinel', 'keep');
            symlink($directory, $directory . '/link');
            foreach ([$directory . '/missing', $directory, $directory . '/link', $directory . '/..'] as $candidate) {
                try {
                    new BoundedStorage($candidate);
                    throw new \LogicException('Unsafe storage root accepted.');
                } catch (\RuntimeException) {
                }
            }
            Runtime::requireThat(
                file_get_contents($directory . '/sentinel') === 'keep' && iterator_count(new \FilesystemIterator($directory)) === 2,
                'Rejected storage root changed user data.',
            );
            $submitted = StorageContract::pressureRegistration(3);
            Runtime::requireThat(
                strlen($submitted['url']) === 2048
                    && strlen($submitted['name']) === 128
                    && strlen($submitted['version']) === 64
                    && strlen($submitted['apiKey']) === 64,
                'Pressure fixture does not occupy near-limit bytes.',
            );
            $ledger = [];
            $body = ['data' => [
                'registered' => true,
                'publicId' => $submitted['publicId'],
                'revision' => 1,
                'updatedAtMs' => 123,
                'lastHeartbeatMs' => 123,
            ]];
            StorageContract::acknowledge($ledger, $submitted, $body);
            $row = $ledger[$submitted['publicId']];
            Runtime::requireThat($row['credential_digest'] === hash('sha256', $submitted['apiKey'])
                && $row['created_ms'] === 123
                && $row['last_seen_ms'] === 123
                && !str_contains(json_encode($ledger, JSON_THROW_ON_ERROR), $submitted['apiKey']));
            foreach ([
                array_replace_recursive($body, ['data' => ['revision' => 2]]),
                array_replace_recursive($body, ['data' => ['updatedAtMs' => '123']]),
                $body + ['extra' => true],
            ] as $invalid) {
                try {
                    StorageContract::acknowledge($ledger, $submitted, $invalid);
                    throw new \LogicException('Malformed acknowledgement was accepted.');
                } catch (\RuntimeException) {
                }
            }
            try {
                StorageContract::requireStorageError('election timeout; connection refused');
                throw new \LogicException('Missing storage diagnostic accepted.');
            } catch (\RuntimeException) {
            }
            Runtime::requireThat(count(StorageContract::requireStorageError('ENOSPC; database or disk is full')) === 2);
            stream_wrapper_register('baanderstorage', StorageWriteFixture::class);
            try {
                $storage = (new \ReflectionClass(BoundedStorage::class))->newInstanceWithoutConstructor();
                $storage->root = $directory;
                $storage->directory = $directory . '/owned';
                $storage->filler = 'baanderstorage://filler';
                StorageWriteFixture::$warning = 'fwrite(): Write failed with errno=28 No space left on device';
                $full = $storage->fill();
                Runtime::requireThat(
                    $full['filler_errno'] === 28 && StorageWriteFixture::$closed,
                    'ENOSPC was not recorded or its descriptor remained open.',
                );
                StorageWriteFixture::$warning = 'fwrite(): Write failed with errno=5 Input/output error';
                try {
                    $storage->fill();
                    throw new \LogicException('Unexpected write error qualified as full disk.');
                } catch (\RuntimeException $failure) {
                    Runtime::requireThat(
                        str_contains($failure->getMessage(), 'without ENOSPC') && StorageWriteFixture::$closed,
                        'Unexpected failure was hidden or descriptor leaked.',
                    );
                }
            } finally {
                stream_wrapper_unregister('baanderstorage');
            }
            $original = new \RuntimeException('Original assertion failure');
            $closed = false;
            $resource = new class ($closed) {
                public function __construct(public bool &$closed) {}
                public function close(): void
                {
                    $this->closed = true;
                    throw new \RuntimeException('Injected cleanup failure');
                }
            };
            try {
                Runtime::cleanupScope(static fn() => throw $original, [$resource]);
                throw new \LogicException('Cleanup scope swallowed failure.');
            } catch (CleanupFailure $failure) {
                Runtime::requireThat($closed && $failure->failures[0] === $original && str_contains($failure->failures[1]->getMessage(), 'Injected cleanup'));
            }
            mkdir($directory . '/owned');
            file_put_contents($directory . '/owned/partial', 'partial setup');
            Runtime::removeTree($directory . '/owned');
            Runtime::requireThat(file_get_contents($directory . '/sentinel') === 'keep', 'Owned cleanup removed sibling.');
            Runtime::requireThat(LoadSmoke::percentile([1.0, 4.0, 2.0, 3.0], 95) === 4.0 && LoadSmoke::percentile([1.0, 4.0, 2.0, 3.0], 50) === 2.0);
        } finally {
            Runtime::removeTree($directory);
        }
        echo "PASS: unsafe storage roots, exact credential-free ledger, diagnostic controls and failure-preserving cleanup\n";
    }
}
