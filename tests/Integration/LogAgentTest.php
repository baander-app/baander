<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Shared\Application\LogViewer\LogQuery;
use App\Shared\Infrastructure\LogViewer\Agent\AgentConfiguration;
use App\Shared\Infrastructure\LogViewer\Agent\AgentProtocol;
use App\Shared\Infrastructure\LogViewer\Agent\Collector;
use App\Shared\Infrastructure\LogViewer\Agent\DockerClient;
use App\Shared\Infrastructure\LogViewer\Agent\DockerLogSession;
use App\Shared\Infrastructure\LogViewer\ArchiveReader;
use App\Shared\Infrastructure\LogViewer\LogIndex;
use App\Shared\Infrastructure\LogViewer\LogNormalizer;
use App\Shared\Infrastructure\LogViewer\LogStorageLimitException;
use Baander\Containers\Docker\Target;
use Baander\Core\EnvironmentVariable;
use Baander\Deployment\Observability\LogCollection;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class LogAgentTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/baander-log-agent-'.(random_bytes(8) |> bin2hex(...));
        mkdir($this->directory, 0700);
        mkdir($this->directory.'/logs', 0700);
        file_put_contents($this->directory.'/token', str_repeat('a', 40));
        file_put_contents($this->directory.'/checkpoint-token', str_repeat('b', 40));
    }

    protected function tearDown(): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $item) {
            $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }
        rmdir($this->directory);
    }

    private function config(): AgentConfiguration
    {
        return new AgentConfiguration(['nodeId' => 'local', 'databasePath' => $this->directory.'/logs.sqlite', 'tokenFile' => $this->directory.'/token', 'checkpointTokenFile' => $this->directory.'/checkpoint-token', 'fileRoots' => [['path' => $this->directory.'/logs', 'service' => 'web']]]);
    }

    public function testFilesArchivesAndRestartUseDurableCheckpointsWithAuthenticatedReads(): void
    {
        file_put_contents($this->directory.'/logs/app.log', "[2026-11-20T12:00:00+00:00] app.INFO: first\n[2026-11-20T12:00:01+00:00] app.ERROR: second\n");
        file_put_contents($this->directory.'/logs/old.log.gz', gzencode("[2026-11-20T12:00:00+00:00] app.INFO: archived\n"));
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $collector = new Collector($this->config(), $index);
        // A final pass flushes a stopped producer's last logical record.
        $collector->tick('abcdef123456');
        $first = $index->query(new LogQuery());
        self::assertCount(3, $first['items']);
        new Collector($this->config(), $index)->tick('abcdef123456');
        self::assertCount(3, $index->query(new LogQuery())['items']);
        $protocol = new AgentProtocol($this->config(), new LogIndex($this->directory.'/logs.sqlite', true));
        self::assertSame(401, $protocol->handle('GET', '/entries', [], 'Bearer incorrect')[0]);
        self::assertSame(200, $protocol->handle('GET', '/entries', [], 'Bearer '.str_repeat('a', 40))[0]);
        self::assertSame(422, $protocol->handle('GET', '/entries', ['sources' => 'unadmitted'], 'Bearer '.str_repeat('a', 40))[0]);
        self::assertSame(404, $protocol->handle('GET', '/sql', [], 'Bearer '.str_repeat('a', 40))[0]);
        self::assertSame(422, $protocol->handle('GET', '/entries', ['path' => '/etc/passwd'], 'Bearer '.str_repeat('a', 40))[0]);
    }

    public function testSymlinkOutsideRootIsNeverCollectedAndMissingRootsReportGap(): void
    {
        file_put_contents($this->directory.'/secret.log', "secret\n");
        symlink($this->directory.'/secret.log', $this->directory.'/logs/link.log');
        $index = new LogIndex($this->directory.'/logs.sqlite');
        new Collector($this->config(), $index)->tick('abcdef123456');
        self::assertSame([], $index->query(new LogQuery())['items']);
        unlink($this->directory.'/logs/link.log');
        rmdir($this->directory.'/logs');
        new Collector($this->config(), $index)->tick();
        self::assertSame('gap', $index->sources()['items'][0]['status']);
    }
    public function testDockerInventoryFramesReplayAndOwnerAdmission(): void
    {
        $socket = $this->directory.'/docker.sock';
        $listener = stream_socket_server('unix://'.$socket, $errno, $error);
        self::assertIsResource($listener);
        $id = str_repeat('a', 64);
        $excluded = str_repeat('c', 64);
        $timestamp = gmdate('Y-m-d\TH:i:s').'.000000000Z';
        $payload = $timestamp." identical\n".$timestamp." identical\n";
        $inventoryFile = $this->directory.'/docker-inventory';
        file_put_contents($inventoryFile, 'present');
        $payloadFile = $this->directory.'/docker-payload';
        file_put_contents($payloadFile, "\x01\0\0\0".pack('N', strlen($payload)).$payload);
        $pid = pcntl_fork();
        if (0 === $pid) {
            while ($connection = @stream_socket_accept($listener, 10)) {
                $request = fgets($connection);
                while (false !== ($header = fgets($connection)) && "\r\n" !== $header) {
                }
                $uri = explode(' ', $request)[1];
                $path = parse_url($uri, PHP_URL_PATH);
                parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $parameters);
                $statusLine = str_ends_with($path, '/logs') && isset($parameters['since']) && !preg_match('/^\d+(?:\.\d{1,9})?$/D', $parameters['since']) ? 'HTTP/1.0 400 Bad Request' : 'HTTP/1.0 200 OK';
                $body = match ($path) {
                    '/v1.45/info' => json_encode(['ID' => 'expected-daemon']),
                    '/v1.45/containers/json' => 'present' === file_get_contents($inventoryFile) ? json_encode([['Id' => $id], ['Id' => $excluded]]) : '[]',
                    '/v1.45/events' => '',
                    '/v1.45/containers/'.$id.'/json' => json_encode(['Id' => $id, 'Config' => ['Labels' => ['com.docker.compose.project' => 'preview', 'com.docker.compose.service' => 'web'], 'Tty' => false], 'State' => ['StartedAt' => 'boot'], 'HostConfig' => ['LogConfig' => ['Type' => 'json-file']]]),
                    '/v1.45/containers/'.$excluded.'/json' => json_encode(['Id' => $excluded, 'Config' => ['Labels' => ['com.docker.compose.project' => 'other', 'com.docker.compose.service' => 'web']]]),
                    '/v1.45/containers/'.$id.'/logs' => file_get_contents($payloadFile),
                    default => 'unexpected',
                };
                $response = $statusLine."\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body;
                $offset = 0;
                while ($offset < strlen($response)) {
                    $written = fwrite($connection, substr($response, $offset, 65536));
                    if (false === $written || 0 === $written) {
                        break;
                    }
                    $offset += $written;
                }
                fclose($connection);
            }
            exit(0);
        }
        fclose($listener);
        try {
            $config = new AgentConfiguration(array_replace($this->config()->values, ['dockerSocket' => $socket, 'expectedDaemonId' => 'expected-daemon', 'containerGroups' => [['labels' => ['com.docker.compose.project' => 'preview'], 'services' => ['web']]]]));
            $index = new LogIndex($this->directory.'/logs.sqlite');
            $collector = new Collector($config, $index);
            $collector->tick();
            self::assertCount(2, $index->query(new LogQuery())['items']);
            $collector->tick();
            self::assertCount(2, $index->query(new LogQuery())['items']);
            self::assertTrue($index->query(new LogQuery())['coverage']['complete']);
            $payload .= $timestamp." distinct same timestamp\n";
            file_put_contents($payloadFile, "\x01\0\0\0".pack('N', strlen($payload)).$payload);
            $collector->tick();
            self::assertCount(3, $index->query(new LogQuery())['items']);
            $collector->tick();
            self::assertCount(3, $index->query(new LogQuery())['items']);
            self::assertTrue($index->query(new LogQuery())['coverage']['complete']);
            new Collector($config, $index)->tick();
            $records = $index->query(new LogQuery())['items'];
            self::assertCount(6, $records);
            self::assertTrue($records[0]['extra']['collectionReplay']['uncertain']);
            $activeSources = array_column($index->sources()['items'], null, 'id');
            self::assertTrue($activeSources[LogIndex::sourceId('local:container:'.$id)]['available']);
            self::assertFalse($index->query(new LogQuery())['coverage']['complete']);
            self::assertCount(2, $index->sources()['items']);
            $rotatedPayload = $timestamp." distinct same timestamp\n";
            file_put_contents($payloadFile, "\x01\0\0\0".pack('N', strlen($rotatedPayload)).$rotatedPayload);
            $collector->tick();
            self::assertCount(6, $index->query(new LogQuery())['items']);
            $issuesAfterRotation = array_merge(...array_column($index->sources()['items'], 'issues'));
            self::assertStringContainsString('boundary is missing', implode(' ', $issuesAfterRotation));
            $wrongDaemon = new AgentConfiguration(array_replace($config->values, ['expectedDaemonId' => 'other-daemon']));
            new Collector($wrongDaemon, $index)->tick();
            self::assertCount(6, $index->query(new LogQuery())['items']);
            $issuesAfterMismatch = array_merge(...array_column($index->sources()['items'], 'issues'));
            self::assertStringContainsString('identity mismatch', implode(' ', $issuesAfterMismatch));

            $largePayload = '';
            for ($number = 0; $number < 140; ++$number) {
                $line = $timestamp.' backlog-'.$number.' '.str_repeat('x', 131072)."\n";
                $largePayload .= "\x01\0\0\0".pack('N', strlen($line)).$line;
            }
            file_put_contents($payloadFile, $largePayload);
            unset($largePayload);
            $sourceId = LogIndex::sourceId('bounded-backlog-fixture');
            $index->upsertSource(['id' => $sourceId, 'status' => 'catching_up']);
            $session = new DockerLogSession(new DockerClient($socket), '/containers/'.$id.'/logs', false);
            $collected = 0;
            $received = [];
            $consume = static function (array $record) use ($index, $sourceId, &$collected, &$received): void {
                $received[] = strstr($record['raw'], ' ', true);
                $index->append($sourceId, 'backlog', ++$collected, new LogNormalizer()->normalize('backlog-'.$collected), ['sequence' => $collected]);
            };
            self::assertFalse($session->collect($consume, seconds: 10));
            self::assertGreaterThan(0, $index->checkpoint($sourceId)['sequence']);
            self::assertLessThan(140, $collected);
            self::assertTrue($session->collect($consume, seconds: 10));
            self::assertSame(140, $collected);
            self::assertSame(140, $index->checkpoint($sourceId)['sequence']);
            $expectedBacklog = array_map(static fn (int $number): string => 'backlog-'.$number, range(0, 139));
            self::assertSame($expectedBacklog, $received);
            new Collector($config, $index)->tick($id, microtime(true) + 10);
            $containerSource = LogIndex::sourceId('local:container:'.$id);
            self::assertSame(146, $index->checkpoint($containerSource)['sequence']);
            $sources = array_column($index->sources()['items'], null, 'id');
            self::assertTrue($sources[$containerSource]['finalCheckpoint']);

            $firstTime = gmdate('Y-m-d\TH:i:s', time() + 1).'Z';
            $secondTime = gmdate('Y-m-d\TH:i:s', time() + 2).'Z';
            $thirdTime = gmdate('Y-m-d\TH:i:s', time() + 3).'Z';
            $stderr = $firstTime." pending stderr before restart\n";
            $stdout = $secondTime." committed stdout\n".$thirdTime." later stdout\n";
            file_put_contents($payloadFile, "\x02\0\0\0".pack('N', strlen($stderr)).$stderr."\x01\0\0\0".pack('N', strlen($stdout)).$stdout);
            $interleaved = new DockerLogSession(new DockerClient($socket), '/containers/'.$id.'/logs', false);
            try {
                $interleaved->collect(static function (array $record) use ($index, $containerSource, $secondTime): void {
                    self::assertSame('committed stdout', $record['raw']);
                    $index->append($containerSource, 'boot', 147, new LogNormalizer()->normalize($record['raw']), ['timestamp' => $record['checkpointTimestamp'], 'resumeThrough' => $secondTime, 'sequence' => 147, 'boundary' => []]);
                    throw new \RuntimeException('Simulated collector interruption.');
                });
                self::fail('Interruption must stop the session.');
            } catch (\RuntimeException $error) {
                self::assertSame('Simulated collector interruption.', $error->getMessage());
            }
            unset($interleaved);
            self::assertSame($firstTime, $index->checkpoint($containerSource)['timestamp']);
            new Collector($config, $index)->tick();
            self::assertCount(1, $index->query(new LogQuery(search: 'pending stderr before restart'))['items']);
            self::assertSame($thirdTime, $index->checkpoint($containerSource)['timestamp']);

            $gatewayConfiguration = new AgentConfiguration(array_replace($config->values, ['containerGroups' => [['labels' => ['com.docker.compose.project' => 'preview'], 'services' => ['web'], 'requestHost' => 'preview.baander.app']]]));
            $gatewayIndex = new LogIndex($this->directory.'/gateway.sqlite');
            $gateway = new Collector($gatewayConfiguration, $gatewayIndex);
            $otherTime = gmdate('Y-m-d\TH:i:s', time() + 4).'Z';
            $ownTime = gmdate('Y-m-d\TH:i:s', time() + 5).'Z';
            $otherRecord = $otherTime.' '.json_encode(['host' => 'other.baander.app', 'message' => 'other preview secret'])."\n";
            file_put_contents($payloadFile, "\x01\0\0\0".pack('N', strlen($otherRecord)).$otherRecord);
            $before = 0;
            $gateway->tick();
            self::assertSame($before, $gatewayIndex->checkpoint($containerSource)['sequence']);
            self::assertSame($otherTime, $gatewayIndex->checkpoint($containerSource)['timestamp']);
            self::assertTrue($gatewayIndex->query(new LogQuery())['coverage']['complete']);
            self::assertCount(0, $gatewayIndex->query(new LogQuery(search: 'other preview secret'))['items']);
            $ownRecord = $ownTime.' '.json_encode(['host' => 'preview.baander.app', 'message' => 'own preview marker'])."\n";
            $gatewayPayload = $otherRecord.$ownRecord;
            file_put_contents($payloadFile, "\x01\0\0\0".pack('N', strlen($gatewayPayload)).$gatewayPayload);
            $gateway->tick();
            self::assertSame($before + 1, $gatewayIndex->checkpoint($containerSource)['sequence']);
            self::assertCount(1, $gatewayIndex->query(new LogQuery(search: 'own preview marker'))['items']);
            file_put_contents($payloadFile, "\x03\0\0\0".pack('N', 1).'x');
            $gateway->tick();
            $sourceErrors = array_column($gatewayIndex->sources()['items'], 'issues', 'id');
            self::assertContains('Invalid Docker log frame.', $sourceErrors[$containerSource]);
            $currentSources = array_column($gatewayIndex->sources()['items'], null, 'id');
            self::assertFalse($currentSources[$containerSource]['available']);
            file_put_contents($payloadFile, "\x01\0\0\0".pack('N', strlen($gatewayPayload)).$gatewayPayload);
            $gateway->tick();
            $currentSources = array_column($gatewayIndex->sources()['items'], null, 'id');
            self::assertTrue($currentSources[$containerSource]['available']);
            self::assertSame('gap', $currentSources[$containerSource]['status']);
            file_put_contents($inventoryFile, 'removed');
            $gateway->tick();
            $currentSources = array_column($gatewayIndex->sources()['items'], null, 'id');
            self::assertFalse($currentSources[$containerSource]['available']);
            self::assertNotEmpty($gatewayIndex->query(new LogQuery(search: 'own preview marker'))['items']);
            file_put_contents($inventoryFile, 'present');
            $gateway->tick();
            $currentSources = array_column($gatewayIndex->sources()['items'], null, 'id');
            self::assertTrue($currentSources[$containerSource]['available']);
            $limitedIndex = new LogIndex($this->directory.'/limited.sqlite', maxStorageBytes: 1);
            try {
                new Collector($config, $limitedIndex)->tick();
                self::fail('The impossible index budget must suspend collection.');
            } catch (LogStorageLimitException) {
            }
            $limitedSources = array_column($limitedIndex->sources()['items'], null, 'id');
            self::assertTrue($limitedSources[$containerSource]['available']);
            self::assertSame('gap', $limitedSources[$containerSource]['status']);
            self::assertSame([], $limitedIndex->query(new LogQuery())['items']);
        } finally {
            \Swoole\Process::kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
            unlink($socket);
        }
    }

    public function testIndependentAgentServesAuthenticatedReadsAndBoundedLocalCheckpoint(): void
    {
        if (!extension_loaded('swoole')) {
            self::markTestSkipped('Swoole runtime required.');
        }
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $address = stream_socket_get_name($reservation, false);
        $port = (int) substr(strrchr($address, ':'), 1);
        fclose($reservation);
        $configuration = array_replace($this->config()->values, ['listenHost' => '127.0.0.1', 'listenPort' => $port]);
        $target = new Target('/usr/bin/docker', 'unix:///var/run/docker.sock', 'fixture-daemon', null, null);
        $service = LogCollection::service('baander:fixture', dirname(__DIR__, 2), $target, $this->directory, $configuration);
        $process = proc_open([PHP_BINARY, dirname(__DIR__, 2).'/bin/log-agent.php'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $this->directory.'/stdout', 'w'], 2 => ['file', $this->directory.'/stderr', 'w']], $pipes, null, array_replace(getenv(), $service['environment']));
        self::assertIsResource($process);
        try {
            $curl = curl_init('http://127.0.0.1:'.$port.'/health');
            curl_setopt_array($curl, [CURLOPT_HTTPHEADER => ['Authorization: Bearer '.str_repeat('a', 40)], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT_MS => 200]);
            $health = false;
            $deadline = microtime(true) + 5;
            while (microtime(true) < $deadline && false === $health) {
                $health = curl_exec($curl);
                if (false === $health) {
                    usleep(50000);
                }
            }
            self::assertIsString($health, (string) file_get_contents($this->directory.'/stderr'));
            self::assertSame('local', json_decode($health, true)['node']);
            // The command a retiring worker's containment runs inside the agent's container.
            $checkpoint = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/log-agent.php', '--checkpoint', 'abcdef123456', '--timeout=2'], env: $service['environment'], timeout: 10);
            $checkpoint->run();
            self::assertSame(0, $checkpoint->getExitCode(), $checkpoint->getErrorOutput());
            self::assertTrue(json_decode($checkpoint->getOutput(), true)['complete']);
        } finally {
            proc_terminate($process);
            proc_close($process);
        }
    }

    public function testCheckpointCommandExitsNonZeroWhenNoAgentListens(): void
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        $address = stream_socket_get_name($reservation, false);
        $port = (int) substr(strrchr($address, ':'), 1);
        fclose($reservation);
        $configuration = json_encode(array_replace($this->config()->values, ['listenPort' => $port]), JSON_THROW_ON_ERROR);
        $checkpoint = new Process([PHP_BINARY, dirname(__DIR__, 2).'/bin/log-agent.php', '--checkpoint', 'abcdef123456', '--timeout=1'], env: [EnvironmentVariable::BAANDER_LOG_AGENT_CONFIG => $configuration], timeout: 10);
        $checkpoint->run();
        self::assertSame(1, $checkpoint->getExitCode());
        self::assertStringContainsString('Final log collection incomplete.', $checkpoint->getErrorOutput());
    }

    public function testRetirementFailureFileMakesCoverageIncomplete(): void
    {
        file_put_contents($this->directory.'/logs/collection-gaps.jsonl', json_encode(['timestamp' => gmdate('c'), 'level' => 'warning', 'message' => 'Final collection failed', 'coverage' => 'gap'])."\n");
        $index = new LogIndex($this->directory.'/logs.sqlite');
        new Collector($this->config(), $index)->tick('abcdef123456');
        self::assertFalse($index->query(new LogQuery())['coverage']['complete']);
        self::assertSame('gap', $index->sources()['items'][0]['status']);
    }

    public function testStableFileGraceFlushesLatestRecordAndMarksLateContinuation(): void
    {
        $path = $this->directory.'/logs/app.log';
        file_put_contents($path, "[2026-11-20T12:00:00+00:00] app.ERROR: first\n  initial frame\n");
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $collector = new Collector($this->config(), $index);
        $collector->tick();
        self::assertCount(0, $index->query(new LogQuery())['items']);
        usleep(1100000);
        $collector->tick();
        $records = $index->query(new LogQuery())['items'];
        self::assertCount(1, $records);
        self::assertStringContainsString('initial frame', $records[0]['raw']);
        file_put_contents($path, "  delayed frame\n[2026-11-20T12:00:01+00:00] app.INFO: next\n", FILE_APPEND);
        $collector->tick();
        $records = $index->query(new LogQuery())['items'];
        self::assertCount(2, $records);
        $fragments = array_filter($records, static fn (array $record): bool => isset($record['extra']['collectionFragment'])) |> array_values(...);
        self::assertCount(1, $fragments);
        self::assertSame(0, $fragments[0]['extra']['collectionFragment']['previousSequence']);
        self::assertStringContainsString('delayed frame', $fragments[0]['raw']);
    }

    public function testMissingRootsRecoverAndOptionalRootsDoNotFailFinalCollection(): void
    {
        $required = $this->directory.'/required';
        $optional = $this->directory.'/optional';
        $configuration = new AgentConfiguration(array_replace($this->config()->values, ['fileRoots' => [['path' => $required, 'service' => 'web'], ['path' => $optional, 'service' => 'execution', 'optional' => true]]]));
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $collector = new Collector($configuration, $index);
        $collector->tick();
        self::assertFalse($index->sources()['coverage']['complete']);
        mkdir($required);
        $collector->tick('abcdef123456');
        self::assertTrue($index->sources()['coverage']['complete']);
        $sources = array_column($index->sources()['items'], null, 'service');
        self::assertTrue($sources['web']['available']);
        self::assertFalse($sources['execution']['available']);
        mkdir($optional);
        $collector->tick();
        $sources = array_column($index->sources()['items'], null, 'service');
        self::assertTrue($sources['execution']['available']);
    }

    public function testUnchangedMultiMemberArchiveHashesOnceAndReplacementIsImported(): void
    {
        if (!extension_loaded('zip')) {
            self::markTestSkipped('ZIP runtime required.');
        }
        $path = $this->directory.'/logs/archive.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('one.log', "first member\n");
        $zip->addFromString('two.log', "second member\n");
        $zip->close();
        $hashes = 0;
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $collector = new Collector($this->config(), $index, static function (string $file) use (&$hashes): string {
            ++$hashes;
            return hash_file('sha256', $file);
        });
        $collector->tick();
        self::assertSame(1, $hashes);
        self::assertCount(2, $index->query(new LogQuery())['items']);
        $collector->tick();
        self::assertSame(1, $hashes);
        $zip->open($path);
        $zip->addFromString('one.log', "updated member\n");
        $zip->close();
        touch($path, time() + 2);
        $collector->tick();
        self::assertSame(2, $hashes);
        self::assertCount(4, $index->query(new LogQuery())['items']);
        $archiveBytes = file_get_contents($path);
        unlink($path);
        $collector->tick();
        self::assertSame([false, false], array_column($index->sources()['items'], 'available'));
        self::assertCount(4, $index->query(new LogQuery())['items']);
        file_put_contents($path, $archiveBytes);
        touch($path, time() + 4);
        $collector->tick();
        self::assertSame([true, true], array_column($index->sources()['items'], 'available'));
        self::assertCount(4, $index->query(new LogQuery())['items']);
    }

    public function testAgentChannelsUseAListAndRejectLegacyOrMalformedSelections(): void
    {
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $source = LogIndex::sourceId('channel-fixture');
        $index->upsertSource(['id' => $source, 'status' => 'following']);
        foreach (['app', 'security', 'messenger'] as $sequence => $channel) {
            $record = new LogNormalizer()->normalize(json_encode(['message' => 'channel fixture', 'channel' => $channel]));
            $index->append($source, 'boot', $sequence, $record);
        }
        $protocol = new AgentProtocol($this->config(), $index);
        $token = 'Bearer '.str_repeat('a', 40);
        [$status, $payload] = $protocol->handle('GET', '/entries', ['channels' => ['app', 'security']], $token);
        self::assertSame(200, $status);
        self::assertCount(2, $payload['items']);
        self::assertSame(['app', 'messenger', 'security'], $protocol->handle('GET', '/sources', [], $token)[1]['channels']);
        foreach ([['channel' => 'app'], ['channels' => 'app,security'], ['channels' => ['named' => 'app']], ['channels' => [['app']]]] as $query) {
            self::assertSame(422, $protocol->handle('GET', '/entries', $query, $token)[0]);
        }
    }

    public function testFileAvailabilityIsIndependentOfRetainedCoverageGap(): void
    {
        $path = $this->directory.'/logs/collection-gaps.jsonl';
        file_put_contents($path, json_encode(['timestamp' => gmdate('c'), 'message' => 'retirement gap', 'coverage' => 'gap'])."\n");
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $collector = new Collector($this->config(), $index);
        $collector->tick('abcdef123456');
        $source = $index->sources()['items'][0];
        self::assertSame('gap', $source['status']);
        self::assertTrue($source['available']);
        unlink($path);
        $collector->tick();
        self::assertFalse($index->sources()['items'][0]['available']);
        self::assertCount(1, $index->query(new LogQuery())['items']);
        file_put_contents($path, json_encode(['timestamp' => gmdate('c'), 'message' => 'retirement gap', 'coverage' => 'gap'])."\n");
        $collector->tick('abcdef123456');
        self::assertTrue($index->sources()['items'][0]['available']);
        self::assertSame('gap', $index->sources()['items'][0]['status']);
        $collector->finalGap('abcdef123456');
        $synthetic = array_filter($index->sources()['items'], static fn (array $item): bool => 'collector' === $item['kind']) |> array_values(...);
        self::assertFalse($synthetic[0]['available']);
    }

    public function testRemovedZipMemberBecomesUnavailableWhileRetainedRecordsRemainQueryable(): void
    {
        $path = $this->directory.'/logs/members.zip';
        $zip = new \ZipArchive();
        $zip->open($path, \ZipArchive::CREATE);
        $zip->addFromString('kept.log', "kept record\n");
        $zip->addFromString('removed.log', "retained removed-member record\n");
        $zip->close();
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $collector = new Collector($this->config(), $index);
        $collector->tick();
        $members = array_column($index->sources()['items'], null, 'member');
        $removedId = $members['removed.log']['id'];
        self::assertTrue($members['removed.log']['available']);
        $zip->open($path);
        $zip->deleteName('removed.log');
        $zip->close();
        touch($path, time() + 2);
        $collector->tick();
        $members = array_column($index->sources()['items'], null, 'member');
        self::assertTrue($members['kept.log']['available']);
        self::assertFalse($members['removed.log']['available']);
        self::assertCount(1, $index->query(new LogQuery(sources: [$removedId]))['items']);
        $zip->open($path);
        $zip->addFromString('removed.log', "restored member record\n");
        $zip->close();
        touch($path, time() + 4);
        $collector->tick();
        $members = array_column($index->sources()['items'], null, 'member');
        self::assertTrue($members['removed.log']['available']);
        self::assertCount(2, $index->query(new LogQuery(sources: [$removedId]))['items']);
    }

    public function testOlderStoredSourceMetadataGetsAvailabilityWithoutGuessingFromGap(): void
    {
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $present = $this->directory.'/logs/present.log';
        $missing = $this->directory.'/logs/missing.log';
        file_put_contents($present, "present record\n");
        foreach ([$present, $missing] as $path) {
            $identity = 'local:file:'.$path;
            $index->upsertSource(['id' => LogIndex::sourceId($identity), 'name' => $identity, 'kind' => 'file', 'status' => 'gap', 'issues' => ['Historic gap.']]);
        }
        $checkpointId = LogIndex::sourceId('local:checkpoint:abcdef123456');
        $index->upsertSource(['id' => $checkpointId, 'name' => 'local:checkpoint:abcdef123456', 'kind' => 'collector', 'status' => 'gap']);
        new Collector($this->config(), $index)->tick('abcdef123456');
        $sources = array_column($index->sources()['items'], null, 'id');
        self::assertTrue($sources[LogIndex::sourceId('local:file:'.$present)]['available']);
        self::assertSame('gap', $sources[LogIndex::sourceId('local:file:'.$present)]['status']);
        self::assertFalse($sources[LogIndex::sourceId('local:file:'.$missing)]['available']);
        self::assertFalse($sources[$checkpointId]['available']);
        self::assertNotContains(null, array_column($sources, 'available'));
    }

    public function testOriginAvailabilityRefreshesBeforeDockerBacklogWork(): void
    {
        $file = $this->directory.'/logs/ready.log';
        $archive = $this->directory.'/logs/ready.log.gz';
        file_put_contents($file, "readable file\n");
        file_put_contents($archive, gzencode("readable archive\n"));
        $member = new ArchiveReader()->members($archive)[0];
        $fileId = LogIndex::sourceId('local:file:'.$file);
        $archiveId = LogIndex::sourceId('local:file:'.$archive.':member:'.$member);
        $index = new LogIndex($this->directory.'/logs.sqlite');
        $index->upsertSource(['id' => $fileId, 'name' => 'local:file:'.$file, 'kind' => 'file', 'status' => 'gap', 'issues' => ['Historic gap.']]);
        $index->upsertSource(['id' => $archiveId, 'name' => basename($archive).':'.$member, 'kind' => 'archive', 'member' => $member, 'status' => 'archived']);
        unset($index);
        $socket = $this->directory.'/slow-daemon.sock';
        $listener = stream_socket_server('unix://'.$socket, $errno, $error);
        self::assertIsResource($listener);
        $config = new AgentConfiguration(array_replace($this->config()->values, ['dockerSocket' => $socket, 'expectedDaemonId' => 'daemon', 'containerGroups' => [['labels' => ['com.docker.compose.project' => 'preview']]]]));
        $pid = pcntl_fork();
        if (0 === $pid) {
            fclose($listener);
            new Collector($config, new LogIndex($this->directory.'/logs.sqlite'))->tick();
            exit(0);
        }
        $reaped = false;
        try {
            $connection = stream_socket_accept($listener, 3);
            self::assertIsResource($connection);
            $index = new LogIndex($this->directory.'/logs.sqlite', true);
            $sources = array_column($index->sources()['items'], null, 'id');
            self::assertTrue($sources[$fileId]['available']);
            self::assertSame('gap', $sources[$fileId]['status']);
            self::assertTrue($sources[$archiveId]['available']);
            self::assertSame($archive, $sources[$archiveId]['originPath']);
            self::assertNull($sources[$fileId]['lastCollectedAt']);
            $body = '{"ID":"wrong-daemon"}';
            fwrite($connection, "HTTP/1.0 200 OK\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
            fclose($connection);
            pcntl_waitpid($pid, $status);
            $reaped = true;
            self::assertSame(0, pcntl_wexitstatus($status));
        } finally {
            if (!$reaped) {
                \Swoole\Process::kill($pid, SIGTERM);
                pcntl_waitpid($pid, $status);
            }
            fclose($listener);
        }
    }

    public function testStorageLimitKeepsReadableFileAndArchiveAvailableWithGap(): void
    {
        file_put_contents($this->directory.'/logs/limited.log', "first record\nsecond record\n");
        file_put_contents($this->directory.'/logs/limited.log.gz', gzencode("archive record\n"));
        $index = new LogIndex($this->directory.'/logs.sqlite', maxStorageBytes: 1);
        $collector = new Collector($this->config(), $index);
        try {
            $collector->tick();
            self::fail('The intentionally impossible index budget must suspend collection.');
        } catch (LogStorageLimitException) {
        }
        self::assertSame([], $index->query(new LogQuery())['items']);
        foreach ($index->sources()['items'] as $source) {
            self::assertTrue($source['available']);
            self::assertSame('gap', $source['status']);
            self::assertNull($source['originError']);
        }
        try {
            $collector->tick();
            self::fail('The intentionally impossible index budget must suspend collection.');
        } catch (LogStorageLimitException) {
        }
        self::assertSame([], $index->query(new LogQuery())['items']);
        foreach ($index->sources()['items'] as $source) {
            self::assertTrue($source['available']);
        }
    }

    public function testTheDockerProxyPassesTheFiveReadsAndRefusesEveryOtherCall(): void
    {
        $daemon = $this->directory.'/daemon.sock';
        $proxy = $this->directory.'/proxy.sock';
        $requests = $this->directory.'/daemon-requests';
        $listener = stream_socket_server('unix://'.$daemon, $errno, $error);
        self::assertIsResource($listener);
        $id = str_repeat('d', 64);
        $pid = pcntl_fork();
        if (0 === $pid) {
            while ($connection = @stream_socket_accept($listener, 20)) {
                $request = (string) fgets($connection);
                while (false !== ($header = fgets($connection)) && "\r\n" !== $header) {
                }
                file_put_contents($requests, trim($request)."\n", FILE_APPEND);
                $path = (string) parse_url(explode(' ', $request)[1] ?? '', PHP_URL_PATH);
                // The event and followed log streams are endless: no length, closed by the daemon.
                if (str_ends_with($path, '/events') || str_ends_with($path, '/logs')) {
                    fwrite($connection, "HTTP/1.0 200 OK\r\nContent-Type: application/octet-stream\r\n\r\nfirst;");
                    usleep(300_000);
                    fwrite($connection, 'second;');
                } else {
                    $body = json_encode(['Path' => $path]);
                    fwrite($connection, "HTTP/1.0 200 OK\r\nContent-Type: application/json\r\nContent-Length: ".strlen($body)."\r\n\r\n".$body);
                }
                fclose($connection);
            }
            exit(0);
        }
        fclose($listener);
        file_put_contents($this->directory.'/nginx.conf', LogCollection::proxyConfiguration($daemon, $proxy));
        $nginx = new Process(['nginx', '-c', $this->directory.'/nginx.conf', '-p', $this->directory]);
        $nginx->start();
        try {
            for ($attempt = 0; $attempt < 100 && !file_exists($proxy); ++$attempt) {
                usleep(20_000);
            }
            self::assertFileExists($proxy, $nginx->getErrorOutput());
            $client = new DockerClient($proxy);

            self::assertSame(['Path' => '/v1.45/info'], $client->json('/info'));
            self::assertSame(['Path' => '/v1.45/containers/json'], $client->json('/containers/json?all=1&filters=%7B%7D'));
            self::assertSame(['Path' => '/v1.45/containers/'.$id.'/json'], $client->json('/containers/'.$id.'/json'));
            self::assertSame('first;second;', implode('', iterator_to_array($client->chunks('/events?since=1&until=2'), false)));
            self::assertSame('first;second;', implode('', iterator_to_array($client->chunks('/containers/'.$id.'/logs?follow=1&stdout=1&stderr=1&timestamps=1'), false)));

            foreach ([
                'POST /v1.45/containers/create',
                'DELETE /v1.45/containers/'.$id,
                'POST /v1.45/containers/'.$id.'/exec',
                'POST /v1.45/containers/'.$id.'/start',
                'POST /v1.45/containers/'.$id.'/json',
                'GET /v1.45/containers/'.$id.'/attach/ws',
                'GET /v1.45/containers/'.$id.'/export',
                'GET /v1.45/containers/'.$id.'/archive?path=/',
                'GET /v1.45/images/json',
                'GET /v1.45/containers/'.$id.'%2Fexport%3F/json',
            ] as $call) {
                self::assertSame('403', $this->proxyStatus($proxy, $call), $call);
            }
            // nginx matches and forwards the normalized path, so an encoded segment cannot
            // reach a path other than the one the allow list matched.
            self::assertSame('200', $this->proxyStatus($proxy, 'GET /v1.45/containers/'.$id.'/x/..%2Fjson'));
        } finally {
            $nginx->stop(1);
            posix_kill($pid, SIGTERM);
            pcntl_waitpid($pid, $status);
        }
        $received = file($requests, FILE_IGNORE_NEW_LINES) ?: [];
        self::assertSame([
            'GET /v1.45/info HTTP/1.0',
            'GET /v1.45/containers/json?all=1&filters=%7B%7D HTTP/1.0',
            'GET /v1.45/containers/'.$id.'/json HTTP/1.0',
            'GET /v1.45/events?since=1&until=2 HTTP/1.0',
            'GET /v1.45/containers/'.$id.'/logs?follow=1&stdout=1&stderr=1&timestamps=1 HTTP/1.0',
            'GET /v1.45/containers/'.$id.'/json HTTP/1.0',
        ], $received);
    }

    private function proxyStatus(string $socket, string $call): string
    {
        $stream = stream_socket_client('unix://'.$socket, $errno, $error, 5);
        self::assertIsResource($stream);
        fwrite($stream, $call." HTTP/1.0\r\nHost: docker\r\nContent-Length: 0\r\n\r\n");
        $status = (string) fgets($stream);
        fclose($stream);

        return explode(' ', $status)[1] ?? '';
    }
}
