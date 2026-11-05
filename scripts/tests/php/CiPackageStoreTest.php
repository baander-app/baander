<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Process\{CompletionPolicy, EngineExecutor, ExecutionContext, ProcessResult, ProcessSpec, RunningProcess};
use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class CiPackageStoreTest extends TestCase
{
    private const string TOKEN = 'package-store-secret-7f3a9c';
    private const string ARCHIVE = 'buildkit-cache.tar.zst';

    private string $work;
    private string $address;
    private EngineExecutor $executor;
    private ?RunningProcess $server = null;

    protected function setUp(): void
    {
        $this->work = sys_get_temp_dir() . '/baander-ci-package-store-' . bin2hex(random_bytes(6));
        mkdir($this->work . '/server/store', 0700, true);
        file_put_contents($this->work . '/server/router.php', self::ROUTER);
        $this->executor = new EngineExecutor(new ExecutionContext(getenv()));
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $error);
        self::assertIsResource($reservation, $error);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        fclose($reservation);
        $this->address = $address;
        $this->server = $this->executor->start(new ProcessSpec(
            argv: [PHP_BINARY, '-S', $address, 'router.php'],
            cwd: $this->work . '/server',
            processGroup: true,
        ));
        $deadline = microtime(true) + 5;
        do {
            $socket = @stream_socket_client('tcp://' . $address, $errorCode, $error, 0.05);
            if ($socket === false) {
                usleep(10000);
            }
        } while ($socket === false && microtime(true) < $deadline);
        self::assertIsResource($socket, 'The package API stub did not start.');
        fclose($socket);
    }

    protected function tearDown(): void
    {
        if ($this->server !== null) {
            $this->executor->stop($this->server);
        }
        $this->executor->close();
        $this->remove($this->work);
    }

    public function testSavedDirectoryRestoresTheSameFilesAtTheSameRelativePaths(): void
    {
        $source = $this->work . '/source';
        mkdir($source . '/blobs/sha256', 0755, true);
        file_put_contents($source . '/index.json', '{"manifests":[]}');
        file_put_contents($source . '/blobs/sha256/4a2b', random_bytes(4096));
        file_put_contents($source . '/ingest.lock', '');

        $save = $this->invoke('save', 'buildkit-cache', self::ARCHIVE, $source);
        self::assertSame(0, $save->exitCode, $save->stderr);
        self::assertSame(
            [
                ['DELETE', '/api/v1/packages/baander/generic/buildkit-cache/latest/' . self::ARCHIVE],
                ['PUT', '/api/v1/packages/baander/generic/buildkit-cache/latest/' . self::ARCHIVE],
            ],
            array_map(static fn(array $request): array => [$request['method'], $request['path']], $this->requests()),
        );
        self::assertSame('application/zstd', $this->requests()[1]['type']);

        $target = $this->work . '/restored/buildkit-cache';
        $restore = $this->invoke('restore', 'buildkit-cache', self::ARCHIVE, $target);
        self::assertSame(0, $restore->exitCode, $restore->stderr);
        self::assertSame($this->snapshot($source), $this->snapshot($target));
        self::assertSame(['buildkit-cache'], $this->entries($this->work . '/restored'));
    }

    public function testRestoreOfAMissingPackageLeavesAnEmptyTargetAndSucceeds(): void
    {
        $target = $this->work . '/restored/yarn-cache';

        $restore = $this->invoke('restore', 'yarn-cache', 'yarn-cache.tar.zst', $target);

        self::assertSame(0, $restore->exitCode, $restore->stderr);
        self::assertDirectoryExists($target);
        self::assertSame([], $this->entries($target));
        self::assertSame(['yarn-cache'], $this->entries($this->work . '/restored'));
        self::assertStringContainsString('HTTP 404', $restore->stdout . $restore->stderr);
    }

    public function testRestoreOfACorruptArchiveReportsTheFailureLeavesAnEmptyTargetAndSucceeds(): void
    {
        $this->seed('buildkit-cache', self::ARCHIVE, "not a zstd archive\n" . random_bytes(512));
        $target = $this->work . '/restored/buildkit-cache';

        $restore = $this->invoke('restore', 'buildkit-cache', self::ARCHIVE, $target);

        self::assertSame(0, $restore->exitCode, $restore->stderr);
        self::assertDirectoryExists($target);
        self::assertSame([], $this->entries($target));
        self::assertSame(['buildkit-cache'], $this->entries($this->work . '/restored'));
        self::assertStringContainsString('Cannot extract', $restore->stdout . $restore->stderr);
    }

    public function testSaveDeletesThePreviousFileBeforeUploadingAndAFailedUploadExitsNonZero(): void
    {
        $source = $this->work . '/source';
        mkdir($source);
        file_put_contents($source . '/cache.bin', 'cache');
        file_put_contents($this->work . '/server/fail-PUT', '500');

        $save = $this->invoke('save', 'buildkit-cache', self::ARCHIVE, $source);

        self::assertNotSame(0, $save->exitCode);
        self::assertStringContainsString('HTTP 500', $save->stderr);
        self::assertSame(['DELETE', 'PUT'], array_column($this->requests(), 'method'));
    }

    public function testBaselineFetchWritesTheStoredFileAndReportsAbsence(): void
    {
        $missing = $this->work . '/baseline/missing.json';
        $absent = $this->invoke('fetch', 'coverage', 'metrics.json', $missing);
        self::assertSame(0, $absent->exitCode, $absent->stderr);
        self::assertFileDoesNotExist($missing);
        self::assertStringContainsString('No baseline', $absent->stdout);

        $metrics = $this->work . '/metrics.json';
        file_put_contents($metrics, '{"lines":81.5}');
        $store = $this->invoke('store', 'coverage', 'metrics.json', $metrics);
        self::assertSame(0, $store->exitCode, $store->stderr);
        self::assertSame(['GET', 'DELETE', 'PUT'], array_column($this->requests(), 'method'));
        self::assertSame('application/json', $this->requests()[2]['type']);

        $fetched = $this->work . '/baseline/metrics.json';
        $fetch = $this->invoke('fetch', 'coverage', 'metrics.json', $fetched);
        self::assertSame(0, $fetch->exitCode, $fetch->stderr);
        self::assertSame('{"lines":81.5}', file_get_contents($fetched));
    }

    public function testTheTokenComesFromTheEnvironmentAndNeverAppearsInOutput(): void
    {
        $source = $this->work . '/source';
        mkdir($source);
        file_put_contents($source . '/cache.bin', 'cache');
        file_put_contents($this->work . '/metrics.json', '{}');
        $this->seed('broken', self::ARCHIVE, 'corrupt');

        $results = [
            $this->invoke('save', 'buildkit-cache', self::ARCHIVE, $source),
            $this->invoke('restore', 'buildkit-cache', self::ARCHIVE, $this->work . '/a'),
            $this->invoke('restore', 'broken', self::ARCHIVE, $this->work . '/b'),
            $this->invoke('restore', 'absent', self::ARCHIVE, $this->work . '/c'),
            $this->invoke('store', 'coverage', 'metrics.json', $this->work . '/metrics.json'),
            $this->invoke('fetch', 'coverage', 'metrics.json', $this->work . '/fetched.json'),
        ];
        file_put_contents($this->work . '/server/fail-PUT', '503');
        $results[] = $this->invoke('save', 'buildkit-cache', self::ARCHIVE, $source);
        $results[] = $this->invoke('store', 'coverage', 'metrics.json', $this->work . '/metrics.json', 'http://127.0.0.1:1/api/v1');

        foreach ($results as $result) {
            self::assertStringNotContainsString(self::TOKEN, $result->stdout . $result->stderr);
        }
        self::assertNotSame(0, $results[6]->exitCode);
        self::assertNotSame(0, $results[7]->exitCode);
        $requests = $this->requests();
        self::assertNotSame([], $requests);
        foreach ($requests as $request) {
            self::assertSame('token ' . self::TOKEN, $request['authorization']);
        }
    }

    private function invoke(string $operation, string $package, string $file, string $path, ?string $api = null): ProcessResult
    {
        return $this->executor->run(new ProcessSpec(
            argv: [PHP_BINARY, dirname(__DIR__, 2) . '/ci-package-store.php', $operation, $package, $file, $path],
            cwd: $this->work,
            environment: [
                'GITHUB_API_URL' => $api ?? 'http://' . $this->address . '/api/v1',
                'GITHUB_REPOSITORY' => 'baander/baander',
                'FORGEJO_TOKEN' => self::TOKEN,
            ],
            timeout: 60,
        ), new CompletionPolicy(null));
    }

    private function seed(string $package, string $file, string $content): void
    {
        file_put_contents(
            $this->work . '/server/store/' . rawurlencode('/api/v1/packages/baander/generic/' . $package . '/latest/' . $file),
            $content,
        );
    }

    /** @return list<array{method:string,path:string,authorization:string,type:string}> */
    private function requests(): array
    {
        $log = $this->work . '/server/requests.log';
        if (!is_file($log)) {
            return [];
        }
        return array_values(array_map(
            static fn(string $line): array => json_decode($line, true, flags: JSON_THROW_ON_ERROR),
            array_filter(explode("\n", (string) file_get_contents($log))),
        ));
    }

    /** @return array<string,string> */
    private function snapshot(string $directory): array
    {
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            $relative = substr($entry->getPathname(), strlen($directory) + 1);
            $files[$relative] = $entry->isDir() ? 'directory' : hash_file('sha256', $entry->getPathname());
        }
        ksort($files);
        return $files;
    }

    /** @return list<string> */
    private function entries(string $directory): array
    {
        $entries = array_values(array_diff((array) scandir($directory), ['.', '..']));
        sort($entries);
        return $entries;
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (array_diff((array) scandir($path), ['.', '..']) as $entry) {
            $this->remove($path . '/' . $entry);
        }
        rmdir($path);
    }

    private const string ROUTER = <<<'ROUTER'
        <?php

        declare(strict_types=1);

        $root = __DIR__;
        $method = $_SERVER['REQUEST_METHOD'];
        $path = (string) parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
        $headers = array_change_key_case(getallheaders());
        $body = (string) file_get_contents('php://input');
        file_put_contents($root . '/requests.log', json_encode([
            'method' => $method,
            'path' => $path,
            'authorization' => $headers['authorization'] ?? '',
            'type' => $headers['content-type'] ?? '',
        ], JSON_THROW_ON_ERROR) . "\n", FILE_APPEND | LOCK_EX);
        $stored = $root . '/store/' . rawurlencode($path);
        if (is_file($root . '/fail-' . $method)) {
            http_response_code((int) file_get_contents($root . '/fail-' . $method));
            echo 'stub failure';
            return true;
        }
        switch ($method) {
            case 'GET':
                if (!is_file($stored)) {
                    http_response_code(404);
                    echo '{"message":"package does not exist"}';
                    break;
                }
                header('Content-Type: application/octet-stream');
                readfile($stored);
                break;
            case 'PUT':
                if (is_file($stored)) {
                    http_response_code(409);
                    break;
                }
                file_put_contents($stored, $body);
                http_response_code(201);
                break;
            case 'DELETE':
                if (!is_file($stored)) {
                    http_response_code(404);
                    break;
                }
                unlink($stored);
                http_response_code(204);
                break;
            default:
                http_response_code(405);
        }
        return true;
        ROUTER;
}
