<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Docker\Client;
use Baander\Tooling\Docker\Target;
use Baander\Tooling\Inventory\Collector;
use Baander\Tooling\Process\EngineExecutor;
use Baander\Tooling\Process\ExecutionContext;
use Baander\Tooling\Resources\ResourceLedger;
use Baander\Tooling\Resources\ResourceKind;
use PHPUnit\Framework\TestCase;

final class ResourceLedgerTest extends TestCase
{
    private string $directory;
    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-resource-test-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
    }
    protected function tearDown(): void
    {
        Collector::remove($this->directory);
    }
    private function ledger(): ResourceLedger
    {
        $environment = array_replace(getenv(), ['RESOURCE_TEST_STATE' => $this->directory]);
        $client = new Client(new EngineExecutor(ExecutionContext::bounded(10, $environment)), new Target(__DIR__ . '/Fixtures/resource-ledger-docker.php', 'unix:///baander.app/docker.sock', 'owned-daemon', null, null), $environment);
        return new ResourceLedger($this->directory . '/receipt.json', $client, $environment);
    }
    public function testFailedRemovalRetainsReceiptAndRetryProvesAbsence(): void
    {
        $ledger = $this->ledger();
        $ledger->create(ResourceKind::Container, 'baander-test-owned', ['image']);
        touch($this->directory . '/fail-remove');
        $report = $ledger->cleanup(10);
        self::assertFalse($report->successful());
        self::assertFileExists($this->directory . '/receipt.json');
        self::assertFileExists($this->directory . '/resource.json');
        unlink($this->directory . '/fail-remove');
        self::assertTrue($ledger->cleanup(10)->successful());
        self::assertFileDoesNotExist($this->directory . '/resource.json');
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
    }
    public function testUncertainCreationIsReconciledByLabelAndForeignResourceIsRetained(): void
    {
        $ledger = $this->ledger();
        touch($this->directory . '/fail-create');
        try {
            $ledger->create(ResourceKind::Container, 'baander-test-owned', ['image']);
            self::fail('Expected failed create');
        } catch (\RuntimeException) {
        }
        self::assertFileExists($this->directory . '/receipt.json');
        $resource = json_decode(file_get_contents($this->directory . '/resource.json'), true);
        $resource['Config']['Labels'] = ['foreign' => 'resource'];
        file_put_contents($this->directory . '/resource.json', json_encode($resource));
        self::assertFalse($ledger->cleanup(10)->successful());
        self::assertFileExists($this->directory . '/resource.json');
        self::assertFileExists($this->directory . '/receipt.json');
    }
    public function testDaemonChangeAndTransportFailureNeverProveAbsence(): void
    {
        $ledger = $this->ledger();
        $ledger->create(ResourceKind::Container, 'baander-test-owned', ['image']);
        touch($this->directory . '/wrong-daemon');
        self::assertFalse($ledger->cleanup(10)->successful());
        unlink($this->directory . '/wrong-daemon');
        touch($this->directory . '/inspect-fails');
        self::assertFalse($ledger->cleanup(10)->successful());
        self::assertFileExists($this->directory . '/receipt.json');
        self::assertFileExists($this->directory . '/resource.json');
    }

    public function testUncertainCreateCanBeRecoveredAndOtherResourcesStillCleanUpAfterFailure(): void
    {
        $ledger = $this->ledger();
        touch($this->directory . '/fail-create');
        try {
            $ledger->create(ResourceKind::Container, 'baander-test-owned', ['image']);
            self::fail('Expected uncertain create');
        } catch (\RuntimeException) {
        }
        unlink($this->directory . '/fail-create');
        $ledger->create(ResourceKind::Container, 'baander-test-second', ['image']);
        touch($this->directory . '/fail-remove');
        self::assertFalse($ledger->cleanup(10)->successful());
        self::assertFileExists($this->directory . '/resource.json');
        self::assertFileDoesNotExist($this->directory . '/resource-baander-test-second.json');
        unlink($this->directory . '/fail-remove');
        self::assertTrue($ledger->cleanup(10)->successful());
        self::assertFileDoesNotExist($this->directory . '/resource.json');
    }

    public function testInspectAbsenceNeedsSuccessfulDaemonEnumeration(): void
    {
        $ledger = $this->ledger();
        $ledger->create(ResourceKind::Container, 'baander-test-owned', ['image']);
        unlink($this->directory . '/resource.json');
        touch($this->directory . '/list-fails');
        self::assertFalse($ledger->cleanup(10)->successful());
        self::assertFileExists($this->directory . '/receipt.json');
        unlink($this->directory . '/list-fails');
        self::assertTrue($ledger->cleanup(10)->successful());
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
    }

    public function testCleanupHasItsOwnBudgetAfterOrdinaryDeadlineExpires(): void
    {
        $this->ledger()->create(ResourceKind::Container, 'baander-test-owned', ['image']);
        $environment = array_replace(getenv(), ['RESOURCE_TEST_STATE' => $this->directory]);
        $expired = new Client(new EngineExecutor(new ExecutionContext($environment, deadline: hrtime(true) / 1_000_000_000 - 1)), new Target(__DIR__ . '/Fixtures/resource-ledger-docker.php', 'unix:///baander.app/docker.sock', 'owned-daemon', null, null), $environment);
        $ledger = new ResourceLedger($this->directory . '/receipt.json', $expired, $environment);
        self::assertTrue($ledger->cleanup(10)->successful());
        self::assertFileDoesNotExist($this->directory . '/resource.json');
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
    }

    public function testCleanupRemovesDependentContainerBeforeItsNetwork(): void
    {
        $ledger = $this->ledger();
        $ledger->create(ResourceKind::Network, 'baander-network', []);
        $ledger->create(ResourceKind::Container, 'baander-test-owned', ['--network', 'baander-network', 'image']);
        $report = $ledger->cleanup(10);
        self::assertTrue($report->successful(), $report->message());
        self::assertFileDoesNotExist($this->directory . '/resource.json');
        self::assertFileDoesNotExist($this->directory . '/resource-baander-network.json');
        self::assertFileDoesNotExist($this->directory . '/receipt.json');
    }

    public function testBrokenReceiptSymlinkCannotBeReportedAsSuccessfulAbsence(): void
    {
        symlink($this->directory . '/missing-receipt', $this->directory . '/receipt.json');
        self::assertFalse($this->ledger()->cleanup(10)->successful());
        self::assertTrue(is_link($this->directory . '/receipt.json'));
    }

    public function testRenamedOwnedContainerRetainsItsReceiptUntilIdentityIsReconciled(): void
    {
        $ledger = $this->ledger();
        $ledger->create(ResourceKind::Container, 'baander-test-owned', ['image']);
        $resource = json_decode(file_get_contents($this->directory . '/resource.json'), true, flags: JSON_THROW_ON_ERROR);
        $resource['Name'] = '/baander-renamed';
        file_put_contents($this->directory . '/resource.json', json_encode($resource, JSON_THROW_ON_ERROR));
        $report = $ledger->cleanup(10);
        self::assertFalse($report->successful());
        self::assertFileExists($this->directory . '/receipt.json');
        self::assertFileExists($this->directory . '/resource.json');
        self::assertStringContainsString('ownership does not match', $report->message());
        $resource['Name'] = '/baander-test-owned';
        file_put_contents($this->directory . '/resource.json', json_encode($resource, JSON_THROW_ON_ERROR));
        self::assertTrue($ledger->cleanup(10)->successful());
    }
    public function testSuccessfulBodyFailsOnCleanupAndPrimaryFailureKeepsItsCode(): void
    {
        $ledger = $this->ledger();
        $ledger->create(ResourceKind::Container, 'baander-test-owned', ['image']);
        touch($this->directory . '/fail-remove');
        try {
            $ledger->scoped(static fn(): int => 0, 10);
            self::fail('Expected cleanup failure');
        } catch (\RuntimeException $error) {
            self::assertNotSame(0, $error->getCode());
        }
        try {
            $ledger->scoped(static fn() => throw new \RuntimeException('primary failure', 47), 10);
            self::fail('Expected primary failure');
        } catch (\RuntimeException $error) {
            self::assertSame(47, $error->getCode());
            self::assertStringContainsString('primary failure', $error->getMessage());
            self::assertStringContainsString('cleanup', $error->getMessage());
        }
    }
}
