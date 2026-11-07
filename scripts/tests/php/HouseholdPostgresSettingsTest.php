<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Deployment\{Pooler, PostgresConfiguration, PostgresSettings};
use PHPUnit\Framework\TestCase;

final class HouseholdPostgresSettingsTest extends TestCase
{
    public function testRemuxAndDefaultTranscodeBudgetsRemainSeparate(): void
    {
        $remux = $this->household();
        self::assertSame('21', $remux->expectedSettings()['max_connections']);
        self::assertSame('128MB', $remux->expectedSettings()['shared_buffers']);
        $transcode = new PostgresSettings(file_get_contents(dirname(__DIR__, 3) . '/docker/postgres/conf/postgresql.conf'));
        self::assertSame('35', $transcode->expectedSettings()['max_connections']);
        self::assertSame('256MB', $transcode->expectedSettings()['shared_buffers']);
    }

    public function testActualPostgresUnitsAndExtensionSettingsAreAccepted(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['settings']['shared_buffers']['setting'] = '16384';
        $snapshot['settings']['shared_buffers']['unit'] = '8kB';
        $snapshot['settings']['work_mem']['setting'] = '4096';
        $snapshot['settings']['work_mem']['unit'] = 'kB';
        self::assertSame([], $this->household()->verify($snapshot, '/etc/baander/postgresql.conf'));
    }

    public function testOverridesRestartErrorsAndMissingExtensionsFailVerification(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['settings']['max_connections']['setting'] = '100';
        $snapshot['settings']['work_mem']['sourcefile'] = '/data/postgresql.auto.conf';
        $snapshot['settings']['shared_buffers']['pending_restart'] = true;
        $snapshot['file_errors'] = [['sourcefile' => '/etc/baander/postgresql.conf', 'sourceline' => 3, 'error' => 'invalid value']];
        unset($snapshot['extensions']['pgroonga']);
        $snapshot['statistics_usable'] = false;
        $errors = implode("\n", $this->household()->verify($snapshot, '/etc/baander/postgresql.conf'));
        foreach (['max_connections', 'work_mem', 'shared_buffers', 'configuration errors', 'pgroonga', 'statistics'] as $failure) {
            self::assertStringContainsString($failure, $errors);
        }
    }

    public function testMissingEvidenceWrongConfigAndWrongServerFailVerification(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['config_file'] = '/data/postgresql.conf';
        $snapshot['server_version'] = 170000;
        unset($snapshot['settings']['autovacuum']);
        $errors = implode("\n", $this->household()->verify($snapshot, '/etc/baander/postgresql.conf'));
        self::assertStringContainsString('config_file', $errors);
        self::assertStringContainsString('PostgreSQL 18', $errors);
        self::assertStringContainsString('autovacuum', $errors);
    }

    public function testMalformedOrRepeatedConfigurationIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PostgresSettings("work_mem = 4MB\nwork_mem = 8MB\n");
    }

    public function testConfigurationIncludesCannotHideAdditionalSettings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PostgresSettings("include '/data/overrides.conf'\n");
    }

    public function testMissingPreloadExtensionAndSessionOverrideAreRejected(): void
    {
        $snapshot = $this->snapshot();
        unset($snapshot['extensions']['pg_stat_statements']);
        $snapshot['settings']['work_mem']['sourcefile'] = null;
        $errors = implode("\n", $this->household()->verify($snapshot, '/etc/baander/postgresql.conf'));
        self::assertStringContainsString('pg_stat_statements', $errors);
        self::assertStringContainsString('work_mem', $errors);
    }

    public function testTimeZonePinnedOnTheDatabaseIsAcceptedWhileOtherDatabaseOverridesAreNot(): void
    {
        $snapshot = $this->snapshot();
        $snapshot['settings']['timezone'] = ['setting' => 'UTC', 'unit' => null, 'source' => 'database', 'sourcefile' => null, 'pending_restart' => false];
        self::assertSame([], $this->household()->verify($snapshot, '/etc/baander/postgresql.conf'));
        $snapshot['settings']['timezone']['setting'] = 'Europe/Copenhagen';
        self::assertStringContainsString('timezone', implode("\n", $this->household()->verify($snapshot, '/etc/baander/postgresql.conf')));
        $snapshot = $this->snapshot();
        $snapshot['settings']['work_mem'] = ['setting' => '4MB', 'unit' => null, 'source' => 'database', 'sourcefile' => null, 'pending_restart' => false];
        self::assertStringContainsString('work_mem', implode("\n", $this->household()->verify($snapshot, '/etc/baander/postgresql.conf')));
    }

    public function testVerificationSqlMatchesCatalogNamesWithoutRegardToCase(): void
    {
        self::assertStringContainsString('lower(s.name) = r.name', $this->household()->verificationSql());
    }

    public function testVerificationSqlCapturesSettingsAndExercisesExtensionsWithoutCredentials(): void
    {
        $sql = $this->household()->verificationSql();
        foreach (['pg_settings', 'pg_file_settings', 'pg_extension', 'pg_stat_statements', "pgroonga_command('status')", 'current_setting', 'BEGIN READ ONLY', 'COMMIT'] as $fragment) {
            self::assertStringContainsString($fragment, $sql);
        }
        self::assertStringNotContainsString('PASSWORD', $sql);
    }

    /** The rendered configuration of a remux deployment with two web workers and two copy slots. */
    private function household(): PostgresSettings
    {
        $pools = Pooler::pools(['webWorkers' => 2, 'sharedCopySlots' => 2, 'videoEncoderSlots' => 0, 'audioEncoderSlots' => 0]);
        return new PostgresSettings(PostgresConfiguration::render(128, 4, $pools));
    }

    /** @return array<string,mixed> */
    private function snapshot(): array
    {
        $settings = [];
        foreach ($this->household()->expectedSettings() as $name => $value) {
            $settings[$name] = ['setting' => $value, 'unit' => null, 'sourcefile' => '/etc/baander/postgresql.conf', 'pending_restart' => false];
        }
        return ['config_file' => '/etc/baander/postgresql.conf', 'server_version' => 180004, 'settings' => $settings,
            'file_errors' => [], 'extensions' => ['pgroonga' => '4.0.5', 'pg_stat_statements' => '1.12'], 'statistics_usable' => true];
    }
}
