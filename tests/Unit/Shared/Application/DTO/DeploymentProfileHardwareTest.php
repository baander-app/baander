<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\HardwareEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentProfileHardwareTest extends TestCase
{
    /** Concurrent 1080p HEVC hardware encodes that each stayed clearly ahead of real time on the reference GPU. */
    private const int MEASURED_HARDWARE_VIDEO_SLOTS = 12;

    public function testUnsetVideoSlotsTakeTheMeasuredHardwareDefault(): void
    {
        $profile = new DeploymentProfile('full', hardware: new HardwareEncoder('vaapi', '/dev/dri/renderD128'));

        self::assertSame([self::MEASURED_HARDWARE_VIDEO_SLOTS, 12], [$profile->videoEncoderSlots, $profile->audioEncoderSlots]);
        self::assertSame(145, $profile->mediaSlotBounds()?->maximumVideoSlots);
    }

    public function testUnsetVideoSlotsWithoutHardwareStayAtEight(): void
    {
        self::assertSame(8, (new DeploymentProfile('full'))->videoEncoderSlots);
    }

    public function testConfiguredSlotsOverrideTheHardwareDefaultUpToThePoolerBound(): void
    {
        $hardware = new HardwareEncoder('vaapi', '/dev/dri/renderD128');

        self::assertSame(3, (new DeploymentProfile('full', 3, 12, $hardware))->videoEncoderSlots);
        self::assertSame(145, (new DeploymentProfile('full', 145, 0, $hardware))->videoEncoderSlots);
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 145 video slots');
        new DeploymentProfile('full', 146, 0, $hardware);
    }

    public function testHardwareRoundTripsAndChangesTheFingerprint(): void
    {
        $software = new DeploymentProfile('full', 8, 12);
        $vaapi = new DeploymentProfile('full', 8, 12, new HardwareEncoder('vaapi', '/dev/dri/renderD128'));
        $otherDevice = new DeploymentProfile('full', 8, 12, new HardwareEncoder('vaapi', '/dev/dri/renderD129'));
        $nvenc = new DeploymentProfile('full', 8, 12, new HardwareEncoder('nvenc'));

        self::assertSame('{"accelerator":"vaapi","device":"/dev/dri/renderD128"}', json_encode(json_decode($vaapi->toJson(), true, flags: JSON_THROW_ON_ERROR)['hardware'], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        self::assertNull(json_decode($software->toJson(), true, flags: JSON_THROW_ON_ERROR)['hardware']);
        foreach ([$software, $vaapi, $otherDevice, $nvenc] as $profile) {
            self::assertEquals($profile, DeploymentProfile::fromJson($profile->toJson()));
            self::assertSame($profile->fingerprint(), DeploymentProfile::resolve('full', $profile->toJson())->fingerprint());
        }
        $fingerprints = array_map(static fn (DeploymentProfile $profile): string => $profile->fingerprint(), [$software, $vaapi, $otherDevice, $nvenc]);
        self::assertSame($fingerprints, array_unique($fingerprints));
    }

    public function testHouseholdAcceptsNoHardwareEncoder(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('hardware encoder');
        new DeploymentProfile('household', hardware: new HardwareEncoder('vaapi', '/dev/dri/renderD128'));
    }

    public function testHardwareRequiresVideoSlots(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('video slots above zero');
        new DeploymentProfile('full', 0, 12, new HardwareEncoder('vaapi', '/dev/dri/renderD128'));
    }

    /** @param list<?string> $arguments */
    #[DataProvider('invalidEncoders')]
    public function testInvalidHardwareEncodersAreRejected(array $arguments, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new HardwareEncoder(...$arguments);
    }

    /** @return iterable<string, array{list<?string>, string}> */
    public static function invalidEncoders(): iterable
    {
        yield 'unknown accelerator' => [['videotoolbox', null], 'must be one of vaapi, qsv, nvenc, amf'];
        yield 'vaapi without device' => [['vaapi', null], 'requires a device path'];
        yield 'qsv without device' => [['qsv', null], 'requires a device path'];
        yield 'amf without device' => [['amf', null], 'requires a device path'];
        yield 'nvenc with device' => [['nvenc', '/dev/nvidia0'], 'takes no device path'];
        yield 'relative device' => [['vaapi', 'dev/dri/renderD128'], 'canonical absolute path under /dev'];
        yield 'device outside dev' => [['vaapi', '/tmp/renderD128'], 'canonical absolute path under /dev'];
        yield 'parent traversal' => [['vaapi', '/dev/dri/../sda'], 'canonical absolute path under /dev'];
        yield 'trailing slash' => [['vaapi', '/dev/dri/'], 'canonical absolute path under /dev'];
    }

    #[DataProvider('invalidSerializedHardware')]
    public function testInvalidSerializedHardwareIsRejected(mixed $hardware): void
    {
        $fields = json_decode((new DeploymentProfile('full', 8, 12))->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $fields['hardware'] = $hardware;
        $this->expectException(\InvalidArgumentException::class);
        DeploymentProfile::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidSerializedHardware(): iterable
    {
        yield 'string' => ['vaapi'];
        yield 'list' => [['vaapi', '/dev/dri/renderD128']];
        yield 'unknown field' => [['accelerator' => 'vaapi', 'device' => '/dev/dri/renderD128', 'group' => 485]];
        yield 'missing accelerator' => [['device' => '/dev/dri/renderD128']];
        yield 'missing device' => [['accelerator' => 'nvenc']];
        yield 'numeric device' => [['accelerator' => 'vaapi', 'device' => 128]];
        yield 'unknown accelerator' => [['accelerator' => 'cuda', 'device' => null]];
    }

    public function testEnvironmentCarriesTheHardwareEncoderToEveryProcess(): void
    {
        $profile = new DeploymentProfile('full', 6, 12, new HardwareEncoder('vaapi', '/dev/dri/renderD128'));
        $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = 'full';
        $_SERVER['BAANDER_DEPLOYMENT_PROFILE_JSON'] = $profile->toJson();
        try {
            self::assertEquals($profile, DeploymentProfile::fromEnvironment());
        } finally {
            unset($_SERVER['BAANDER_DEPLOYMENT_PROFILE'], $_SERVER['BAANDER_DEPLOYMENT_PROFILE_JSON']);
        }
    }
}
