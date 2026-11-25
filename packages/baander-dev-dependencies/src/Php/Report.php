<?php

declare(strict_types=1);

namespace Baander\DevDependencies\Php;

final class Report
{
    /** @var list<array<string,mixed>> */
    private array $records = [];

    public function __construct(public readonly bool $localOnly, public readonly bool $advisoriesOnly = false, public readonly bool $imageDrift = false) {}

    /** @param array<string,mixed> $details
     */
    public function add(string $status, string $project, string $kind, string $source, string $reason, array $details = []): void
    {
        if (!in_array($status, ['evidence', 'finding', 'gap'], true)) {
            throw new \InvalidArgumentException('Unsupported dependency evidence status.');
        }
        $this->records[] = ['status' => $status, 'project' => $project, 'kind' => $kind, 'source' => $source, 'reason' => $reason, 'details' => $details];
    }

    public function exitCode(): int
    {
        $statuses = array_column($this->records, 'status');
        return in_array('gap', $statuses, true) ? 2 : (in_array('finding', $statuses, true) ? 1 : 0);
    }

    /** @return array<string,mixed>
     */
    public function toArray(): array
    {
        $records = $this->records;
        usort($records, static fn(array $a, array $b): int => [$a['project'], $a['kind'], $a['source'], $a['reason']] <=> [$b['project'], $b['kind'], $b['source'], $b['reason']]);
        [$mode, $requested, $excluded] = match (true) {
            $this->imageDrift => ['image-drift', ['inventory', 'build-inputs', 'image-drift'], ['locks', 'runtime-declarations', 'patches', 'composer-validation', 'online-currency', 'advisories', 'composer-image-php', 'upstream-references', 'installed-runtime', 'native-behavior']],
            $this->advisoriesOnly => ['advisories-only', ['inventory', 'locks', 'advisories'], ['runtime-declarations', 'build-inputs', 'patches', 'composer-validation', 'online-currency', 'composer-image-php', 'image-drift', 'upstream-references', 'installed-runtime', 'native-behavior']],
            $this->localOnly => ['local-only', ['inventory', 'locks', 'runtime-declarations', 'build-inputs', 'patches', 'composer-validation'], ['online-currency', 'advisories', 'composer-image-php', 'image-drift', 'upstream-references', 'installed-runtime', 'native-behavior']],
            default => ['online', ['inventory', 'locks', 'runtime-declarations', 'build-inputs', 'patches', 'composer-validation', 'stable-releases', 'advisories', 'outdated', 'upstream-references', 'composer-image-php'], ['image-drift', 'installed-runtime', 'native-behavior']],
        };
        return [
            'mode' => $mode,
            'exitCode' => $this->exitCode(),
            'requested' => $requested,
            'excluded' => $excluded,
            'records' => $records,
        ];
    }

    public function render(bool $json = false): string
    {
        $data = $this->toArray();
        if ($json) {
            return json_encode(self::redact($data), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        }
        $lines = ['PHP dependencies: ' . $data['mode'] . '; status ' . $this->exitCode(), 'Excluded: ' . implode(', ', $data['excluded'])];
        foreach ($data['records'] as $record) {
            $line = sprintf('[%s] %s %s: %s (%s)', $record['status'], $record['project'], $record['kind'], $record['reason'], $record['source']);
            $lines[] = self::redact($line);
            $details = match ($record['kind']) {
                'lock' => ['lockedPackages' => count($record['details']['packages'] ?? [])],
                'manifest' => ['kind' => $record['details']['kind'] ?? null, 'directRequirements' => count($record['details']['requirements'] ?? [])],
                default => $record['details'],
            };
            if ($details !== []) {
                $lines[] = '  details: ' . json_encode(self::redact($details), JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
            }
        }
        return implode("\n", $lines) . "\n";
    }
    private static function redact(mixed $value): mixed
    {
        if (is_array($value)) {
            $result = [];
            foreach ($value as $key => $item) {
                $result[$key] = is_string($key) && preg_match('/auth|password|token|secret|credential|api.?key/i', $key) === 1 ? '[redacted]' : self::redact($item);
            }
            return $result;
        }
        if (is_string($value)) {
            return $value
                |> (static fn(string $text): string => preg_replace('~(https?://)[^/\s@]+@~i', '$1[redacted]@', $text) ?? $text)
                |> (static fn(string $text): string => preg_replace('/([?&](?:token|password|secret|api.?key|access_token)=)[^&\s]+/i', '$1[redacted]', $text) ?? $text);
        }
        return $value;
    }

}
