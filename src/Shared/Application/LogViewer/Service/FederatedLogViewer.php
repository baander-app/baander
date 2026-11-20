<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer\Service;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Exception\ServiceUnavailableException;
use App\Shared\Application\LogViewer\LogQuery;
use App\Shared\Application\LogViewer\LogSqlQuery;
use App\Shared\Application\LogViewer\LogViewerInterface;
use Throwable;

final readonly class FederatedLogViewer implements LogViewerInterface
{
    public function __construct(private LogNodeClientInterface $client, private LogSnapshotStoreInterface $snapshots, private LogSqlExecutorInterface $sql)
    {
    }

    public function sources(): array
    {
        $items = $nodes = $reasons = $channels = [];
        $deadline = microtime(true) + 5;
        foreach ($this->client->nodes() as $node) {
            try {
                if (microtime(true) >= $deadline) {
                    throw new ServiceUnavailableException('Log inventory deadline reached.');
                }
                $data = $this->client->get($node, '/sources');
                foreach ($data['channels'] ?? [] as $channel) {
                    if (is_string($channel)) {
                        $channels[$channel] = $channel;
                    }
                }
                foreach ($data['items'] ?? [] as $source) {
                    $source = array_intersect_key($source, array_flip(['id', 'node', 'name', 'service', 'kind', 'format', 'size', 'status', 'available', 'lastCollectedAt', 'retainedFrom', 'issues', 'stream', 'archive', 'member', 'container', 'error']));
                    $source['id'] = self::qualify($node, $source['id']);
                    $source['node'] = $node;
                    $source += ['available' => true];
                    $items[] = $source;
                }
                $nodes[] = ['id' => $node, 'status' => 'healthy'];
                if (($data['coverage']['complete'] ?? false) !== true) {
                    $reasons[] = 'Collection coverage is incomplete on '.$node;
                    foreach ($data['coverage']['issues'] ?? [] as $issue) {
                        if (is_string($issue)) {
                            $reasons[] = $node.': '.$issue;
                        }
                    }
                }
            } catch (Throwable) {
                $nodes[] = ['id' => $node, 'status' => 'unreachable'];
                $reasons[] = 'Node unavailable: '.$node;
            }
        }
        if ($nodes === []) {
            $reasons[] = 'No log nodes configured.';
        }

        $channels = array_values($channels);
        sort($channels, SORT_STRING);

        return ['items' => $items, 'nodes' => $nodes, 'channels' => $channels, 'coverage' => self::coverage($reasons)];
    }

    public function entries(LogQuery $query): array
    {
        if ($query->cursor !== null) {
            $state = $this->snapshots->load($query->cursor);
            if ($state['snapshot']['filters'] !== $query->fingerprint()) {
                throw new InvalidInputException('Log cursor does not match these filters.');
            }
        } else {
            $inventory = $this->sources();
            $selected = $this->selectedSources($query, $inventory);
            $nodes = [];
            foreach ($this->client->nodes() as $node) {
                if ($selected === [] || isset($selected[$node])) {
                    $nodes[$node] = ['sources' => $selected[$node] ?? [], 'buffer' => [], 'cursor' => null, 'started' => false, 'done' => false];
                }
            }
            $state = ['nodes' => $nodes, 'coverage' => $selected === [] ? $inventory['coverage'] : self::selectedCoverage($inventory['nodes'], array_keys($selected)), 'snapshot' => ['id' => bin2hex(random_bytes(12)), 'createdAt' => gmdate('c'), 'expiresAt' => gmdate('c', time() + 900), 'filters' => $query->fingerprint(), 'nodeSnapshots' => []]];
        }
        $items = [];
        $deadline = microtime(true) + 5;
        $requests = 0;
        while (count($items) < $query->limit) {
            $deferred = false;
            foreach ($state['nodes'] as $node => &$nodeState) {
                while ($nodeState['buffer'] === [] && !$nodeState['done']) {
                    if (microtime(true) >= $deadline || $requests >= 128) {
                        $state['coverage']['reasons'][] = 'Log page scan budget reached; continue this snapshot.';
                        $deferred = true;
                        break 2;
                    }
                    ++$requests;
                    try {
                        $data = $this->client->get($node, '/entries', [
                            'sources' => implode(',', $nodeState['sources']), 'search' => $query->search,
                            'levels' => implode(',', $query->levels), 'channels' => $query->channels,
                            'correlationId' => $query->correlationId, 'since' => $query->since, 'until' => $query->until,
                            'order' => $query->order, 'limit' => min(16, $query->limit), 'cursor' => $nodeState['cursor'],
                        ]);
                        $bufferBytes = strlen(json_encode($data['items'] ?? [], JSON_THROW_ON_ERROR));
                        foreach ($state['nodes'] as $bufferedNode) {
                            $bufferBytes += strlen(json_encode($bufferedNode['buffer'], JSON_THROW_ON_ERROR));
                        }
                        if ($bufferBytes > 67108864) {
                            throw new ServiceUnavailableException('Log page buffer limit reached.');
                        }
                        $nodeState['buffer'] = array_map(static fn (array $record): array => self::record($node, $record), $data['items'] ?? []);
                        $previousCursor = $nodeState['cursor'];
                        $nodeState['cursor'] = $data['nextCursor'] ?? null;
                        $nodeState['done'] = $nodeState['cursor'] === null;
                        if ($nodeState['cursor'] !== null && $nodeState['cursor'] === $previousCursor) {
                            $nodeState['done'] = true;
                            $state['coverage']['reasons'][] = 'Node pagination did not advance: '.$node;
                        }
                        $state['snapshot']['nodeSnapshots'][$node] = $data['snapshot'] ?? null;
                        if (($data['coverage']['complete'] ?? false) !== true) {
                            $state['coverage']['reasons'][] = 'Record coverage is incomplete on '.$node;
                            foreach ($data['coverage']['issues'] ?? [] as $issue) {
                                if (is_string($issue)) {
                                    $state['coverage']['reasons'][] = $node.': '.$issue;
                                }
                            }
                        }
                    } catch (InvalidInputException $e) {
                        throw $e;
                    } catch (Throwable) {
                        $nodeState['done'] = true;
                        $state['coverage']['reasons'][] = 'Snapshot unavailable on '.$node;
                    }
                }
            }
            unset($nodeState);
            if ($deferred) {
                break;
            }
            $chosen = null;
            foreach ($state['nodes'] as $node => $nodeState) {
                if ($nodeState['buffer'] === []) {
                    continue;
                }
                if ($chosen === null) {
                    $chosen = $node;
                    continue;
                }
                $a = $nodeState['buffer'][0];
                $b = $state['nodes'][$chosen]['buffer'][0];
                $comparison = [$a['timestamp'], $a['id']] <=> [$b['timestamp'], $b['id']];
                if (($query->order === 'asc' && $comparison < 0) || ($query->order === 'desc' && $comparison > 0)) {
                    $chosen = $node;
                }
            }
            if ($chosen === null) {
                break;
            }
            $items[] = array_shift($state['nodes'][$chosen]['buffer']);
        }
        $state['coverage'] = self::coverage($state['coverage']['reasons']);
        $more = array_filter($state['nodes'], static fn (array $n): bool => !$n['done'] || $n['buffer'] !== []);
        $cursor = $more !== [] ? $this->snapshots->save($state) : null;

        return ['items' => $items, 'nextCursor' => $cursor, 'snapshot' => self::publicSnapshot($state['snapshot']), 'coverage' => $state['coverage']];
    }

    public function detail(string $id): array
    {
        [$node, $local] = $this->unqualify($id);
        try {
            $record = $this->client->get($node, '/entry/'.rawurlencode($local));
        } catch (NotFoundException $e) {
            throw $e;
        } catch (Throwable $e) {
            throw new ServiceUnavailableException('Log node is unavailable.', ['node' => $node], $e);
        }

        return self::record($node, $record);
    }

    public function query(LogSqlQuery $query): array
    {
        if ($query->filter->cursor !== null) {
            throw new InvalidInputException('SQL filters cannot contain a browse cursor.');
        }
        $snapshot = $this->freeze($query->filter);
        if (!$snapshot['coverage']['complete'] && !$query->allowPartial) {
            throw new ServiceUnavailableException('SQL snapshot is incomplete. Explicitly allow partial coverage to query it.', ['coverage' => $snapshot['coverage']]);
        }
        $result = $this->sql->run($query->sql, $snapshot['items']);
        $snapshot['snapshot']['id'] = $this->snapshots->save($snapshot);

        return $result + ['snapshot' => self::publicSnapshot($snapshot['snapshot']), 'coverage' => $snapshot['coverage']];
    }

    /** @return array<string, mixed> */
    private function freeze(LogQuery $query): array
    {
        $inventory = $this->sources();
        $selected = $this->selectedSources($query, $inventory);
        $items = $nodeSnapshots = [];
        $reasons = ($selected === [] ? $inventory['coverage'] : self::selectedCoverage($inventory['nodes'], array_keys($selected)))['reasons'];
        $bytes = 0;
        $deadline = microtime(true) + 15;
        foreach ($this->client->nodes() as $node) {
            if ($selected !== [] && !isset($selected[$node])) {
                continue;
            }
            $cursor = null;
            $seen = [];
            try {
                do {
                    if (microtime(true) >= $deadline) {
                        $reasons[] = 'Snapshot collection deadline reached.';
                        break 2;
                    }
                    $data = $this->client->get($node, '/entries', [
                        'sources' => implode(',', $selected[$node] ?? []), 'search' => $query->search,
                        'levels' => implode(',', $query->levels), 'channels' => $query->channels,
                        'correlationId' => $query->correlationId, 'since' => $query->since, 'until' => $query->until,
                        'order' => $query->order, 'limit' => 16, 'cursor' => $cursor,
                    ]);
                    $nodeSnapshots[$node] = $data['snapshot'] ?? null;
                    if (($data['coverage']['complete'] ?? false) !== true) {
                        $reasons[] = 'Record coverage is incomplete on '.$node;
                    }
                    foreach ($data['items'] ?? [] as $record) {
                        $record = self::record($node, $record);
                        $bytes += strlen(json_encode($record, JSON_THROW_ON_ERROR));
                        if (count($items) >= 100000 || $bytes > 67108864) {
                            $reasons[] = 'Snapshot record or byte limit reached.';
                            break 3;
                        }
                        $items[] = $record;
                    }
                    $cursor = $data['nextCursor'] ?? null;
                    if ($cursor !== null && isset($seen[$cursor])) {
                        $reasons[] = 'Node pagination did not advance: '.$node;
                        break;
                    }
                    if ($cursor !== null) {
                        $seen[$cursor] = true;
                    }
                } while ($cursor !== null);
            } catch (Throwable) {
                $reasons[] = 'Snapshot unavailable on '.$node;
            }
        }
        usort($items, static function (array $a, array $b) use ($query): int {
            $comparison = [$a['timestamp'], $a['id']] <=> [$b['timestamp'], $b['id']];

            return $query->order === 'asc' ? $comparison : -$comparison;
        });

        return ['items' => $items, 'snapshot' => ['createdAt' => gmdate('c'), 'expiresAt' => gmdate('c', time() + 900), 'filters' => $query->fingerprint(), 'nodeSnapshots' => $nodeSnapshots], 'coverage' => self::coverage($reasons)];
    }

    /**
     * @param list<string> $reasons
     * @return array{complete: bool, reasons: list<string>}
     */
    private static function coverage(array $reasons): array
    {
        return ['complete' => $reasons === [], 'reasons' => $reasons |> array_unique(...) |> array_values(...)];
    }

    /**
     * @param array<string, mixed> $snapshot
     * @return array<string, mixed>
     */
    private static function publicSnapshot(array $snapshot): array
    {
        $snapshot['nodeSnapshots'] = (object) $snapshot['nodeSnapshots'];

        return $snapshot;
    }

    /**
     * @param array<string, mixed> $inventory
     * @return array<string, list<string>>
     */
    private function selectedSources(LogQuery $query, array $inventory): array
    {
        $known = array_fill_keys(array_column($inventory['items'], 'id'), true);
        $healthy = array_fill_keys(array_column(array_filter($inventory['nodes'], static fn (array $node): bool => $node['status'] === 'healthy'), 'id'), true);
        $selected = [];
        foreach ($query->sources as $source) {
            [$node, $local] = $this->unqualify($source);
            if (!isset($known[$source]) && isset($healthy[$node])) {
                throw new InvalidInputException('Unknown log source.');
            }
            $selected[$node][] = $local;
        }

        return $selected;
    }

    /**
     * @param list<array<string, mixed>> $nodes
     * @param list<string> $selected
     * @return array{complete: bool, reasons: list<string>}
     */
    private static function selectedCoverage(array $nodes, array $selected): array
    {
        $reasons = [];
        foreach ($nodes as $node) {
            if (in_array($node['id'], $selected, true) && $node['status'] !== 'healthy') {
                $reasons[] = 'Node unavailable: '.$node['id'];
            }
        }

        return self::coverage($reasons);
    }

    private static function qualify(string $node, string $id): string
    {
        return rtrim(strtr(base64_encode(json_encode([$node, $id], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');
    }

    /** @return array{string,string} */
    private function unqualify(string $id): array
    {
        $decoded = json_decode(base64_decode(strtr($id, '-_', '+/'), true) ?: '', true);
        if (!is_array($decoded) || count($decoded) !== 2 || !is_string($decoded[0] ?? null) || !is_string($decoded[1] ?? null) || $decoded[1] === '' || !in_array($decoded[0], $this->client->nodes(), true)) {
            throw new InvalidInputException('Unknown log identifier.');
        }

        return [$decoded[0], $decoded[1]];
    }

    /**
     * @param array<string, mixed> $record
     * @return array<string, mixed>
     */
    private static function record(string $node, array $record): array
    {
        $record = array_intersect_key($record, array_flip(['id', 'sourceId', 'timestamp', 'level', 'channel', 'message', 'context', 'extra', 'raw', 'correlationId', 'node', 'service', 'stream', 'truncated', 'format']));
        $record['id'] = self::qualify($node, $record['id']);
        $record['sourceId'] = self::qualify($node, $record['sourceId']);
        $record['node'] = $node;

        return $record;
    }
}
