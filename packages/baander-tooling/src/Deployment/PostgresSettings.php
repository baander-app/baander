<?php

declare(strict_types=1);

namespace Baander\Tooling\Deployment;

use InvalidArgumentException;

final class PostgresSettings
{
    /** @var array<string,string> */
    private array $settings = [];

    public function __construct(string $configuration)
    {
        foreach (explode("\n", $configuration) as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match("/^([a-z][a-z0-9_.]*)\\s*=\\s*(?:'([^']*)'|([a-zA-Z0-9.*+-]+))$/D", $line, $match) !== 1) {
                throw new InvalidArgumentException('Unsupported PostgreSQL configuration assignment.');
            }
            $name = $match[1];
            if (array_key_exists($name, $this->settings)) {
                throw new InvalidArgumentException('Repeated PostgreSQL setting: ' . $name);
            }
            $this->settings[$name] = $match[3] ?? $match[2];
        }
        if ($this->settings === []) {
            throw new InvalidArgumentException('PostgreSQL configuration has no assignments.');
        }
    }

    /** @return array<string,string> */
    public function expectedSettings(): array
    {
        return $this->settings;
    }

    public function verificationSql(): string
    {
        $names = implode(', ', array_map(static fn(string $name): string => "('" . $name . "')", array_keys($this->settings)));
        return <<<SQL
BEGIN READ ONLY;
SET LOCAL statement_timeout = '15s';
SET LOCAL lock_timeout = '3s';
-- Load PGroonga so its custom settings are registered in this observer session.
SELECT pgroonga_command('status') IS NOT NULL;
WITH requested(name) AS (VALUES {$names})
SELECT json_build_object(
 'server_version', current_setting('server_version_num')::int,
 'config_file', current_setting('config_file'),
 'settings', (SELECT json_object_agg(r.name, json_build_object(
   'setting', coalesce(s.setting, current_setting(r.name, true)),
   'unit', s.unit, 'sourcefile', s.sourcefile,
   'pending_restart', s.pending_restart))
   FROM requested r LEFT JOIN pg_settings s USING (name)),
 'file_errors', (SELECT coalesce(json_agg(json_build_object(
   'sourcefile', sourcefile, 'sourceline', sourceline, 'error', error)), '[]'::json)
   FROM pg_file_settings WHERE error IS NOT NULL),
 'extensions', (SELECT coalesce(json_object_agg(extname, extversion), '{}'::json) FROM pg_extension),
 'statistics_usable', (SELECT count(*) >= 0 FROM pg_stat_statements)
);
COMMIT;
SQL;
    }

    /**
     * @param array<string,mixed> $snapshot
     * @return list<string>
     */
    public function verify(array $snapshot, string $expectedConfigPath): array
    {
        $errors = [];
        if (($snapshot['config_file'] ?? null) !== $expectedConfigPath) {
            $errors[] = 'PostgreSQL config_file does not select the deployment configuration.';
        }
        $version = $snapshot['server_version'] ?? 0;
        if (!is_int($version) || $version < 180000 || $version >= 190000) {
            $errors[] = 'Expected PostgreSQL 18.';
        }
        if (($snapshot['file_errors'] ?? null) !== []) {
            $errors[] = 'PostgreSQL configuration errors are present or their evidence is missing.';
        }
        foreach (['pgroonga', 'pg_stat_statements'] as $extension) {
            if (!is_string($snapshot['extensions'][$extension] ?? null) || $snapshot['extensions'][$extension] === '') {
                $errors[] = 'Required PostgreSQL extension is missing: ' . $extension;
            }
        }
        if (($snapshot['statistics_usable'] ?? null) !== true) {
            $errors[] = 'PostgreSQL statement statistics are not usable.';
        }
        foreach ($this->settings as $name => $expected) {
            $actual = $snapshot['settings'][$name] ?? null;
            if (!is_array($actual) || !is_string($actual['setting'] ?? null)) {
                $errors[] = 'Missing effective PostgreSQL setting: ' . $name;
                continue;
            }
            $unit = $actual['unit'] ?? '';
            if (!is_string($unit) || self::normalize($actual['setting'], $unit) !== self::normalize($expected)) {
                $errors[] = 'Unexpected effective PostgreSQL setting: ' . $name;
            }
            if (($actual['sourcefile'] ?? null) !== $expectedConfigPath) {
                $errors[] = 'PostgreSQL setting does not originate in the deployment configuration: ' . $name;
            }
            if (($actual['pending_restart'] ?? null) !== false) {
                $errors[] = 'PostgreSQL setting requires restart or lacks restart evidence: ' . $name;
            }
        }
        return $errors;
    }

    private static function normalize(string $value, string $unit = ''): string
    {
        if ($unit === '8kB' && ctype_digit($value)) {
            return (string) ((int) $value * 8192);
        }
        if (preg_match('/^([0-9]+)(B|kB|MB|GB|TB)$/D', $value . $unit, $match) === 1) {
            $multiplier = match ($match[2]) {
                'B' => 1,
                'kB' => 1024,
                'MB' => 1024 ** 2,
                'GB' => 1024 ** 3,
                'TB' => 1024 ** 4,
            };
            return (string) ((int) $match[1] * $multiplier);
        }
        return $value;
    }
}
