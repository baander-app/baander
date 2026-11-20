<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\LogViewer;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\LogViewer\LogQuery;
use App\Shared\Application\LogViewer\LogSqlQuery;
use App\Shared\Application\LogViewer\Service\FederatedLogViewer;
use App\Shared\Application\LogViewer\Service\LogNodeClientInterface;
use App\Shared\Application\LogViewer\Service\LogSnapshotStoreInterface;
use App\Shared\Application\LogViewer\Service\LogSqlExecutorInterface;
use App\Shared\Interface\Request\Log\LogFilterRequest;
use App\Shared\Interface\Resource\Log\LogEntriesResource;
use App\Shared\Interface\Resource\Log\LogSourcesResource;
use App\Shared\Interface\Resource\Log\LogSqlResource;
use PHPUnit\Framework\TestCase;

final class FederatedLogViewerTest extends TestCase
{
    public function testFederatedPagesFreezeRemoteCursorsAndBindFilters(): void
    {
        $client = new class implements LogNodeClientInterface {
            /** @var list<array{string, string, array<string, scalar|list<string>|null>}> */
            public array $requests = [];

            public function nodes(): array
            {
                return ['a', 'b'];
            }

            public function get(string $node, string $path, array $parameters = []): array
            {
                $this->requests[] = [$node, $path, $parameters];
                if ($path === '/sources') {
                    return ['items' => [['id' => 'source', 'tokenFile' => '/private/credential', 'token' => 'secret']], 'coverage' => ['complete' => true]];
                }
                $time = $node === 'a' ? '2026-11-20T10:00:00Z' : '2026-11-20T11:00:00Z';

                return ['items' => [['id' => 'record', 'sourceId' => 'source', 'timestamp' => $time, 'credential' => 'secret']], 'nextCursor' => null, 'snapshot' => ['highWater' => 1], 'coverage' => ['complete' => true]];
            }
        };
        $store = $this->store();
        $sql = $this->createStub(LogSqlExecutorInterface::class);
        $viewer = new FederatedLogViewer($client, $store, $sql);
        $inventory = $viewer->sources();
        self::assertArrayNotHasKey('tokenFile', $inventory['items'][0]);
        self::assertArrayNotHasKey('token', $inventory['items'][0]);
        self::assertSame($inventory, LogSourcesResource::from($inventory));
        $page = $viewer->entries(new LogQuery(limit: 1));
        self::assertSame('b', $page['items'][0]['node']);
        self::assertTrue($page['coverage']['complete']);
        self::assertArrayNotHasKey('credential', $page['items'][0]);
        self::assertSame($page, LogEntriesResource::from($page));
        self::assertNotNull($page['nextCursor']);
        $requests = count($client->requests);
        $next = $viewer->entries(new LogQuery(limit: 1, cursor: $page['nextCursor']));
        self::assertSame('a', $next['items'][0]['node']);
        self::assertNull($next['nextCursor']);
        self::assertCount($requests, $client->requests);
        self::assertSame($page['snapshot']['id'], $next['snapshot']['id']);
        $this->expectException(InvalidInputException::class);
        $viewer->entries(new LogQuery(search: 'changed', cursor: $page['nextCursor']));
    }

    public function testUnreachableConfiguredNodeRemainsVisibleAndSqlRequiresOptIn(): void
    {
        $client = $this->createStub(LogNodeClientInterface::class);
        $client->method('nodes')->willReturn(['offline']);
        $client->method('get')->willThrowException(new ServiceUnavailableException('unavailable'));
        $sql = $this->createMock(LogSqlExecutorInterface::class);
        $sql->expects(self::never())->method('run');
        $viewer = new FederatedLogViewer($client, $this->store(), $sql);
        self::assertSame([['id' => 'offline', 'status' => 'unreachable']], $viewer->sources()['nodes']);
        self::assertFalse($viewer->entries(new LogQuery())['coverage']['complete']);
        $this->expectException(ServiceUnavailableException::class);
        $viewer->query(new LogSqlQuery('SELECT count(*) FROM logs'));
    }

    public function testExplicitPartialSqlCarriesCoverage(): void
    {
        $client = $this->createStub(LogNodeClientInterface::class);
        $client->method('nodes')->willReturn([]);
        $sql = $this->createMock(LogSqlExecutorInterface::class);
        $sql->expects(self::once())->method('run')->with('SELECT 1', [])->willReturn(['columns' => ['1'], 'rows' => [[1]], 'truncated' => false]);
        $result = new FederatedLogViewer($client, $this->store(), $sql)->query(new LogSqlQuery('SELECT 1', true));
        self::assertFalse($result['coverage']['complete']);
        self::assertSame([[1]], $result['rows']);
        self::assertSame($result, LogSqlResource::from($result));
    }

    public function testEmptyRemoteScanPagesCannotAdvanceAnotherNodesOlderHead(): void
    {
        $viewer = new FederatedLogViewer($this->scanningClient(1), $this->store(), $this->createStub(LogSqlExecutorInterface::class));
        $page = $viewer->entries(new LogQuery(search: 'match', limit: 1));
        self::assertSame('a', $page['items'][0]['node']);
        $next = $viewer->entries(new LogQuery(search: 'match', limit: 1, cursor: $page['nextCursor']));
        self::assertSame('b', $next['items'][0]['node']);
    }

    public function testScanBudgetDefersOrderingUntilEveryAvailableNodeHasAHead(): void
    {
        $viewer = new FederatedLogViewer($this->scanningClient(128), $this->store(), $this->createStub(LogSqlExecutorInterface::class));
        $page = $viewer->entries(new LogQuery(search: 'match', limit: 1));
        self::assertSame([], $page['items']);
        self::assertNotNull($page['nextCursor']);
        self::assertFalse($page['coverage']['complete']);
        $next = $viewer->entries(new LogQuery(search: 'match', limit: 1, cursor: $page['nextCursor']));
        self::assertSame('a', $next['items'][0]['node']);
    }

    public function testUnavailableNodesSerializeSnapshotMapAsAnObject(): void
    {
        $viewer = new FederatedLogViewer($this->scanningClient(0, []), $this->store(), $this->createStub(LogSqlExecutorInterface::class));
        $page = $viewer->entries(new LogQuery());
        self::assertInstanceOf(\stdClass::class, $page['snapshot']['nodeSnapshots']);
        self::assertStringContainsString('"nodeSnapshots":{}', json_encode($page, JSON_THROW_ON_ERROR));
        self::assertIsString($page['snapshot']['filters']);
    }

    /** @param list<string> $nodes */
    private function scanningClient(int $emptyPages, array $nodes = ['a', 'b']): LogNodeClientInterface
    {
        $client = $this->createStub(LogNodeClientInterface::class);
        $client->method('nodes')->willReturn($nodes);
        $client->method('get')->willReturnCallback(static function (string $node, string $path, array $parameters = []) use ($emptyPages): array {
            if ($path === '/sources') {
                return ['items' => [['id' => 'source']], 'coverage' => ['complete' => true]];
            }
            $position = (int) ($parameters['cursor'] ?? '0');
            if ($node === 'a' && $position < $emptyPages) {
                return ['items' => [], 'nextCursor' => (string) ($position + 1), 'snapshot' => ['highWater' => 2], 'coverage' => ['complete' => false]];
            }

            return ['items' => [['id' => 'record', 'sourceId' => 'source', 'timestamp' => $node === 'a' ? '2026-11-20T12:00:00Z' : '2026-11-20T11:00:00Z']], 'nextCursor' => null, 'snapshot' => ['highWater' => 2], 'coverage' => ['complete' => true]];
        });

        return $client;
    }

    public function testChannelFacetsIncludeEveryDistinctChannelAcrossNodes(): void
    {
        $client = $this->createStub(LogNodeClientInterface::class);
        $client->method('nodes')->willReturn(['a', 'b']);
        $client->method('get')->willReturnCallback(static fn (string $node): array => [
            'items' => [], 'coverage' => ['complete' => true],
            'channels' => $node === 'a' ? array_map(static fn (int $number): string => 'channel-'.$number, range(0, 299)) : ['channel-0', 'security'],
        ]);
        $viewer = new FederatedLogViewer($client, $this->store(), $this->createStub(LogSqlExecutorInterface::class));
        $channels = $viewer->sources()['channels'];
        self::assertCount(301, $channels);
        self::assertContains('channel-299', $channels);
        self::assertContains('security', $channels);
        $sorted = $channels;
        sort($sorted, SORT_STRING);
        self::assertSame($sorted, $channels);
    }

    public function testChannelArrayFiltersForwardToNodesAndBindTheCursor(): void
    {
        $query = LogFilterRequest::fromQuery(['channels' => ['app', 'security']])->query();
        self::assertSame(['app', 'security'], $query->channels);
        self::assertNotSame($query->fingerprint(), new LogQuery(channels: ['worker'])->fingerprint());
        $client = $this->createMock(LogNodeClientInterface::class);
        $client->method('nodes')->willReturn(['a']);
        $client->expects(self::exactly(2))->method('get')->willReturnCallback(static function (string $node, string $path, array $parameters = []): array {
            if ($path === '/entries') {
                self::assertSame(['app', 'security'], $parameters['channels']);
                self::assertArrayNotHasKey('channel', $parameters);
            }

            return ['items' => [], 'nextCursor' => null, 'snapshot' => [], 'coverage' => ['complete' => true]];
        });
        new FederatedLogViewer($client, $this->store(), $this->createStub(LogSqlExecutorInterface::class))->entries($query);
    }

    public function testScalarChannelInputIsRejected(): void
    {
        $this->expectException(InvalidInputException::class);
        LogFilterRequest::fromQuery(['channels' => 'app']);
    }

    public function testSourceAvailabilityIsIndependentOfHistoricalCoverageGaps(): void
    {
        $client = $this->createStub(LogNodeClientInterface::class);
        $client->method('nodes')->willReturn(['a']);
        $client->method('get')->willReturn([
            'items' => [
                ['id' => 'active', 'status' => 'gap', 'available' => true],
                ['id' => 'removed', 'status' => 'gap', 'available' => false],
                ['id' => 'retained', 'status' => 'gap'],
            ],
            'coverage' => ['complete' => false, 'issues' => ['Earlier collection gap remains.']],
        ]);
        $viewer = new FederatedLogViewer($client, $this->store(), $this->createStub(LogSqlExecutorInterface::class));
        $inventory = $viewer->sources();
        self::assertSame([true, false, true], array_column($inventory['items'], 'available'));
        self::assertSame(['gap', 'gap', 'gap'], array_column($inventory['items'], 'status'));
        self::assertFalse($inventory['coverage']['complete']);
        self::assertSame($inventory, LogSourcesResource::from($inventory));
    }

    private function store(): LogSnapshotStoreInterface
    {
        return new class implements LogSnapshotStoreInterface {
            /** @var array<string, array<string, mixed>> */
            private array $items = [];

            public function save(array $snapshot): string
            {
                $id = bin2hex(random_bytes(24));
                $this->items[$id] = $snapshot;

                return $id;
            }

            public function load(string $id): array
            {
                return $this->items[$id];
            }
        };
    }
}
