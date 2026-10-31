<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

require __DIR__ . '/bootstrap.php';

Runtime::main(static function (): void {
    $source = <<<'PHP'
declare(strict_types=1);

namespace Baander\RegistryTests;

// This standalone process substitutes status responses before the real Cluster is autoloaded.
final class Cluster
{
    public int $count = 3;
    public int $polls = 0;
    public int $rowRequests = 0;

    /** @param list<array{statuses:array<int,array{int,array<mixed>}>,row?:array<string,mixed>}> $frames */
    public function __construct(private array $frames) {}

    /**
     * @param array<mixed>|null $statements
     * @return array{int,array<mixed>}
     */
    public function request(int $index, string $target, ?array $statements = null): array
    {
        if ($target === '/status' && $index === 0) {
            ++$this->polls;
        }
        $frame = $this->frames[min($this->polls - 1, count($this->frames) - 1)];
        if ($target === '/status') {
            return $frame['statuses'][$index];
        }
        ++$this->rowRequests;
        return [200, ['results' => [[
            'types' => [
                'public_id' => 'text', 'credential_digest' => 'text', 'url' => 'text',
                'name' => 'text', 'version' => 'text', 'created_ms' => 'integer',
                'updated_ms' => 'integer', 'last_seen_ms' => 'integer', 'revision' => 'integer',
            ],
            'rows' => [$frame['row']],
        ]]]];
    }
}

require $argv[1] . '/bootstrap.php';

Runtime::main(static function (): void {
    $status = static fn(mixed $leader = 'node-1', mixed $applied = 20): array => [
        200, ['store' => ['leader' => ['node_id' => $leader], 'db_applied_index' => $applied]],
    ];
    $valid = ['statuses' => [$status(), $status()]];
    $retryCases = [
        'null leader identity' => $status(null),
        'missing leader' => [200, ['store' => ['db_applied_index' => 20]]],
        'null leader' => [200, ['store' => ['leader' => null, 'db_applied_index' => 20]]],
        'missing leader identity' => [200, ['store' => ['leader' => [], 'db_applied_index' => 20]]],
        'empty leader identity' => $status(''),
        'integer leader identity' => $status(1),
        'array leader identity' => $status([]),
        'wrong leader prefix' => $status('other-1'),
        'negative leader index' => $status('node--1'),
        'noncanonical leader index' => $status('node-01'),
        'unknown leader index' => $status('node-3'),
        'overflowing leader index' => $status('node-' . str_repeat('9', 30)),
        'unavailable status' => [503, $status()[1]],
        'missing store' => [200, []],
        'invalid store' => [200, ['store' => 'unavailable']],
        'missing applied index' => [200, ['store' => ['leader' => ['node_id' => 'node-1']]]],
        'noninteger applied index' => $status('node-1', '20'),
        'applied index lag' => $status('node-1', 19),
    ];
    foreach ($retryCases as $label => $invalid) {
        $cluster = new Cluster([['statuses' => [$invalid, $invalid]], $valid]);
        $leader = ClusterContract::waitConverged($cluster, [0, 1], 20);
        Runtime::requireThat($leader === 1 && $cluster->polls === 2, "{$label}: accepted incomplete voter convergence");
    }
    $cluster = new Cluster([['statuses' => [$status('node-0'), $status('node-1')]], $valid]);
    Runtime::requireThat(
        ClusterContract::waitConverged($cluster, [0, 1], 20) === 1 && $cluster->polls === 2,
        'Different leaders satisfied voter convergence.',
    );
    foreach ([0, 2] as $leader) {
        $cluster = new Cluster([['statuses' => [$status("node-{$leader}"), $status("node-{$leader}")]]]);
        Runtime::requireThat(
            ClusterContract::waitConverged($cluster, [0, 1], 20) === $leader && $cluster->polls === 1,
            'A valid cluster leader was rejected.',
        );
    }
    $row = [
        'public_id' => 'convergence', 'credential_digest' => str_repeat('a', 64),
        'url' => 'https://registry.baander.app', 'name' => 'Convergence', 'version' => '1.0.0',
        'created_ms' => 1, 'updated_ms' => 2, 'last_seen_ms' => 3, 'revision' => 1,
    ];
    $cluster = new Cluster([
        $valid + ['row' => array_replace($row, ['revision' => 0])],
        $valid + ['row' => $row],
    ]);
    Runtime::requireThat(
        ClusterContract::waitConverged($cluster, [0, 1], 20, ['convergence' => $row]) === 1
            && $cluster->polls === 2 && $cluster->rowRequests === 3,
        'Voter convergence did not prove acknowledged rows on every voter.',
    );
    echo "PASS: voter convergence rejects incomplete status and proves leader, applied index and rows\n";
});
PHP;
    $result = Runtime::run([PHP_BINARY, '-r', $source, __DIR__], timeout: 10);
    Runtime::requireThat(
        $result['code'] === 0 && str_contains($result['stdout'], 'PASS: voter convergence'),
        "Voter convergence fixture failed ({$result['code']}):\n{$result['stdout']}{$result['stderr']}",
    );
    echo $result['stdout'];
});
