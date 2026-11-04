<?php

declare(strict_types=1);

namespace App\Tests\Functional\Console;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\HardwareEncoder;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class DeploymentProfileShowCommandTest extends KernelTestCase
{
    private CommandTester $command;

    protected function setUp(): void
    {
        $this->command = new CommandTester(new Application(self::bootKernel())->find('app:deployment:profile:show'));
    }

    protected function tearDown(): void
    {
        static::ensureKernelShutdown();
        parent::tearDown();
    }

    public function testWithoutOptionsItPrintsTheEffectiveProfileAsEnvironmentValues(): void
    {
        $effective = self::getContainer()->get(DeploymentProfile::class);
        self::assertInstanceOf(DeploymentProfile::class, $effective);

        self::assertSame(Command::SUCCESS, $this->command->execute([]));
        $display = $this->command->getDisplay();
        self::assertStringContainsString('BAANDER_DEPLOYMENT_PROFILE=' . $effective->identifier . "\n", $display);
        self::assertStringContainsString("BAANDER_DEPLOYMENT_PROFILE_JSON='" . $effective->toJson() . "'\n", $display);
        self::assertStringContainsString($effective->fingerprint(), $display);
    }

    public function testJsonCarriesAProfileTheWorkersAcceptAndItsDerivedLimits(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute(['--identifier' => 'full', '--video-slots' => '8', '--audio-slots' => '12', '--json' => true]));
        $document = json_decode($this->command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        $profile = DeploymentProfile::fromJson(json_encode($document['profile'], JSON_THROW_ON_ERROR));
        self::assertEquals(new DeploymentProfile('full', 8, 12), $profile);
        self::assertSame($profile->fingerprint(), $document['fingerprint']);
        self::assertSame([
            'videoSlots' => 8, 'audioSlots' => 12, 'sharedCopySlots' => 0,
            'maximumVideoSlots' => 145, 'maximumAudioSlots' => 217,
            'nativeCommandLimit' => 29, 'nativeResultLimit' => 60, 'nativeClientLimit' => 22, 'nativePeerLimit' => 26,
            'supervisorChildCeiling' => ['control' => 1 + 3 + 1 + 20, 'execution' => 1 + (4 + 1) * 2 + 1],
        ], $document['limits']);
    }

    public function testHouseholdPrintsItsFixedSharedCopyLimits(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute(['--identifier' => 'household', '--json' => true]));
        $document = json_decode($this->command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);

        self::assertEquals(new DeploymentProfile('household'), DeploymentProfile::fromJson(json_encode($document['profile'], JSON_THROW_ON_ERROR)));
        self::assertSame([0, 0, 2, null, null, 5, 12, 4], [
            $document['limits']['videoSlots'], $document['limits']['audioSlots'], $document['limits']['sharedCopySlots'],
            $document['limits']['maximumVideoSlots'], $document['limits']['maximumAudioSlots'],
            $document['limits']['nativeCommandLimit'], $document['limits']['nativeResultLimit'], $document['limits']['nativeClientLimit'],
        ]);
    }

    public function testSlotsBeyondTheirBoundAreRejectedAsInvalidInputNamingTheBound(): void
    {
        self::assertSame(Command::INVALID, $this->command->execute(['--identifier' => 'full', '--video-slots' => '146'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('at most 145 video slots', $this->command->getErrorOutput());
        self::assertSame('', $this->command->getDisplay());
    }

    public function testHouseholdRejectsSlotValues(): void
    {
        self::assertSame(Command::INVALID, $this->command->execute(['--identifier' => 'household', '--audio-slots' => '1'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('Household deployment profile', $this->command->getErrorOutput());
    }

    public function testHardwareOptionsBuildAProfileWithTheHardwareVideoDefault(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute(['--identifier' => 'full', '--hardware-accelerator' => 'vaapi', '--hardware-device' => '/dev/dri/renderD128', '--json' => true]));
        $document = json_decode($this->command->getDisplay(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($document);

        $profile = DeploymentProfile::fromJson(json_encode($document['profile'], JSON_THROW_ON_ERROR));
        self::assertEquals(new DeploymentProfile('full', hardware: new HardwareEncoder('vaapi', '/dev/dri/renderD128')), $profile);
        self::assertSame(['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128'], $document['profile']['hardware']);
        self::assertSame($profile->videoEncoderSlots, $document['limits']['videoSlots']);
        self::assertNotSame((new DeploymentProfile('full'))->fingerprint(), $document['fingerprint']);
    }

    public function testTextOutputNamesTheHardwareEncoder(): void
    {
        self::assertSame(Command::SUCCESS, $this->command->execute(['--identifier' => 'full', '--video-slots' => '6', '--hardware-accelerator' => 'vaapi', '--hardware-device' => '/dev/dri/renderD128']));
        self::assertStringContainsString('vaapi on /dev/dri/renderD128', $this->command->getDisplay());
        self::assertStringContainsString('"hardware":{"accelerator":"vaapi","device":"\\/dev\\/dri\\/renderD128"}', $this->command->getDisplay());
    }

    public function testInvalidHardwareOptionsAreRejected(): void
    {
        foreach ([
            [['--hardware-device' => '/dev/dri/renderD128'], 'requires --hardware-accelerator'],
            [['--hardware-accelerator' => 'vaapi'], 'requires a device path'],
            [['--hardware-accelerator' => 'cuda'], 'must be one of'],
            [['--identifier' => 'household', '--hardware-accelerator' => 'nvenc'], 'Household deployment profile'],
        ] as [$options, $message]) {
            self::assertSame(Command::INVALID, $this->command->execute($options, ['capture_stderr_separately' => true]));
            self::assertStringContainsString($message, $this->command->getErrorOutput());
        }
    }

    public function testNonIntegerSlotsAreRejected(): void
    {
        self::assertSame(Command::INVALID, $this->command->execute(['--video-slots' => 'eight'], ['capture_stderr_separately' => true]));
        self::assertStringContainsString('--video-slots must be an integer', $this->command->getErrorOutput());
    }
}
