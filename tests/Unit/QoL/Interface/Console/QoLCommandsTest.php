<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL\Interface\Console;

use App\QoL\Application\Port\QoLAdminPortInterface;
use App\QoL\Domain\Model\GovernorState;
use App\QoL\Domain\Service\LearningModel;
use App\QoL\Domain\ValueObject\AlgorithmProfile;
use App\QoL\Domain\ValueObject\UtilizationSample;
use App\QoL\Infrastructure\QoLAdminService;
use App\QoL\Interface\Console\QoLProfileCommand;
use App\QoL\Interface\Console\QoLResetCommand;
use App\QoL\Interface\Console\QoLStatusCommand;
use App\QoL\Interface\Console\QoLStreamsCommand;
use App\Shared\Application\Port\ServerControlPortInterface;
use App\Shared\Application\Port\ServerNotRunningException;
use App\Shared\Domain\Model\Uuid;
use App\Tests\Unit\QoL\QoLWorkers;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class QoLCommandsTest extends TestCase
{
    private string $directory;
    private QoLWorkers $workers;
    private QoLAdminService $qol;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/baander-qol-commands-' . bin2hex(random_bytes(8));
        $this->workers = new QoLWorkers($this->directory);
        $this->qol = new QoLAdminService($this->workers);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testStatusPrintsOneRowPerWorkerAndATotal(): void
    {
        $this->workers->governors[1]->allocateStream(new Uuid(), '1080p', 30.0);
        $tester = new CommandTester(new QoLStatusCommand($this->qol));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^\s*0\s+learning\s+balanced\s+0\s+0\s+no\s+80%/m', $display);
        self::assertMatchesRegularExpression('/^\s*1\s+learning\s+balanced\s+1\s+0\s+no\s+80%/m', $display);
        self::assertMatchesRegularExpression('/^\s*Total\s+1\s*$/m', $display);
    }

    public function testStatusJsonIsTheApiDataPayload(): void
    {
        $tester = new CommandTester(new QoLStatusCommand($this->qol));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        self::assertSame(self::asJson($this->qol->getStatus()), json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testStatusNamesAWorkerThatDidNotAnswerAndFails(): void
    {
        $this->workers->missing = [2];
        $tester = new CommandTester(new QoLStatusCommand($this->qol));

        self::assertSame(Command::FAILURE, $tester->execute([]));
        self::assertStringContainsString('No answer from worker(s) 2.', $tester->getDisplay());
    }

    public function testStreamsListEachWorkersStreamsAndTheTotal(): void
    {
        $this->workers->governors[2]->allocateStream(Uuid::fromString('a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11'), '4K', 55.567);
        $tester = new CommandTester(new QoLStreamsCommand($this->qol));

        self::assertSame(Command::SUCCESS, $tester->execute([]));

        $display = $tester->getDisplay();
        self::assertMatchesRegularExpression('/^\s*2\s+1\s+a0eebc99-9c0b-4ef8-bb6d-6bb9bd380a11 4K 55\.57/m', $display);
        self::assertMatchesRegularExpression('/^\s*Total\s+1\s+predicted cost 55\.57/m', $display);
    }

    public function testStreamsJsonIsTheApiDataPayload(): void
    {
        $this->workers->governors[0]->allocateStream(new Uuid(), '720p', 20.0);
        $tester = new CommandTester(new QoLStreamsCommand($this->qol));

        self::assertSame(Command::SUCCESS, $tester->execute(['--json' => true]));

        self::assertSame(self::asJson($this->qol->getActiveStreams()), json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR));
    }

    public function testProfileSetsEveryWorker(): void
    {
        $tester = new CommandTester(new QoLProfileCommand($this->qol));

        self::assertSame(Command::SUCCESS, $tester->execute(['profile' => 'aggressive']));

        foreach ($this->workers->governors as $governor) {
            self::assertSame(AlgorithmProfile::Aggressive, $governor->getProfile());
        }
        self::assertStringContainsString('Every worker uses the aggressive profile.', $tester->getDisplay());
        self::assertSame(['aggressive', 'aggressive', 'aggressive'], array_column($this->qol->getStatus()['workers'], 'profile'));
    }

    public function testAnUnknownProfileExitsInvalidAndChangesNoWorker(): void
    {
        $tester = new CommandTester(new QoLProfileCommand($this->qol));

        self::assertSame(Command::INVALID, $tester->execute(['profile' => 'turbo']));

        self::assertStringContainsString('Unknown profile "turbo"', $tester->getDisplay());
        self::assertSame([], $this->workers->calls);
        self::assertSame(['balanced', 'balanced', 'balanced'], array_column($this->qol->getStatus()['workers'], 'profile'));
    }

    public function testAProfileChangeThatMissesAWorkerFails(): void
    {
        $this->workers->missing = [1];
        $tester = new CommandTester(new QoLProfileCommand($this->qol));

        self::assertSame(Command::FAILURE, $tester->execute(['profile' => 'aggressive']));

        self::assertStringContainsString('No answer from worker(s) 1.', $tester->getDisplay());
        self::assertStringNotContainsString('Every worker uses', $tester->getDisplay());
    }

    public function testResetWithoutATerminalRequiresForce(): void
    {
        $this->train();
        $tester = new CommandTester(new QoLResetCommand($this->qol));

        self::assertSame(Command::INVALID, $tester->execute([], ['interactive' => false]));

        self::assertStringContainsString('--force', $tester->getDisplay());
        self::assertSame([], $this->workers->calls);
        self::assertSame(GovernorState::Active, $this->workers->governors[0]->getState());
    }

    public function testForcedResetReturnsEveryWorkerToLearning(): void
    {
        $this->train();
        $tester = new CommandTester(new QoLResetCommand($this->qol));

        self::assertSame(Command::SUCCESS, $tester->execute(['--force' => true], ['interactive' => false]));

        foreach ($this->workers->governors as $governor) {
            self::assertSame(GovernorState::Learning, $governor->getState());
            self::assertSame(0, $governor->getModel()->sampleCount());
        }
        self::assertFileExists($this->directory . '/governor_state.json');
    }

    public function testEveryCommandReportsThatNoServerIsRunning(): void
    {
        $port = $this->createStub(ServerControlPortInterface::class);
        $port->method('execute')->willThrowException(new ServerNotRunningException());
        $qol = new QoLAdminService($port);

        foreach ([
            [new QoLStatusCommand($qol), []],
            [new QoLStreamsCommand($qol), []],
            [new QoLProfileCommand($qol), ['profile' => 'balanced']],
            [new QoLResetCommand($qol), ['--force' => true]],
        ] as [$command, $input]) {
            $tester = new CommandTester($command);
            self::assertSame(Command::FAILURE, $tester->execute($input, ['interactive' => false]), (string) $command->getName());
            self::assertStringContainsString('no web server is running in this container', $tester->getDisplay());
        }
    }

    public function testCommandsUseThePortTheControllerUses(): void
    {
        foreach ([QoLStatusCommand::class, QoLStreamsCommand::class, QoLProfileCommand::class, QoLResetCommand::class] as $class) {
            $parameters = (new \ReflectionMethod($class, '__construct'))->getParameters();
            self::assertCount(1, $parameters);
            self::assertSame(QoLAdminPortInterface::class, (string) $parameters[0]->getType());
        }
    }

    /**
     * The payload as a JSON client reads it: the API response encodes it the same way.
     *
     * @param array<string, mixed> $payload
     *
     * @return array<string, mixed>
     */
    private static function asJson(array $payload): array
    {
        return json_decode(json_encode($payload, JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);
    }

    private function train(): void
    {
        foreach ($this->workers->governors as $governor) {
            for ($i = 0; $i < LearningModel::MIN_SAMPLES; $i++) {
                $governor->getModel()->addSample(new UtilizationSample(50.0, 0.0, 30.0, 1080, 'h264', false, 5_000_000, '1080p', 1));
            }
            $governor->activate();
        }
    }
}
