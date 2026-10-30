<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\LiveUpdates;

use App\Shared\Application\LiveUpdates\LiveFamily;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Mapping\UnderscoreNamingStrategy;
use PHPUnit\Framework\TestCase;

final class CaptureCoverageTest extends TestCase
{
    public function testEveryMappedAndMigrationTableHasAnExplicitDisposition(): void
    {
        $manifest = $this->manifest();
        $sources = array_column($manifest['sources'], null, 'table');
        self::assertCount(count($manifest['sources']), $sources, 'Duplicate source tables.');
        $tables = array_keys($this->mappedTables());
        foreach (glob($this->root() . '/migrations/*.php') as $file) {
            preg_match_all('/CREATE TABLE (\w+)\s*\(/i', file_get_contents($file), $matches);
            array_push($tables, ...$matches[1]);
        }
        $tables = array_values(array_unique($tables));
        sort($tables);
        $declared = array_keys($sources);
        sort($declared);
        self::assertSame($tables, $declared, 'Review new persistence sources and removed tables explicitly.');
    }

    public function testEveryMappedColumnIsRelevantOrExplicitlyExcluded(): void
    {
        $sources = array_column($this->manifest()['sources'], null, 'table');
        foreach ($this->mappedTables() as $table => $mapping) {
            $source = $sources[$table];
            if ($source['classification'] === 'excluded') {
                self::assertNotEmpty($source['reason'], $table);
                continue;
            }
            $declared = array_merge($source['relevant_columns'], $source['excluded_columns']);
            self::assertCount(count(array_unique($declared)), $declared, $table . ' has overlapping column dispositions.');
            sort($declared);
            $columns = $mapping['columns'];
            sort($columns);
            self::assertSame($columns, $declared, $table . ' mapping changed without capture review.');
            self::assertSame($mapping['file'], $source['mapping']);
        }
    }

    public function testCaptureContractsReferenceKnownFamiliesScopesAndReaders(): void
    {
        $manifest = $this->manifest();
        $families = array_map(static fn(LiveFamily $family): string => $family->value, LiveFamily::cases());
        $declared = $manifest['families'];
        sort($families);
        sort($declared);
        self::assertSame($families, $declared);
        foreach ($manifest['sources'] as $source) {
            self::assertFileExists($this->root() . '/' . $source['mapping']);
            self::assertContains($source['classification'], ['capture', 'excluded']);
            if ($source['classification'] === 'excluded') {
                self::assertNotEmpty($source['reason']);
                continue;
            }
            self::assertSame(['INSERT', 'UPDATE', 'DELETE'], $source['operations'], $source['table']);
            self::assertNotEmpty($source['relevant_columns'], $source['table']);
            self::assertNotEmpty($source['families'], $source['table']);
            self::assertSame([], array_values(array_diff($source['families'], $families)), $source['table']);
            self::assertNotEmpty($source['scope_routes'], $source['table']);
            foreach ($source['scope_routes'] as $route) {
                self::assertArrayHasKey($route, $manifest['scope_routes'], $source['table']);
                self::assertNotEmpty($manifest['scope_routes'][$route]['derivation']);
                self::assertNotEmpty($manifest['scope_routes'][$route]['audience']);
            }
            self::assertArrayHasKey($source['response_cache'], $manifest['response_caches']);
            self::assertNotEmpty($source['readers']);
            foreach ($source['readers'] as $reader) {
                self::assertFileExists($this->root() . '/' . $reader, $source['table']);
            }
        }
        foreach (array_merge($manifest['dependency_rules'], $manifest['sampled_sources']) as $dependency) {
            self::assertSame([], array_values(array_diff($dependency['families'], $families)));
            self::assertFileExists($this->root() . '/' . $dependency['reader']);
            foreach ($dependency['source_tables'] ?? [] as $table) {
                self::assertContains($table, array_column($manifest['sources'], 'table'));
            }
        }
    }

    public function testEveryParentCascadeHasAnExplicitCaptureStrategy(): void
    {
        $actual = [];
        foreach (glob($this->root() . '/migrations/*.php') as $file) {
            preg_match_all('/ALTER TABLE (?:ONLY )?(\w+)\s+ADD CONSTRAINT \w+ FOREIGN KEY\s*\((\w+)\) REFERENCES (\w+)\s*\(\w+\) ON DELETE (CASCADE|SET NULL)/i', file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $actual[] = $match[3] . ':' . $match[1] . ':' . $match[2] . ':' . $match[4];
            }
            preg_match_all('/CREATE TABLE (\w+)\s*\((.*?)\n\s*\)/is', file_get_contents($file), $tables, PREG_SET_ORDER);
            foreach ($tables as $table) {
                preg_match_all('/FOREIGN KEY\s*\((\w+)\) REFERENCES (\w+)\s*\(\w+\) ON DELETE (CASCADE|SET NULL)/i', $table[2], $foreignKeys, PREG_SET_ORDER);
                foreach ($foreignKeys as $foreignKey) {
                    $actual[] = $foreignKey[2] . ':' . $table[1] . ':' . $foreignKey[1] . ':' . $foreignKey[3];
                }
            }
        }
        $declared = [];
        $sources = array_column($this->manifest()['sources'], null, 'table');
        foreach ($this->manifest()['parent_cascades'] as $cascade) {
            self::assertArrayHasKey($cascade['parent'], $sources);
            self::assertArrayHasKey($cascade['child'], $sources);
            self::assertContains($cascade['capture'], ['before-parent-delete', 'child-row-or-exempt']);
            $declared[] = $cascade['parent'] . ':' . $cascade['child'] . ':' . $cascade['foreign_key'] . ':' . $cascade['action'];
        }
        sort($actual);
        sort($declared);
        self::assertSame($actual, $declared, 'Foreign-key changes require a parent/child capture review.');
    }

    public function testMigrationOnlyCapturedTablesDeclareTheirColumns(): void
    {
        $mapped = $this->mappedTables();
        foreach ($this->manifest()['sources'] as $source) {
            if ($source['classification'] !== 'capture' || isset($mapped[$source['table']])) {
                continue;
            }
            $migration = file_get_contents($this->root() . '/' . $source['mapping']);
            self::assertSame(1, preg_match('/CREATE TABLE ' . preg_quote($source['table'], '/') . '\s*\((.*?)\n\s*\)/is', $migration, $table));
            preg_match_all('/^\s*"?(\w+)"?\s+(?:uuid|text|bigint|integer|int|boolean|timestamp|timestamptz|jsonb|double|smallint)\b/im', $table[1], $columns);
            $declared = array_merge($source['relevant_columns'], $source['excluded_columns']);
            sort($declared);
            sort($columns[1]);
            self::assertSame($columns[1], $declared, $source['table']);
        }
    }

    public function testPrivateAndSecretSourcesCannotBecomeGlobalCapture(): void
    {
        $sources = array_column($this->manifest()['sources'], null, 'table');
        foreach (['media_activities', 'notifications', 'audio_preferences', 'player_preferences', 'layout_preferences', 'preference_history'] as $table) {
            self::assertSame(['user'], $sources[$table]['scope_routes'], $table);
        }
        foreach (['domain_event_outbox', 'domain_event_outbox_delivery', 'domain_event_outbox_receipt', 'password_reset_tokens', 'email_verification_tokens', 'oauth_auth_codes', 'transcode_control_receipts'] as $table) {
            self::assertSame('excluded', $sources[$table]['classification'], $table);
        }
        foreach (['users' => ['password', 'totp_secret'], 'oauth_clients' => ['secret_hash'], 'webhooks' => ['encrypted_secret'], 'push_subscriptions' => ['public_key', 'auth_key'], 'libraries' => ['claim_expires_at'], 'oauth_device_codes' => ['last_polled_at'], 'devices' => ['last_seen_at']] as $table => $columns) {
            foreach ($columns as $column) {
                self::assertContains($column, $sources[$table]['excluded_columns'], $table);
            }
        }
    }

    /** @return array<string, mixed> */
    private function manifest(): array
    {
        return file_get_contents($this->root() . '/tests/Fixtures/LiveUpdates/capture-sources.json')
            |> (static fn(string $json): array => json_decode($json, true, flags: JSON_THROW_ON_ERROR));
    }

    /** @return array<string, array{file: string, columns: list<string>}> */
    private function mappedTables(): array
    {
        $tables = [];
        $naming = new UnderscoreNamingStrategy();
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root() . '/src'));
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $source = file_get_contents($file->getPathname());
            if (!str_contains($source, '#[ORM\\Table(')) {
                continue;
            }
            preg_match('/namespace ([^;]+);/', $source, $namespace);
            preg_match('/(?:final )?(?:readonly )?class (\w+)/', $source, $class);
            $reflection = new \ReflectionClass($namespace[1] . '\\' . $class[1]);
            $table = $reflection->getAttributes(ORM\Table::class)[0]->newInstance()->name;
            $columns = [];
            foreach ($reflection->getProperties() as $property) {
                foreach ($property->getAttributes(ORM\Column::class) as $attribute) {
                    $columns[] = $attribute->newInstance()->name ?? $naming->propertyToColumnName($property->getName(), $reflection->getName());
                }
                foreach ($property->getAttributes(ORM\JoinColumn::class) as $attribute) {
                    $columns[] = $attribute->newInstance()->name;
                }
            }
            $tables[$table] = ['file' => substr($file->getPathname(), strlen($this->root()) + 1), 'columns' => $columns];
        }

        return $tables;
    }

    private function root(): string
    {
        return dirname(__DIR__, 4);
    }
}
