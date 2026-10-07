<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Application;

use App\Activity\Application\Port\ActivityPortInterface;
use App\Catalog\Domain\Repository\SongRepositoryInterface;
use App\Recommendation\Application\Command\GenerateRecommendationsCommand;
use App\Recommendation\Application\CommandHandler\GenerateRecommendationsHandler;
use App\Recommendation\Application\Port\RecommendationJobPortInterface;
use App\Recommendation\Application\Settings\RecommendationSettingDefinitions;
use App\Recommendation\Domain\Service\CollaborativeFilteringCalculator;
use App\Recommendation\Domain\Service\ContentSimilarityCalculator;
use App\Recommendation\Domain\Service\GenreSimilarityCalculator;
use App\Scheduler\Domain\Model\SchedulableCommandInterface;
use App\Shared\Application\Port\SystemSettingsPortInterface;
use App\Shared\Infrastructure\Swoole\ProcessPool\CpuProcessPool;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Serializer\Encoder\JsonEncoder;

/**
 * The daily scheduler job builds the command with automatic: true; only that run reads
 * recommendations.auto_generate. The CLI and admin action build it without the flag.
 */
final class ScheduledGenerationTest extends TestCase
{
    private InfoLineLogger $logger;

    public function testCommandIsSchedulableWithModeAndAutomaticParameters(): void
    {
        self::assertContains(SchedulableCommandInterface::class, class_implements(GenerateRecommendationsCommand::class));
        self::assertSame(
            [
                'mode' => ['type' => 'string', 'required' => false, 'default' => 'full'],
                'automatic' => ['type' => 'bool', 'required' => false, 'default' => false],
            ],
            array_map(
                static fn (array $definition): array => array_diff_key($definition, ['description' => true]),
                GenerateRecommendationsCommand::schedulerParameters(),
            ),
        );

        // The scheduler spreads the stored job parameters into the constructor by name.
        $scheduled = new GenerateRecommendationsCommand(...['mode' => 'full', 'automatic' => true]);
        self::assertTrue($scheduled->isAutomatic());
        self::assertTrue($scheduled->isFull());

        // The registry instantiates schedulable commands through the container, without arguments.
        $default = new GenerateRecommendationsCommand();
        self::assertFalse($default->isAutomatic());
        self::assertTrue($default->isFull());
    }

    public function testScheduledRunWithTheToggleOffRecordsASkipAndGeneratesNothing(): void
    {
        $songs = $this->createMock(SongRepositoryInterface::class);
        $songs->expects(self::never())->method('findAllForRecommendations');
        $songs->expects(self::never())->method('findUpdatedAfter');
        $jobs = $this->createMock(RecommendationJobPortInterface::class);
        $jobs->expects(self::never())->method('create');

        $result = $this->handler($songs, $this->settings(false), $jobs)(
            new GenerateRecommendationsCommand(mode: GenerateRecommendationsCommand::MODE_FULL, automatic: true),
        );

        self::assertSame(GenerateRecommendationsHandler::SKIPPED_AUTO_GENERATE_OFF, $result);
        self::assertCount(1, $this->logger->infoLines);
        self::assertStringContainsString(RecommendationSettingDefinitions::AUTO_GENERATE, $this->logger->infoLines[0]);
    }

    public function testScheduledRunWithTheToggleOnGenerates(): void
    {
        $songs = $this->createMock(SongRepositoryInterface::class);
        $songs->expects(self::once())->method('findAllForRecommendations')->willReturn([]);

        $result = $this->handler($songs, $this->settings(true))(
            new GenerateRecommendationsCommand(mode: GenerateRecommendationsCommand::MODE_FULL, automatic: true),
        );

        self::assertSame(['collaborative' => 0, 'content' => 0, 'genre' => 0], $result);
        self::assertSame([], $this->logger->infoLines);
    }

    public function testManualRunGeneratesWithoutReadingTheToggle(): void
    {
        $songs = $this->createMock(SongRepositoryInterface::class);
        $songs->expects(self::once())->method('findAllForRecommendations')->willReturn([]);
        $settings = $this->createMock(SystemSettingsPortInterface::class);
        $settings->expects(self::never())->method('get');

        $result = $this->handler($songs, $settings)(new GenerateRecommendationsCommand(mode: GenerateRecommendationsCommand::MODE_FULL));

        self::assertSame(['collaborative' => 0, 'content' => 0, 'genre' => 0], $result);
    }

    public function testUnknownModeIsRejectedInsteadOfRunningIncrementally(): void
    {
        $songs = $this->createMock(SongRepositoryInterface::class);
        $songs->expects(self::never())->method('findUpdatedAfter');

        $this->expectException(\InvalidArgumentException::class);
        $this->handler($songs, $this->createStub(SystemSettingsPortInterface::class))(new GenerateRecommendationsCommand(mode: 'Full', automatic: true));
    }

    private function settings(bool $autoGenerate): SystemSettingsPortInterface
    {
        $settings = $this->createMock(SystemSettingsPortInterface::class);
        $settings->expects(self::once())->method('get')->with(RecommendationSettingDefinitions::AUTO_GENERATE)->willReturn($autoGenerate);

        return $settings;
    }

    private function handler(
        SongRepositoryInterface $songs,
        SystemSettingsPortInterface $settings,
        ?RecommendationJobPortInterface $jobs = null,
    ): GenerateRecommendationsHandler {
        $this->logger = new InfoLineLogger();

        $activity = $this->createStub(ActivityPortInterface::class);
        $activity->method('getAllListeningHistories')->willReturn([]);

        return new GenerateRecommendationsHandler(
            $this->createStub(MessageBusInterface::class),
            $songs,
            $activity,
            new CollaborativeFilteringCalculator(),
            new ContentSimilarityCalculator(),
            new GenreSimilarityCalculator(),
            $jobs ?? $this->createStub(RecommendationJobPortInterface::class),
            // Never started, so isRunning() is false and generation runs synchronously.
            new CpuProcessPool([], 1, new NullLogger()),
            new JsonEncoder(),
            'postgresql://baander.app/unused',
            $settings,
            $this->logger,
        );
    }
}

final class InfoLineLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $infoLines = [];

    /** @param mixed[] $context */
    public function log(mixed $level, \Stringable|string $message, array $context = []): void
    {
        if ($level === 'info') {
            $this->infoLines[] = (string) $message;
        }
    }
}
