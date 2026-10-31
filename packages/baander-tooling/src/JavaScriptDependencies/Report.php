<?php

declare(strict_types=1);

namespace Baander\Tooling\JavaScriptDependencies;

final class Report
{
    /** @var list<array<string,mixed>> */
    private array $records = [];

    public function __construct(public readonly bool $localOnly) {}

    /** @param array<string,mixed> $details */
    public function add(string $status, string $project, string $kind, string $reason, array $details = []): void
    {
        $this->records[] = compact('status', 'project', 'kind', 'reason', 'details');
    }

    public function exitCode(): int
    {
        $statuses = array_column($this->records, 'status');
        return in_array('gap', $statuses, true) ? 2 : (in_array('finding', $statuses, true) ? 1 : 0);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['mode' => $this->localOnly ? 'local-only' : 'online', 'exitCode' => $this->exitCode(), 'records' => $this->records];
    }

    public function render(bool $json = false): string
    {
        if ($json) {
            return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
        $lines = ['JavaScript dependencies: ' . ($this->localOnly ? 'local-only' : 'online') . '; status ' . $this->exitCode()];
        foreach ($this->records as $record) {
            $lines[] = sprintf('[%s] %s %s: %s', $record['status'], $record['project'], $record['kind'], $record['reason']);
            if ($record['details'] !== []) {
                $lines[] = '  ' . json_encode($record['details'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }
        return implode("\n", $lines) . "\n";
    }
}
