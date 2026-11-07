<?php

declare(strict_types=1);

namespace Baander\Tooling\Tests;

use Baander\Tooling\Fixtures\Qualification\Dataset;
use Baander\Tooling\Fixtures\Runtime;
use Baander\Tooling\Docker\{Client, Target};
use Baander\Tooling\Inventory\Collector;
use PHPUnit\Framework\TestCase;

final class QualificationDatasetTest extends TestCase
{
    public function testPlanOwnsDistinctPathsAndMetadataForAllRequiredRecords(): void
    {
        $plan = Dataset::plan('qualification-one');
        self::assertSame($plan, Dataset::plan('qualification-one'));
        self::assertCount(2300, $plan['files']);
        self::assertSame(['song' => 2000, 'movie' => 50, 'episode' => 250], array_count_values(array_column($plan['files'], 'kind')));
        self::assertCount(2300, array_unique(array_column($plan['files'], 'path')));
        self::assertCount(2300, array_unique(array_column($plan['files'], 'title')));
        $episodes = array_filter($plan['files'], static fn(array $file): bool => $file['kind'] === 'episode');
        self::assertCount(250, array_unique(array_map(static fn(array $file): string => dirname($file['path']), $episodes)));
        self::assertSame('movie', $plan['libraries']['episodes']['type']);
        self::assertSame(5, count(array_filter($plan['files'], static fn(array $file): bool => $file['duration'] >= 1860)));
        self::assertSame(2, count($plan['playback']['originalVideos']));
        self::assertSame(2, count($plan['playback']['copyVideos']));
        foreach ($plan['files'] as $file) {
            self::assertStringStartsWith('qualification-one/', $file['path']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{8}-(?:[a-f0-9]{4}-){3}[a-f0-9]{12}$/D', $file['metadata']['id']);
        }
    }

    public function testPreparationReadsTheCompletedProcessLogWhenOutputIsRedirected(): void
    {
        $directory = sys_get_temp_dir() . '/qualification-dataset-log-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        copy(__DIR__ . '/Fixtures/development-lifecycle-docker.php', $directory . '/docker');
        chmod($directory . '/docker', 0700);
        $expected = Dataset::plan('qualification-one');
        file_put_contents($directory . '/daemon.json', json_encode(['stdout' => json_encode($expected, JSON_THROW_ON_ERROR)], JSON_THROW_ON_ERROR));
        $runtime = new Runtime(dirname(__DIR__, 3), 30);
        $docker = new Client($runtime->executor, new Target($directory . '/docker', 'unix:///baander.app/fixture.sock', 'owned-daemon', null, null), $runtime->environment);
        try {
            $actual = Dataset::prepare($docker, $runtime, 'sha256:' . str_repeat('a', 64), 'owned-source', $directory, 'qualification-one');
            self::assertSame($expected, $actual);
            self::assertSame($expected, json_decode(file_get_contents($directory . '/dataset.json'), true, flags: JSON_THROW_ON_ERROR));
            self::assertFileDoesNotExist($directory . '/dataset-container.json');
            self::assertSame([], glob($directory . '/resource-*.json'));
        } finally {
            $runtime->close();
            Collector::remove($directory);
        }
    }

    public function testPreparationProgramRetainsUniqueTagsAndCollectsActualProbeAndHashEvidence(): void
    {
        $program = Dataset::containerProgram(Dataset::plan('qualification-one'));
        self::assertStringContainsString('hash_file', $program);
        self::assertStringContainsString('ffprobe', $program);
        self::assertStringContainsString("'-c', 'copy'", $program);
        self::assertStringContainsString('file_exists', $program);
        self::assertStringContainsString('Duplicate source hash', $program);
        self::assertStringContainsString('output_ts_offset', $program);
    }

    public function testPlanRejectsAnUnsafeRunName(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Dataset::plan('../other');
    }
}
