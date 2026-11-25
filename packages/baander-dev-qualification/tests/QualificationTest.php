<?php

declare(strict_types=1);

namespace Baander\DevQualification\Tests;

use Baander\Configuration\ApplicationLayout;
use Baander\Configuration\ToolingConfiguration;
use Baander\Deployment\Presets\Configuration;
use Baander\Deployment\Presets\RedisCredentials;
use Baander\Deployment\Presets\StateStore;
use Baander\Containers\Docker\Client;
use Baander\Containers\Docker\Target;
use Baander\Filesystem\AtomicFile;
use Baander\DevQualification\Fixtures\Runtime;
use Baander\Images\Inventory\Collector;
use Baander\Filesystem\PrivateDirectory;
use Baander\Process\Execution\EngineExecutor;
use Baander\Process\Execution\ExecutionContext;
use Baander\Process\Execution\ProcessResult;
use Baander\Process\Execution\ProcessSpec;
use Baander\Process\Execution\CommandExecutor;
use Baander\Process\Execution\CompletionPolicy;
use Baander\DevQualification\Fixtures\Qualification\Observer;
use Baander\DevQualification\Fixtures\Qualification\Qualification;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class QualificationTest extends TestCase
{
    private const array SIZE = ['webWorkers' => 2, 'databaseSlots' => 4, 'ordinaryConsumers' => 1, 'sharedBuffersMiB' => 128, 'workMemMiB' => 4, 'copySlots' => 2];

    public function testConfigurationDeclaresWholeHostAndLongWorkloadBeforePreparation(): void
    {
        $configuration = $this->configuration();
        $validated = Qualification::validateConfiguration($configuration);
        self::assertSame(7200, $validated['fixtureTimeoutSeconds']);
        self::assertSame(7200, $validated['maintenanceDeadlineSeconds']);
        self::assertSame(1800, $validated['durationSeconds']);
        $this->expectException(\InvalidArgumentException::class);
        Qualification::validateConfiguration(array_replace($configuration, ['durationSeconds' => 60]));
    }

    public function testColdStartContractAllowsItsSamplingGapWithoutRequiringThirtyMinutePlayback(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state): void {
            $contract = new \ReflectionMethod(Qualification::class, 'contract')->invoke($qualification, $state, 'cold-startup', 10.0, 0);
            $declaration = $contract->toArray();
            self::assertGreaterThan($declaration['maxSampleGapSeconds'], $declaration['durationSeconds']);
            self::assertLessThan(1800, $declaration['durationSeconds']);
            self::assertFalse($contract->requiresPlayback());
        });
    }

    public function testObserverStartFailureProducesAFailureReceiptAndClearsActiveRun(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            try {
                $qualification->start();
                self::fail('Observer startup unexpectedly succeeded.');
            } catch (\RuntimeException $error) {
                self::assertSame('Simulated observer startup failure.', $error->getMessage());
            }
            $saved = AtomicFile::readJson($directory . '/qualification.json');
            self::assertSame('start-failed', $saved['phase']);
            self::assertArrayNotHasKey('activeRun', $saved);
            $receipt = AtomicFile::readJson($directory . '/runs/cold-startup/receipt.json');
            self::assertSame('Simulated observer startup failure.', $receipt['failure']);
        });
    }

    public function testContractFailureProducesAFailureReceiptBeforeAnyObserverIsCreated(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            $state['images']['app'] = 'baander/fixture:latest';
            AtomicFile::writeJson($directory . '/qualification.json', $state);
            try {
                $qualification->start();
                self::fail('A mutable run image unexpectedly started.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('Run image must be immutable', $error->getMessage());
            }
            self::assertSame('start-failed', AtomicFile::readJson($directory . '/qualification.json')['phase']);
            self::assertFileExists($directory . '/runs/cold-startup/receipt.json');
            self::assertFileDoesNotExist($directory . '/runs/cold-startup/observer-resources.json');
        });
    }

    public function testScenarioObserverFailureAlsoRetainsItsFailureReceipt(): void
    {
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            $state['phase'] = 'running';
            $state['provision'] = ['libraries' => []];
            $state['playbackSlots'] = [];
            AtomicFile::writeJson($directory . '/qualification.json', $state);
            $result = $qualification->scenario('original-overlap');
            self::assertSame('Simulated observer startup failure.', $result['failure']);
            $saved = AtomicFile::readJson($directory . '/qualification.json');
            self::assertSame('scenario-failed', $saved['phase']);
            self::assertArrayNotHasKey('activeRun', $saved);
            self::assertSame('Simulated observer startup failure.', AtomicFile::readJson($directory . '/runs/original-overlap-1/receipt.json')['failure']);
        });
    }

    public function testHostWithSwapRefusesStartAndScenarioBeforeAnyDeploymentIsCreated(): void
    {
        $calls = [];
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory): void {
            try {
                $qualification->start();
                self::fail('A host with swap unexpectedly started.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('swap', $error->getMessage());
            }
            self::assertSame('prepared', AtomicFile::readJson($directory . '/qualification.json')['phase']);
            self::assertDirectoryDoesNotExist($directory . '/runs');
            $state['phase'] = 'running';
            $state['provision'] = ['libraries' => []];
            $state['playbackSlots'] = [];
            AtomicFile::writeJson($directory . '/qualification.json', $state);
            try {
                $qualification->scenario('original-overlap', 1, 1);
                self::fail('A host with swap unexpectedly ran a scenario.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('swap', $error->getMessage());
            }
            self::assertSame('running', AtomicFile::readJson($directory . '/qualification.json')['phase']);
            self::assertDirectoryDoesNotExist($directory . '/runs');
        }, function (CommandExecutor&\PHPUnit\Framework\MockObject\Stub $executor) use (&$calls): void {
            $executor->method('run')->willReturnCallback(self::hostMemoryDocker(1024 ** 3, $calls));
        });
        $created = array_filter($calls, static fn(ProcessSpec $call): bool => ($call->argv[3] ?? null) === 'create') |> array_values(...);
        self::assertCount(2, $created);
        foreach ($created as $call) {
            self::assertContains('type=bind,src=/proc,dst=/host/proc,readonly', $call->argv);
        }
        self::assertFalse(array_any($calls, static fn(ProcessSpec $call): bool => in_array('compose', $call->argv, true) || in_array('volume', $call->argv, true)));
    }

    public function testHostMemoryCheckNeedsAMeasuredHostWithinTheConstraintAndNoSwap(): void
    {
        Qualification::assertHostMemory(['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => 0], 4 * 1024 ** 3);
        $refused = 0;
        foreach ([['memTotalBytes' => 4 * 1024 ** 3 + 1, 'swapTotalBytes' => 0], ['memTotalBytes' => null, 'swapTotalBytes' => 0],
            ['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => 4096], ['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => null]] as $memory) {
            try {
                Qualification::assertHostMemory($memory, 4 * 1024 ** 3);
                self::fail('Accepted an unsuitable host.');
            } catch (\RuntimeException) {
                ++$refused;
            }
        }
        self::assertSame(4, $refused);
    }

    public function testDeploymentReplacementFailsWhenTheNewBootKeepsTheOldFingerprint(): void
    {
        $before = ['profileFingerprint' => str_repeat('a', 64), 'composeHash' => str_repeat('b', 64), 'size' => self::SIZE, 'workerBootId' => str_repeat('c', 32)];
        $after = ['profileFingerprint' => str_repeat('d', 64), 'composeHash' => str_repeat('e', 64), 'size' => array_replace(self::SIZE, ['webWorkers' => 1]), 'workerBootId' => str_repeat('f', 32)];
        $serving = ['workersReady' => true, 'healthy' => true, 'catalogIntact' => true, 'proxyTrust' => ['status' => 'passed', 'failures' => []]];
        $receipt = ['failure' => null, 'before' => $before, 'after' => $after, 'serving' => $serving];
        self::assertSame('passed', Qualification::replacementCheck($receipt)['status']);
        $receipt['serving']['proxyTrust'] = ['status' => 'failed', 'failures' => ['Web cannot reach the gateway health route.']];
        self::assertSame(['The new boot does not see clients through exactly its gateway.'], Qualification::replacementCheck($receipt)['failures']);
        $receipt['serving'] = $serving;
        $receipt['after']['profileFingerprint'] = $before['profileFingerprint'];
        $check = Qualification::replacementCheck($receipt);
        self::assertSame('failed', $check['status']);
        self::assertSame(['The new boot has the same deployment fingerprint as the old one.'], $check['failures']);
        unset($receipt['after'], $receipt['serving']);
        $receipt['failure'] = 'Simulated start failure.';
        self::assertContains('Simulated start failure.', Qualification::replacementCheck($receipt)['failures']);
    }

    public function testDeploymentReplacementNeedsADifferentDeclaredSizeAndNoPlayers(): void
    {
        $configuration = $this->configuration();
        $configuration['replacementSize'] = array_replace(self::SIZE, ['webWorkers' => 1]);
        self::assertSame(1, Qualification::validateConfiguration($configuration)['replacementSize']['webWorkers']);
        try {
            Qualification::validateConfiguration(array_replace($configuration, ['replacementSize' => self::SIZE]));
            self::fail('Accepted an unchanged replacement size.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('differ', $error->getMessage());
        }
        $this->withPreparedQualification(function (Qualification $qualification): void {
            try {
                $qualification->scenario('deployment-replacement');
                self::fail('Replaced without a declared size.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('replacementSize', $error->getMessage());
            }
            $this->expectException(\InvalidArgumentException::class);
            $qualification->scenario('deployment-replacement', 1, 1);
        });
    }

    public function testProxyTrustNeedsWebToTrustExactlyTheGatewayAndSeeEachSendersOwnAddressInEitherFamily(): void
    {
        $deployment = Configuration::fromArray($this->configuration()['deployment']);
        $web = ['Config' => ['Env' => ['APP_ENV=prod', 'TRUSTED_PROXIES=10.231.0.2,fd00:baa:231::2']], 'NetworkSettings' => ['Networks' => [
            'baander-qualification-private' => ['IPAddress' => '172.30.0.5', 'GlobalIPv6Address' => ''],
            'baander-qualification-edge' => ['IPAddress' => '10.231.0.8', 'GlobalIPv6Address' => 'fd00:baa:231:0:8000::'],
        ]]];
        $gateway = ['NetworkSettings' => ['Networks' => ['baander-qualification-edge' => ['IPAddress' => '10.231.0.2', 'GlobalIPv6Address' => 'fd00:baa:231::2']]]];
        $forwarded = ['ipv4' => '10.231.0.8', 'ipv6' => 'fd00:baa:231:0:8000::'];
        // Web's HTTP status for a request to its own edge address in each family.
        $answers = ['ipv4' => 200, 'ipv6' => 200];

        $check = Qualification::proxyTrustCheck($deployment, $web, $gateway, 200, $answers, $forwarded, '10.231.0.1');
        self::assertSame(['passed', []], [$check['status'], $check['failures']]);
        self::assertSame(['10.231.0.2,fd00:baa:231::2', '10.231.0.1'], [$check['trustedProxies'], $check['expectedHairpinClient']]);
        // The same address in another spelling is the same sender.
        $respelled = ['ipv4' => '10.231.0.8', 'ipv6' => 'FD00:0BAA:0231:0000:8000:0000:0000:0000'];
        self::assertSame('passed', Qualification::proxyTrustCheck($deployment, $web, $gateway, 200, $answers, $respelled, '10.231.0.1')['status']);

        $ipv4Trusted = $web;
        $ipv4Trusted['Config']['Env'][1] = 'TRUSTED_PROXIES=10.231.0.2';
        $rangeTrusted = $web;
        $rangeTrusted['Config']['Env'][1] = 'TRUSTED_PROXIES=10.231.0.0/28,fd00:baa:231::/64';
        $twoNetworks = $gateway;
        $twoNetworks['NetworkSettings']['Networks']['baander-qualification-private'] = ['IPAddress' => '172.30.0.6', 'GlobalIPv6Address' => ''];
        $ipv4Gateway = $gateway;
        $ipv4Gateway['NetworkSettings']['Networks']['baander-qualification-edge']['GlobalIPv6Address'] = '';
        foreach ([
            [[$ipv4Trusted, $gateway, 200, $answers, $forwarded, '10.231.0.1'], 'Web trusts "10.231.0.2" instead of exactly the gateway addresses 10.231.0.2,fd00:baa:231::2.'],
            [[$rangeTrusted, $gateway, 200, $answers, $forwarded, '10.231.0.1'], 'Web trusts "10.231.0.0/28,fd00:baa:231::/64" instead of exactly the gateway addresses 10.231.0.2,fd00:baa:231::2.'],
            [[$web, $twoNetworks, 200, $answers, $forwarded, '10.231.0.1'], 'The gateway joins baander-qualification-edge, baander-qualification-private instead of the edge network alone.'],
            [[$web, $ipv4Gateway, 200, $answers, $forwarded, '10.231.0.1'], 'The gateway does not hold its pinned edge address fd00:baa:231::2.'],
            [[$web, $gateway, null, $answers, $forwarded, '10.231.0.1'], 'Web cannot reach the gateway health route.'],
            // Web's server listening on IPv4 alone refuses its IPv6 edge address.
            [[$web, $gateway, 200, ['ipv4' => 200, 'ipv6' => null], $forwarded, '10.231.0.1'], 'Web does not answer on its IPv6 edge address "fd00:baa:231:0:8000::".'],
            [
                [$web, $gateway, 200, $answers, ['ipv4' => '198.51.100.7', 'ipv6' => 'fd00:baa:231:0:8000::'], '10.231.0.1'],
                'A request over IPv4 that forged X-Forwarded-For reached web as "198.51.100.7" instead of its sender\'s address.',
            ],
            [
                [$web, $gateway, 200, $answers, ['ipv4' => '10.231.0.8', 'ipv6' => '2001:db8::7'], '10.231.0.1'],
                'A request over IPv6 that forged X-Forwarded-For reached web as "2001:db8::7" instead of its sender\'s address.',
            ],
            [[$web, $gateway, 200, $answers, $forwarded, null], 'A host-local client reached web as "" instead of the trusted edge bridge address 10.231.0.1.'],
        ] as [$observed, $failure]) {
            self::assertSame([$failure], Qualification::proxyTrustCheck($deployment, ...$observed)['failures']);
        }

        // A host-local client of an IPv6 loopback listener arrives from the IPv6 bridge address.
        $ipv6Loopback = $this->configuration()['deployment'];
        $ipv6Loopback['listen']['address'] = '::1';
        $ipv6Loopback = Configuration::fromArray($ipv6Loopback);
        $check = Qualification::proxyTrustCheck($ipv6Loopback, $web, $gateway, 200, $answers, $forwarded, 'fd00:baa:231::1');
        self::assertSame(['passed', 'fd00:baa:231::1'], [$check['status'], $check['expectedHairpinClient']]);
        self::assertSame(
            ['A host-local client reached web as "10.231.0.1" instead of the trusted edge bridge address fd00:baa:231::1.'],
            Qualification::proxyTrustCheck($ipv6Loopback, $web, $gateway, 200, $answers, $forwarded, '10.231.0.1')['failures'],
        );

        // A listener on a LAN address trusts no host-local source, so no hairpin request is expected.
        $lan = $this->configuration()['deployment'];
        $lan['listen']['address'] = '192.0.2.40';
        self::assertSame('passed', Qualification::proxyTrustCheck(Configuration::fromArray($lan), $web, $gateway, 200, $answers, $forwarded, null)['status']);
    }

    public function testProxyTrustOfAnIpv4OnlyEdgeNetworkChecksIpv4Alone(): void
    {
        $data = $this->configuration()['deployment'] + ['network' => ['ipv6' => false]];
        $web = ['Config' => ['Env' => ['TRUSTED_PROXIES=10.231.0.2']], 'NetworkSettings' => ['Networks' => [
            'baander-qualification-private' => ['IPAddress' => '172.30.0.5', 'GlobalIPv6Address' => ''],
            'baander-qualification-edge' => ['IPAddress' => '10.231.0.8', 'GlobalIPv6Address' => ''],
        ]]];
        $gateway = ['NetworkSettings' => ['Networks' => ['baander-qualification-edge' => ['IPAddress' => '10.231.0.2', 'GlobalIPv6Address' => '']]]];

        $check = Qualification::proxyTrustCheck(Configuration::fromArray($data), $web, $gateway, 200, ['ipv4' => 200], ['ipv4' => '10.231.0.8'], '10.231.0.1');
        self::assertSame(['passed', []], [$check['status'], $check['failures']]);

        // Host-local clients of an IPv6 loopback listener reach an IPv4-only gateway from the IPv4 bridge.
        $data['listen']['address'] = '::1';
        $check = Qualification::proxyTrustCheck(Configuration::fromArray($data), $web, $gateway, 200, ['ipv4' => 200], ['ipv4' => '10.231.0.8'], '10.231.0.1');
        self::assertSame(['passed', '10.231.0.1'], [$check['status'], $check['expectedHairpinClient']]);
    }

    public function testAccessLogProgramReportsTheClientWebChargedTheProbePathTo(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-access-log-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        $line = static fn(string $channel, string $message): string => json_encode(['message' => $message, 'context' => [], 'channel' => $channel], JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($directory . '/prod.log', implode('', [
            "not a JSON record qualification-forwarding-0a1b\n",
            $line('app', 'Probe qualification-forwarding-0a1b mentioned outside a request line'),
            $line('swoole.access_log', '10.231.0.9 - - [22/Nov/2026:15:00:00 +0100] "GET /qualification-forwarding-ffff HTTP/1.1" 200 512'),
            $line('swoole.access_log', '10.231.0.8 - - [22/Nov/2026:15:00:01 +0100] "GET /qualification-forwarding-0a1b HTTP/1.1" 200 512'),
        ]));
        $executor = new EngineExecutor(ExecutionContext::bounded(15, getenv()));
        try {
            $result = $executor->run(new ProcessSpec(
                [PHP_BINARY, '-r', Qualification::accessLogProgram(), 'qualification-forwarding-0a1b'],
                environment: [...getenv(), 'BAANDER_LOG_DIR' => $directory],
            ));
            self::assertSame(['client' => '10.231.0.8'], json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR));
            self::assertNotEmpty(token_get_all('<?php ' . Qualification::requestProgram(), TOKEN_PARSE));
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }

    public function testHealthyAnswerNeedsHttpOkAndNoUnhealthyCheck(): void
    {
        $body = ['status' => 'healthy', 'checks' => [['component' => 'postgresql', 'status' => 'healthy'], ['component' => 'memory', 'status' => 'not_available']]];
        self::assertTrue(Qualification::healthy(['httpStatus' => 200, 'body' => $body]));
        self::assertFalse(Qualification::healthy(['httpStatus' => 503, 'body' => $body]));
        $body['checks'][] = ['component' => 'worker', 'status' => 'unhealthy'];
        self::assertFalse(Qualification::healthy(['httpStatus' => 200, 'body' => $body]));
        self::assertFalse(Qualification::healthy(['httpStatus' => null, 'body' => null]));
    }

    public function testOnlyOomEventsOfThisDeploymentCount(): void
    {
        $event = static fn(array $attributes, string $action = 'oom'): string => json_encode(['Action' => $action, 'Actor' => ['ID' => str_repeat('a', 64), 'Attributes' => $attributes]], JSON_THROW_ON_ERROR);
        $events = implode("\n", [
            $event(['name' => 'qualification-web-1', 'org.baander.preset.owner' => 'owner']),
            $event(['name' => 'qualification-execution', 'app.baander.worker.namespace' => 'qualification']),
            $event(['name' => 'qualification-web-1', 'org.baander.preset.owner' => 'owner'], 'die'),
            $event(['name' => 'unrelated', 'org.baander.preset.owner' => 'other']),
        ]) . "\n";
        self::assertSame(['qualification-web-1', 'qualification-execution'], Qualification::ownedOomEvents($events, 'qualification', 'owner'));
        self::assertSame([], Qualification::ownedOomEvents('', 'qualification', 'owner'));
    }

    public function testSeedReuseAcceptsUnchangedImportedSeedAndRejectsUnsafeSources(): void
    {
        $configuration = Qualification::validateConfiguration($this->configuration());
        $target = ['daemonId' => 'fixture', 'endpoint' => 'unix:///run/baander.app.sock'];
        $seedConfiguration = $configuration;
        $seedConfiguration['name'] = 'seed-qualification';
        $seed = ['version' => 1, 'phase' => 'running', 'configuration' => $seedConfiguration, 'target' => $target, 'runs' => ['cold-startup' => 'receipt']];
        Qualification::validateSeed($seed, $configuration, $target);
        $tuned = $configuration;
        $tuned['deployment']['resources']['redisMaxmemoryMiB'] = 256;
        $tuned['host']['constraintBytes'] = 8 * 1024 ** 3;
        Qualification::validateSeed($seed, $tuned, $target);
        self::assertSame(256, $tuned['deployment']['resources']['redisMaxmemoryMiB']);
        foreach ([['borrowedFrom' => []], ['changedSourcePath' => 'renamed.flac'], ['activeRun' => 'cold-startup'], ['phase' => 'cleaned'], ['target' => ['daemonId' => 'foreign', 'endpoint' => $target['endpoint']]]] as $change) {
            try {
                Qualification::validateSeed(array_replace($seed, $change), $configuration, $target);
                self::fail('Unsafe fixture seed accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Fixture seed', $error->getMessage());
            }
        }
    }

    public function testSeedAndChildPathsMustBeSeparateAndNotNested(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-paths-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        mkdir($directory . '/seed', 0700);
        mkdir($directory . '/child', 0700);
        try {
            self::assertSame($directory . '/seed', Qualification::sourceDirectory($directory . '/seed', $directory . '/child'));
            $this->expectException(\InvalidArgumentException::class);
            Qualification::sourceDirectory($directory . '/seed', $directory);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testParentImageCleanupWaitsForMatchingChildCleanupReceipt(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-borrowers-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        mkdir($directory . '/child', 0700);
        $state = ['borrowers' => [$directory . '/child' => ['borrowerId' => 'child-identity']]];
        $child = ['phase' => 'prepared', 'borrowedFrom' => ['stateDirectory' => $directory . '/seed', 'borrowerId' => 'child-identity']];
        try {
            AtomicFile::writeJson($directory . '/child/qualification.json', $child);
            try {
                Qualification::assertBorrowersCleaned($state, $directory . '/seed');
                self::fail('Active child allowed parent image cleanup.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Retain shared fixture images', $error->getMessage());
            }
            $child['phase'] = 'cleaned';
            AtomicFile::writeJson($directory . '/child/qualification.json', $child);
            Qualification::assertBorrowersCleaned($state, $directory . '/seed');
        } finally {
            Collector::remove($directory);
        }
    }

    public function testPreparedMediaCopyVerifiesHashesAndDoesNotShareMutableFiles(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-prepared-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        mkdir($directory . '/source', 0700);
        mkdir($directory . '/destination', 0700);
        file_put_contents($directory . '/source/song.flac', 'prepared source bytes');
        file_put_contents($directory . '/source/song.lrc', 'prepared sidecar');
        $manifest = ['files' => [['path' => 'song.flac', 'size' => filesize($directory . '/source/song.flac'), 'hash' => hash_file('xxh3', $directory . '/source/song.flac')]]];
        $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', Qualification::copyProgram(), $directory . '/source', $directory . '/destination'], stdin: json_encode($manifest, JSON_THROW_ON_ERROR)));
            self::assertSame(1, json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR)['verifiedMedia']);
            self::assertSame('prepared sidecar', file_get_contents($directory . '/destination/song.lrc'));
            file_put_contents($directory . '/destination/song.flac', 'changed child bytes');
            self::assertSame('prepared source bytes', file_get_contents($directory . '/source/song.flac'));
            unlink($directory . '/destination/song.flac');
            unlink($directory . '/destination/song.lrc');
            file_put_contents($directory . '/source/song.flac', 'changed seed bytes');
            $failure = $executor->run(new ProcessSpec([PHP_BINARY, '-r', Qualification::copyProgram(), $directory . '/source', $directory . '/destination'], stdin: json_encode($manifest, JSON_THROW_ON_ERROR)), new CompletionPolicy(null));
            self::assertNotSame(0, $failure->exitCode);
            self::assertSame(['.', '..'], scandir($directory . '/destination'));
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }

    public function testMetadataCoverageNeedsEverySongIdentityInsideItsWindowAndNoAlbumIdentity(): void
    {
        $dataset = ['albums' => ['album' => 'album-id'], 'files' => [
            ['kind' => 'song', 'metadata' => ['id' => 'first-song-id']],
            ['kind' => 'song', 'metadata' => ['id' => 'second-song-id']],
        ]];
        $path = tempnam(sys_get_temp_dir(), 'baander.app-metadata-proof-');
        try {
            // The album was matched before the window opened and is not requested again; one song was last requested then too.
            file_put_contents($path, self::metadataRequest(8, 'album-id') . self::metadataRequest(9, 'second-song-id') . self::metadataRequest(11, 'first-song-id'));
            $coverage = Qualification::metadataCoverage($path, $dataset, 10, 20);
            self::assertSame(['second-song-id'], $coverage['missingIdentities']);
            self::assertFalse($coverage['complete']);
            file_put_contents($path, self::metadataRequest(12, 'second-song-id'), FILE_APPEND);
            $coverage = Qualification::metadataCoverage($path, $dataset, 10, 20);
            self::assertSame([], $coverage['missingIdentities']);
            self::assertSame(2, $coverage['requiredIdentities']);
            self::assertTrue($coverage['complete']);
        } finally {
            unlink($path);
        }
    }

    public function testAnUnmatchedRequestInsideTheWindowFailsMetadataCoverage(): void
    {
        $dataset = ['albums' => ['album' => 'album-id'], 'files' => [['kind' => 'song', 'metadata' => ['id' => 'song-id']]]];
        $path = tempnam(sys_get_temp_dir(), 'baander.app-metadata-proof-');
        try {
            // A search the fixture answers with no result is matched and names no identity.
            file_put_contents($path, self::metadataRequest(11, 'song-id') . self::metadataRequest(12, null));
            $answered = Qualification::metadataCoverage($path, $dataset, 10, 20);
            self::assertTrue($answered['complete']);
            file_put_contents($path, self::metadataRequest(13, null, 'unmatched'), FILE_APPEND);
            $refused = Qualification::metadataCoverage($path, $dataset, 10, 20);
            self::assertSame([], $refused['missingIdentities']);
            self::assertSame(['Unmatched metadata request within observation window.'], $refused['errors']);
            self::assertFalse($refused['complete']);
        } finally {
            unlink($path);
        }
    }

    public function testDatasetIdentityUsesRecordedSourcesInsteadOfPreparationTime(): void
    {
        $dataset = ['preparation' => ['startedAt' => 1], 'files' => [
            ['path' => 'first.flac', 'hash' => 'abc', 'size' => 123, 'duration' => 1860.0],
            ['path' => 'second.mp4', 'hash' => 'def', 'size' => 456, 'duration' => 30.0],
        ]];
        $identity = Qualification::datasetDigest($dataset);
        $persisted = json_decode(json_encode($dataset, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
        $persisted['files'] = array_map(static function (array $file): array {
            ksort($file);
            return $file;
        }, $persisted['files']);
        self::assertSame($identity, Qualification::datasetDigest($persisted));
        $dataset['preparation']['startedAt'] = 2;
        $dataset['files'] = array_reverse($dataset['files']);
        self::assertSame($identity, Qualification::datasetDigest($dataset));
        $dataset['files'][0]['hash'] = 'changed';
        self::assertNotSame($identity, Qualification::datasetDigest($dataset));
    }

    public function testMaintenanceNeedsCompleteCatalogAndKnownEmptyQueues(): void
    {
        $snapshot = $this->snapshot();
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        self::assertTrue(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $queues['async']['state'] = 'uninitialized';
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $queues['async'] = $this->emptyQueue();
        $snapshot['libraries']['music']['songsWithMbid'] = 1999;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $snapshot['libraries']['movies']['missingCatalogFiles'] = 1;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
    }

    public function testMetadataMaintenanceNeedsAMusicBrainzIdOnEveryAlbum(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['libraries']['music']['albumsWithMbid'] = 99;
        $evidence = Qualification::maintenanceEvidence($snapshot, ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()], 45);
        self::assertTrue($evidence['scan']['completed']);
        self::assertFalse($evidence['metadata']['completed']);
    }

    public function testNormalVideoIngestionDoesNotRequireUnintegratedMovieEnrichment(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['libraries']['movies']['moviesWithTmdbId'] = 0;
        $snapshot['libraries']['episodes']['moviesWithTmdbId'] = 0;
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        $evidence = Qualification::maintenanceEvidence($snapshot, $queues, 45);
        self::assertTrue($evidence['metadata']['completed']);
        self::assertStringContainsString('unexercised', $evidence['metadata']['movieMetadataEnrichment']);
        $snapshot['libraries']['movies']['videos'] = 49;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
    }

    public function testScannedRenameCanLeaveOneUnavailableHistoricalIndexRow(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['libraries']['music']['indexedFiles'] = 2001;
        $snapshot['libraries']['music']['indexedUnavailableFiles'] = 1;
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        self::assertTrue(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
        $snapshot['libraries']['music']['indexedAvailableFiles'] = 1999;
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['scan']['completed']);
    }

    public function testRenamePlanAvoidsPlaybackSourcesAndPreservesTheExpectedContentHash(): void
    {
        $files = [];
        for ($index = 0; $index < 2000; ++$index) {
            $files[] = ['kind' => 'song', 'path' => 'run/music/album/song-' . $index . '.flac', 'hash' => 'digest-' . $index];
        }
        $plan = Qualification::renamePlan(['files' => $files], 'original-overlap', 1);
        self::assertSame('run/music/album/song-1999.flac', $plan['source']);
        self::assertSame('digest-1999', $plan['expectedHash']);
        self::assertSame('run/music/album/song-1999-original-overlap-1.lrc', $plan['targetSidecar']);
        self::assertNotEmpty(token_get_all('<?php ' . Qualification::renameProgram(), TOKEN_PARSE));
    }

    public function testRenameMovesMediaAndSidecarWithoutChangingContent(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-rename-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        file_put_contents($directory . '/song.flac', 'fixture bytes');
        file_put_contents($directory . '/song.lrc', 'fixture lyrics');
        $executor = new EngineExecutor(ExecutionContext::bounded(5, getenv()));
        $plan = ['source' => 'song.flac', 'target' => 'moved.flac', 'sourceSidecar' => 'song.lrc', 'targetSidecar' => 'moved.lrc', 'expectedHash' => hash_file('xxh3', $directory . '/song.flac')];
        try {
            $result = $executor->run(new ProcessSpec([PHP_BINARY, '-r', Qualification::renameProgram(), $directory], stdin: json_encode($plan, JSON_THROW_ON_ERROR)));
            $receipt = json_decode($result->stdout, true, flags: JSON_THROW_ON_ERROR);
            self::assertSame($plan['expectedHash'], $receipt['afterHash']);
            self::assertFileDoesNotExist($directory . '/song.flac');
            self::assertSame('fixture lyrics', file_get_contents($directory . '/moved.lrc'));
        } finally {
            $executor->close();
            Collector::remove($directory);
        }
    }

    public function testRenameContainerRunsWithoutAContainerLimit(): void
    {
        $created = null;
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory) use (&$created): void {
            $store = new StateStore($directory . '/deployment');
            $deployment = $store->load();
            $deployment['composeFile'] = $directory . '/compose.json';
            $store->save($deployment);
            $files = [];
            for ($index = 0; $index < 2000; ++$index) {
                $files[] = ['kind' => 'song', 'path' => 'run/music/album/song-' . $index . '.flac', 'hash' => 'digest-' . $index];
            }
            AtomicFile::writeJson($directory . '/dataset-manifest.json', ['files' => $files]);
            try {
                new \ReflectionMethod(Qualification::class, 'renameSource')->invokeArgs($qualification, [&$state, 'original-overlap', 1, $directory . '/run']);
            } catch (\RuntimeException) {
            }
            self::assertInstanceOf(ProcessSpec::class, $created);
            foreach (['--memory', '--memory-swap', '--pids-limit', '--cpus', '--cpuset-cpus'] as $limit) {
                self::assertNotContains($limit, $created->argv);
            }
            self::assertContains('--cgroupns', $created->argv);
            self::assertContains('memory_limit=64M', $created->argv);
        }, function (CommandExecutor&\PHPUnit\Framework\MockObject\Stub $executor) use (&$created): void {
            $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use (&$created): \Baander\Process\Execution\ProcessResult {
                $output = '';
                if (in_array('info', $command->argv, true)) {
                    $output = '{"ID":"fixture-daemon"}';
                } elseif (in_array('psql', $command->argv, true)) {
                    $output = '[{"publicId":"fixture-song","hash":"digest-1999"}]';
                } elseif (in_array('create', $command->argv, true)) {
                    $created = $command;
                    throw new \RuntimeException('Captured rename container before launch.');
                }
                return new \Baander\Process\Execution\ProcessResult(0, null, false, false, $output, '', 0);
            });
        });
    }

    public function testCopyStartsCanBeProvenBetweenSamplesWithoutInventingProcessConcurrency(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        file_put_contents($directory . '/maintenance.stdout', "BAANDER_MAINTENANCE_START:10.0\nBAANDER_MAINTENANCE_FINISH:20.0\n");
        $launch = ['executable' => 'ffmpeg', 'arguments' => ['-c:v', 'copy', '-c:a', 'copy']];
        file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 11], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 12], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/samples.jsonl', json_encode(['epochSeconds' => 13, 'processes' => []], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/database.jsonl', json_encode(['epochSeconds' => 15, 'snapshot' => ['jobs' => ['groups' => [['name' => 'SyncSongMessage', 'status' => 'running', 'count' => 1]]]]], JSON_THROW_ON_ERROR) . "\n");
        try {
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertTrue($proof['observed']);
            self::assertSame(2, $proof['copyLaunchesDuringMaintenance']);
            self::assertSame(0, $proof['sampledSimultaneousCopyProcessesLowerBound']);
            self::assertTrue(Qualification::copyOverlapProof($directory, 30, 1)['observed']);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testCopyStartsAfterCommandDispatchWhileMetadataStillRuns(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-async-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        file_put_contents($directory . '/maintenance.stdout', "BAANDER_MAINTENANCE_START:10.0\nBAANDER_MAINTENANCE_FINISH:20.0\n");
        $launch = ['executable' => 'ffmpeg', 'arguments' => ['-c:v', 'copy', '-c:a', 'copy']];
        file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 21], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 22], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/samples.jsonl', json_encode(['epochSeconds' => 23, 'processes' => []], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/database.jsonl', json_encode(['epochSeconds' => 25, 'snapshot' => ['jobs' => ['groups' => [['name' => 'SyncSongMessage', 'status' => 'running', 'count' => 1]]]]], JSON_THROW_ON_ERROR) . "\n");
        try {
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertTrue($proof['observed']);
            self::assertSame(2, $proof['copyLaunchesDuringMaintenance']);
            self::assertSame(20.0, $proof['maintenanceCommandFinishedEpoch']);
            self::assertSame(30.0, $proof['maintenanceFinishedEpoch']);
            self::assertSame(25.0, $proof['lastPendingMetadataEpoch']);
            self::assertFalse(Qualification::copyOverlapProof($directory, 21, 2)['observed']);
            file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 26], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 27], JSON_THROW_ON_ERROR) . "\n");
            self::assertFalse(Qualification::copyOverlapProof($directory, 30, 2)['observed']);
            file_put_contents($directory . '/database.jsonl', json_encode(['epochSeconds' => 25, 'snapshot' => ['jobs' => ['groups' => [['name' => 'SyncSongMessage', 'status' => 'completed', 'count' => 1]]]]], JSON_THROW_ON_ERROR) . "\n");
            self::assertFalse(Qualification::copyOverlapProof($directory, 30, 2)['observed']);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testAQueuedAlbumMatchRunKeepsMetadataWorkPendingAndAFinishedOneDoesNot(): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-match-queue-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        file_put_contents($directory . '/maintenance.stdout', "BAANDER_MAINTENANCE_START:10.0\nBAANDER_MAINTENANCE_FINISH:20.0\n");
        $launch = ['executable' => 'ffmpeg', 'arguments' => ['-c:v', 'copy', '-c:a', 'copy']];
        file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 21], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 22], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/samples.jsonl', json_encode(['epochSeconds' => 23, 'processes' => []], JSON_THROW_ON_ERROR) . "\n");
        $songSync = self::jobObservation(15, 'SyncSongMessage', 'running');
        try {
            file_put_contents($directory . '/database.jsonl', $songSync . self::jobObservation(25, 'DrainAlbumMatchesMessage', 'queued'));
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertSame(25.0, $proof['lastPendingMetadataEpoch']);
            self::assertSame(2, $proof['copyLaunchesDuringMaintenance']);
            self::assertTrue($proof['observed']);
            file_put_contents($directory . '/database.jsonl', $songSync . self::jobObservation(25, 'DrainAlbumMatchesMessage', 'finished'));
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertSame(15.0, $proof['lastPendingMetadataEpoch']);
            self::assertSame(0, $proof['copyLaunchesDuringMaintenance']);
            self::assertFalse($proof['observed']);
        } finally {
            Collector::remove($directory);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function artistSyncJobs(): iterable
    {
        yield 'the enumeration of the admin sync' => ['SyncArtistsMessage'];
        yield 'the fill of one artist' => ['SyncArtistMessage'];
    }

    #[DataProvider('artistSyncJobs')]
    public function testAQueuedArtistSyncKeepsMetadataWorkPendingAndAFinishedOneDoesNot(string $job): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-copy-artist-sync-' . (random_bytes(8) |> bin2hex(...));
        mkdir($directory, 0700);
        file_put_contents($directory . '/maintenance.stdout', "BAANDER_MAINTENANCE_START:10.0\nBAANDER_MAINTENANCE_FINISH:20.0\n");
        $launch = ['executable' => 'ffmpeg', 'arguments' => ['-c:v', 'copy', '-c:a', 'copy']];
        file_put_contents($directory . '/native.jsonl', json_encode($launch + ['at' => 21], JSON_THROW_ON_ERROR) . "\n" . json_encode($launch + ['at' => 22], JSON_THROW_ON_ERROR) . "\n");
        file_put_contents($directory . '/samples.jsonl', json_encode(['epochSeconds' => 23, 'processes' => []], JSON_THROW_ON_ERROR) . "\n");
        $songSync = self::jobObservation(15, 'SyncSongMessage', 'running');
        try {
            file_put_contents($directory . '/database.jsonl', $songSync . self::jobObservation(25, $job, 'queued'));
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertSame(25.0, $proof['lastPendingMetadataEpoch']);
            self::assertSame(2, $proof['copyLaunchesDuringMaintenance']);
            self::assertTrue($proof['observed']);
            file_put_contents($directory . '/database.jsonl', $songSync . self::jobObservation(25, $job, 'finished'));
            $proof = Qualification::copyOverlapProof($directory, 30, 2);
            self::assertSame(15.0, $proof['lastPendingMetadataEpoch']);
            self::assertSame(0, $proof['copyLaunchesDuringMaintenance']);
            self::assertFalse($proof['observed']);
        } finally {
            Collector::remove($directory);
        }
    }

    public function testMaintenanceLogParsesTheRetainedPrettyPrintedCommandDocuments(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'baander.app-maintenance-');
        try {
            $document = json_encode(['jobId' => 'fixture'], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            file_put_contents($path, str_repeat($document . "\nBAANDER_MAINTENANCE_RESULT_END\n", 4));
            self::assertCount(4, Qualification::parseMaintenanceLog($path));
            file_put_contents($path, $document);
            $this->expectException(\RuntimeException::class);
            Qualification::parseMaintenanceLog($path);
        } finally {
            unlink($path);
        }
    }

    public function testFailedOrRetryingWorkCannotBecomeSuccessfulMaintenance(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['jobs']['groups'] = [['status' => 'failed', 'count' => 1, 'retried' => 0, 'max_attempt' => 1]];
        $queues = ['async' => $this->emptyQueue(), 'scheduler' => $this->emptyQueue()];
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
        $snapshot['jobs']['groups'] = [['status' => 'finished', 'count' => 1, 'retried' => 8, 'max_attempt' => 8]];
        self::assertFalse(Qualification::maintenanceEvidence($snapshot, $queues, 45)['metadata']['completed']);
    }

    public function testObserverBootstrapIsExecutablePhpWithTheResolvedImageLayout(): void
    {
        $program = Observer::bootstrap(ApplicationLayout::fromArray(['version' => 1, 'root' => '/srv/baander']));
        self::assertStringContainsString("'/srv/baander/vendor/autoload.php'", $program);
        self::assertStringContainsString('\\Baander\\DevQualification\\Fixtures\\Qualification\\Observer::serve', $program);
        self::assertNotEmpty(token_get_all('<?php ' . $program, TOKEN_PARSE));
    }

    public function testObserverOnlyMapsOwnedRunningContainersAndRejectsRoleAmbiguity(): void
    {
        $container = ['Config' => ['Labels' => ['org.baander.preset.owner' => 'owner', 'com.docker.compose.service' => 'web']], 'State' => ['Running' => true, 'Pid' => 17]];
        self::assertSame(['web' => 17], Observer::rolesFromInspection([$container], 'deployment', 'owner'));
        self::assertSame([], Observer::rolesFromInspection([$container], 'deployment', 'foreign'));
        $this->expectException(\RuntimeException::class);
        Observer::rolesFromInspection([$container, $container], 'deployment', 'owner');
    }

    public function testNormalExitRequiresActualDieEventsForEveryOwnedProcess(): void
    {
        $containers = [['Id' => 'one', 'State' => ['Running' => true]], ['Id' => 'two', 'State' => ['Running' => true]]];
        $first = json_encode(['Action' => 'die', 'Actor' => ['ID' => 'one', 'Attributes' => ['exitCode' => '0']]], JSON_THROW_ON_ERROR) . "\n";
        $second = json_encode(['Action' => 'die', 'Actor' => ['ID' => 'two', 'Attributes' => ['exitCode' => '143']]], JSON_THROW_ON_ERROR) . "\n";
        self::assertFalse(Qualification::normalExits($containers, $first));
        self::assertTrue(Qualification::normalExits($containers, $first . $second));
        self::assertFalse(Qualification::normalExits($containers, $first . str_replace('143', '137', $second)));
    }

    public function testFailedStartRecordsEachServiceExitAndHealthState(): void
    {
        $web = ['Name' => '/baander-qualification-web-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'web']],
            'State' => ['Running' => false, 'ExitCode' => 255, 'OOMKilled' => true, 'Health' => ['Status' => 'unhealthy', 'Log' => [
                ['ExitCode' => 1, 'Output' => 'first'], ['ExitCode' => 1, 'Output' => 'second'], ['ExitCode' => 1, 'Output' => 'third'], ['ExitCode' => 1, 'Output' => str_repeat('x', 900)],
            ]]]];
        $redis = ['Name' => '/baander-qualification-redis-1', 'Config' => ['Labels' => ['com.docker.compose.service' => 'redis']],
            'State' => ['Running' => true, 'ExitCode' => 0, 'OOMKilled' => false]];
        $states = Qualification::serviceStates([$web, $redis]);
        self::assertSame(['name' => 'baander-qualification-web-1', 'service' => 'web', 'running' => false, 'exitCode' => 255, 'oomKilled' => true,
            'health' => 'unhealthy'], array_diff_key($states[0], ['healthLog' => true]));
        self::assertSame(['second', 'third'], array_slice(array_column($states[0]['healthLog'], 'output'), 0, 2));
        self::assertSame(512, strlen($states[0]['healthLog'][2]['output']));
        self::assertNull($states[1]['health']);
        self::assertSame([], $states[1]['healthLog']);
    }

    public function testOnlyUnansweredWebControlRequestsAreRetried(): void
    {
        self::assertTrue(Qualification::unansweredControlRequest("\n [ERROR] the web server closed the control connection without answering\n"));
        self::assertTrue(Qualification::unansweredControlRequest(' [ERROR] the web server did not answer within 5 seconds'));
        self::assertFalse(Qualification::unansweredControlRequest(' [ERROR] cannot connect to the web server control socket /run/control.sock: Permission denied'));
        self::assertFalse(Qualification::unansweredControlRequest(''));
    }

    public function testRedisDiagnosticsAuthenticateAsTheApplicationUserWithThePasswordOffTheArguments(): void
    {
        $sampled = null;
        $this->withPreparedQualification(function (Qualification $qualification, array $state, string $directory) use (&$sampled): void {
            $store = new StateStore($directory . '/deployment');
            $deployment = $store->load();
            $deployment['composeFile'] = $directory . '/compose.json';
            $store->save($deployment);

            $diagnostics = new \ReflectionMethod(Qualification::class, 'diagnostics')->invoke($qualification);

            self::assertSame('noeviction', $diagnostics['redis']['maxmemory_policy'] ?? null);
            self::assertSame([], new \ReflectionProperty(Qualification::class, 'unexpectedExits')->getValue($qualification));
            self::assertInstanceOf(ProcessSpec::class, $sampled);
            self::assertFalse(array_any($sampled->argv, static fn(string $argument): bool => str_contains($argument, 'private-redis')));
        }, function (CommandExecutor&\PHPUnit\Framework\MockObject\Stub $executor) use (&$sampled): void {
            $executor->method('run')->willReturnCallback(static function (ProcessSpec $command) use (&$sampled): ProcessResult {
                $output = match (true) {
                    in_array('app:server:stats', $command->argv, true) => '{"missing_workers":[],"worker_errors":[]}',
                    in_array('redis-cli', $command->argv, true) => self::redisInfo($sampled = $command),
                    default => '',
                };
                return new ProcessResult(0, null, false, false, $output, '', 0);
            });
        });
    }

    public function testOnlyARetiredOrNeverRecordedWorkerPairCountsAsRetired(): void
    {
        self::assertTrue(Qualification::pairRetired(null));
        self::assertTrue(Qualification::pairRetired(['phase' => 'retired']));
        self::assertFalse(Qualification::pairRetired(['phase' => 'preparing']));
        self::assertFalse(Qualification::pairRetired(['phase' => 'ready']));
    }

    private function withPreparedQualification(\Closure $test, ?\Closure $configureExecutor = null): void
    {
        $directory = sys_get_temp_dir() . '/baander.app-start-' . (random_bytes(8) |> bin2hex(...));
        $configuration = Qualification::validateConfiguration($this->configuration());
        $runtime = new Runtime(dirname(__DIR__, 3), 5, getenv(), ToolingConfiguration::resolve());
        $executor = $this->createStub(CommandExecutor::class);
        if ($configureExecutor === null) {
            $executor->method('run')->willReturnCallback(self::hostMemoryDocker(0));
        } else {
            $configureExecutor($executor);
        }
        PrivateDirectory::prepare($directory);
        $target = new Target(self::cleanupDocker($directory), 'unix:///run/baander.app-docker.sock', 'fixture-daemon', null, null);
        $deployment = new StateStore($directory . '/deployment');
        $deployment->save(['version' => 1, 'phase' => 'prepared', 'configuration' => $configuration['deployment'],
            'profile' => ['version' => 2, 'deployment' => ['capability' => 'remux', 'hardware' => null, 'webWorkers' => 2, 'doctrinePoolSlots' => 4],
                'autoscaling' => ['minimumConcurrency' => 1, 'maximumConcurrency' => 1], 'runtime' => ['phpHeapMiB' => 256, 'scheduledConsoleMiB' => 320]],
            'layout' => ['version' => 1, 'root' => '/srv/baander'], 'owner' => 'fixture-owner']);
        $state = ['version' => 1, 'phase' => 'prepared', 'target' => $target->toArray(), 'configuration' => $configuration,
            'effectiveDeployment' => $configuration['deployment'], 'runs' => [], 'freshCopyConsumed' => false, 'datasetDigest' => str_repeat('d', 64),
            'images' => ['operator' => 'sha256:' . str_repeat('e', 64), 'worker' => 'sha256:' . str_repeat('f', 64), 'app' => 'sha256:' . str_repeat('a', 64), 'manifestDigest' => str_repeat('b', 64), 'manifest' => ['sourceRevision' => str_repeat('c', 64)]]];
        AtomicFile::writeJson($directory . '/qualification.json', $state);
        try {
            $test(new Qualification($runtime, new Client($executor, $target, getenv()), $directory, $configuration), $state, $directory);
        } finally {
            $runtime->close();
            Collector::remove($directory);
        }
    }

    /**
     * Answers the host memory observation container and fails every later Docker command, so a test sees
     * exactly what a qualification action does once the host check has passed or refused.
     *
     * @param list<ProcessSpec> $calls
     * @return \Closure(ProcessSpec): ProcessResult
     */
    private static function hostMemoryDocker(int $swapBytes, array &$calls = []): \Closure
    {
        $memoryContainer = str_repeat('9', 64);
        return static function (ProcessSpec $command) use ($swapBytes, &$calls, $memoryContainer): ProcessResult {
            $calls[] = $command;
            $arguments = array_slice($command->argv, 3);
            $output = match (true) {
                $arguments === ['info', '--format', '{{json .}}'] => '{"ID":"fixture-daemon"}',
                ($arguments[0] ?? null) === 'create' && str_contains(implode(' ', $arguments), '->memory()') => $memoryContainer,
                $arguments === ['start', '--attach', $memoryContainer] => json_encode(['memTotalBytes' => 4 * 1024 ** 3, 'swapTotalBytes' => $swapBytes], JSON_THROW_ON_ERROR),
                $arguments === ['inspect', '--format', '{{.State.ExitCode}}', $memoryContainer] => '0',
                default => throw new \RuntimeException('Simulated observer startup failure.'),
            };
            return new ProcessResult(0, null, false, false, $output, '', 0);
        };
    }

    /**
     * Answers INFO as the preset's Redis does: its ACL disables `default`, and redis-cli prints the refusal
     * and exits 0.
     */
    private static function redisInfo(ProcessSpec $command): string
    {
        $user = array_search('--user', $command->argv, true);
        $authenticated = $user !== false
            && ($command->argv[$user + 1] ?? null) === RedisCredentials::USER
            && ($command->environment['REDISCLI_AUTH'] ?? null) === 'private-redis';

        return $authenticated
            ? "# Memory\r\nmaxmemory_policy:noeviction\r\n# Persistence\r\naof_last_write_status:ok\r\n# Stats\r\nevicted_keys:0\r\n"
            : "AUTH failed: WRONGPASS invalid username-password pair or user is disabled.\nNOAUTH Authentication required.\n";
    }

    /** A Docker command for resource cleanup that finds no remaining test container. */
    private static function cleanupDocker(string $directory): string
    {
        $path = $directory . '/docker';
        file_put_contents($path, <<<'SH'
            #!/bin/sh
            shift 2
            case "$1 $2" in
              "info --format") printf '{"ID":"fixture-daemon"}\n' ;;
              "container inspect") printf 'Error: No such container: %s\n' "$4" >&2; exit 1 ;;
            esac
            SH);
        chmod($path, 0700);
        return $path;
    }

    /** @return array<string,mixed> */
    private function configuration(): array
    {
        return ['version' => 1, 'name' => 'baander-qualification', 'deployment' => [
            'version' => 2, 'name' => 'baander-qualification', 'capability' => 'remux', 'size' => self::SIZE, 'origin' => 'http://baander.app:8080',
            'listen' => ['address' => '127.0.0.1', 'port' => 8080],
            'images' => array_fill_keys(['application', 'worker', 'operator', 'postgres', 'gateway', 'redis'], 'baander/fixture:local'),
            'environment' => ['APP_SECRET' => 'private-app', 'STREAM_URL_HMAC_SECRET' => 'private-stream', 'AUTH_TOTP_ENCRYPTION_KEY' => 'cHJpdmF0ZS10b3RwLWVuY3J5cHRpb24ta2V5LTMyYiE=', 'DB_USERNAME' => 'baander', 'DB_PASSWORD' => 'private-db', 'DB_DATABASE' => 'baander', 'REDIS_PASSWORD' => 'private-redis'],
        ], 'datasetRun' => 'repeatable-media',
            'host' => ['constraintBytes' => 12 * 1024 ** 3, 'storage' => 'disposable VM disk', 'description' => 'four CPU twelve GiB VM']];
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $libraries = [];
        foreach (['music' => 2000, 'movies' => 50, 'episodes' => 250] as $role => $count) {
            $libraries[$role] = ['exists' => true, 'scanStatus' => 'completed', 'missingCatalogFiles' => 0, 'indexedFiles' => $count, 'indexedAvailableFiles' => $count, 'indexedUnavailableFiles' => 0, 'songs' => $count, 'videos' => $count,
                'songsWithMbid' => $count, 'moviesWithTmdbId' => $count, 'albumsWithMbid' => 100];
        }
        return ['libraries' => $libraries, 'outbox' => ['dead' => 0, 'pending' => 0], 'jobs' => ['groups' => [['status' => 'finished', 'count' => 2300, 'retried' => 0, 'max_attempt' => 1]]]];
    }

    /** @return array<string,mixed> */
    private function emptyQueue(): array
    {
        return ['state' => 'known', 'unreadCount' => 0, 'pendingCount' => 0, 'dueCount' => 0];
    }

    /** One line of the metadata audit journal: a request the fixture answered, or one it refused. */
    private static function metadataRequest(float $at, ?string $identity, string $outcome = 'matched'): string
    {
        return json_encode(['at' => $at, 'outcome' => $outcome, 'identity' => $identity], JSON_THROW_ON_ERROR) . "\n";
    }

    /** One line of the database observations: a sample that saw one monitored job of the name in the status. */
    private static function jobObservation(float $epochSeconds, string $name, string $status): string
    {
        return json_encode(['epochSeconds' => $epochSeconds, 'snapshot' => ['jobs' => ['groups' => [['name' => $name, 'status' => $status, 'count' => 1]]]]], JSON_THROW_ON_ERROR) . "\n";
    }
}
