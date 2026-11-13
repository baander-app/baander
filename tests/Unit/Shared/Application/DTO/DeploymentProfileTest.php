<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Application\DTO\HardwareEncoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentProfileTest extends TestCase
{
    private static function remux(int $webWorkers = 2, int $doctrineSlots = 4, int $consumers = 1, int $copySlots = 2): DeploymentProfile
    {
        return new DeploymentProfile('remux', $webWorkers, $doctrineSlots, $consumers, sharedCopySlots: $copySlots);
    }

    public function testTranscodeProfileCarriesConfiguredSlotsThroughItsSerializedForm(): void
    {
        $profile = new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12);

        self::assertSame(['transcode', 4, 10, 4, 0, 8, 12], [$profile->capability, $profile->webWorkers, $profile->doctrinePoolSlots, $profile->ordinaryConsumerLimit, $profile->sharedCopySlots, $profile->videoEncoderSlots, $profile->audioEncoderSlots]);
        self::assertSame(20, $profile->mediaChildSlots());
        self::assertEquals($profile, DeploymentProfile::fromJson($profile->toJson()));
        self::assertEquals($profile, DeploymentProfile::resolve($profile->toJson()));
    }

    public function testRemuxProfileKeepsItsSharedCopyPoolAndRoundTrips(): void
    {
        $remux = self::remux();

        self::assertSame(['remux', 2, 4, 1, 2, 0, 0], [$remux->capability, $remux->webWorkers, $remux->doctrinePoolSlots, $remux->ordinaryConsumerLimit, $remux->sharedCopySlots, $remux->videoEncoderSlots, $remux->audioEncoderSlots]);
        self::assertSame(2, $remux->mediaChildSlots());
        self::assertEquals($remux, DeploymentProfile::fromJson($remux->toJson()));
        self::assertEquals($remux, DeploymentProfile::resolve($remux->toJson()));
    }

    public function testProfilesSetTheirPhpHeaps(): void
    {
        foreach ([new DeploymentProfile(videoEncoderSlots: 3, audioEncoderSlots: 1), self::remux()] as $profile) {
            self::assertSame(256, $profile->phpHeapMiB());
            self::assertSame(320, $profile->phpProcessMemoryMiB());
            self::assertSame(128, $profile->nativeManagerHeapMiB());
        }
    }

    public function testRemuxNativeLimitsFollowItsSharedCopyPool(): void
    {
        $remux = self::remux();

        self::assertSame(['supervisor' => 0, 'media-control' => 1, 'media-copy-0' => 2, 'media-copy-1' => 2], $remux->nativeClientCommandCaps());
        self::assertSame([5, 12, 4, 1], [$remux->nativeCommandLimit(), $remux->nativeResultLimit(), $remux->nativeClientLimit(), $remux->mediaHelperLimit()]);
        self::assertSame(['media-copy-0', 'media-copy-1'], $remux->mediaClientIds('video'));
        self::assertSame(['media-copy-0', 'media-copy-1'], $remux->mediaClientIds('audio'));
        self::assertSame(8, count(self::remux(copySlots: 8)->mediaClientIds('audio')));
    }

    public function testDefaultIsTranscodeAtTheSixteenGigabyteStartingPoint(): void
    {
        $default = new DeploymentProfile();

        self::assertSame(['transcode', 4, 10, 4, 0, 8, 12], [$default->capability, $default->webWorkers, $default->doctrinePoolSlots, $default->ordinaryConsumerLimit, $default->sharedCopySlots, $default->videoEncoderSlots, $default->audioEncoderSlots]);
        self::assertEquals($default, DeploymentProfile::resolve(''));
        self::assertSame([29, 60, 22, 1], [$default->nativeCommandLimit(), $default->nativeResultLimit(), $default->nativeClientLimit(), $default->mediaHelperLimit()]);
    }

    public function testSlotsSetTheNativePeerLimitAboveTheirClients(): void
    {
        self::assertSame(22 + 4, (new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12))->nativePeerLimit());
        self::assertSame(6 + 4, (new DeploymentProfile(videoEncoderSlots: 3, audioEncoderSlots: 1))->nativePeerLimit());
        self::assertSame(4 + 4, self::remux()->nativePeerLimit());
    }

    public function testFingerprintFollowsEverySetting(): void
    {
        $profile = new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12);

        self::assertMatchesRegularExpression('/\A[0-9a-f]{64}\z/D', $profile->fingerprint());
        self::assertSame($profile->fingerprint(), DeploymentProfile::fromJson($profile->toJson())->fingerprint());
        self::assertNotSame($profile->fingerprint(), (new DeploymentProfile(videoEncoderSlots: 4, audioEncoderSlots: 12))->fingerprint());
        self::assertNotSame($profile->fingerprint(), (new DeploymentProfile(webWorkers: 3, videoEncoderSlots: 8, audioEncoderSlots: 12))->fingerprint());
        self::assertNotSame(self::remux()->fingerprint(), self::remux(copySlots: 3)->fingerprint());
        self::assertNotSame(self::remux()->fingerprint(), self::remux(webWorkers: 1)->fingerprint());
    }

    public function testEightVideoAndTwelveAudioSlotsDeriveTheirNativeLimits(): void
    {
        $profile = new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12);

        self::assertSame([29, 60, 2 + 20], [$profile->nativeCommandLimit(), $profile->nativeResultLimit(), $profile->nativeClientLimit()]);
        self::assertSame('media-video-7', array_last($profile->mediaClientIds('video')));
        self::assertSame('media-audio-11', array_last($profile->mediaClientIds('audio')));
        self::assertNotContains('media-video-8', $profile->mediaClientIds('video'));
    }

    public function testEachVideoSlotAddsAnEncoderAndAHelper(): void
    {
        $base = new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12);
        $raised = new DeploymentProfile(videoEncoderSlots: 9, audioEncoderSlots: 12);

        self::assertSame($base->nativeCommandLimit() + 2, $raised->nativeCommandLimit());
        self::assertSame($base->nativeClientLimit() + 1, $raised->nativeClientLimit());
        self::assertSame(2, $raised->nativeClientCommandCaps()['media-video-8']);
    }

    public function testZeroVideoSlotsDeriveNoVideoClients(): void
    {
        $profile = new DeploymentProfile(videoEncoderSlots: 0, audioEncoderSlots: 4);

        self::assertSame([], $profile->mediaClientIds('video'));
        self::assertSame([], array_filter(array_keys($profile->nativeClientCommandCaps()), static fn (string $client): bool => str_starts_with($client, 'media-video-')));
        self::assertSame([5, 12, 6], [$profile->nativeCommandLimit(), $profile->nativeResultLimit(), $profile->nativeClientLimit()]);
    }

    public function testUnknownMediaKindHasNoClients(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeploymentProfile())->mediaClientIds('subtitle');
    }

    /** @param array<string, mixed> $settings */
    #[DataProvider('capabilityContradictions')]
    public function testCapabilityRefusesSettingsItCannotRun(array $settings, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new DeploymentProfile(...$settings);
    }

    /** @return iterable<string, array{array<string, mixed>, string}> */
    public static function capabilityContradictions(): iterable
    {
        yield 'remux with video slots' => [['capability' => 'remux', 'sharedCopySlots' => 2, 'videoEncoderSlots' => 1], 'Remux deployments run no video or audio encoding slots'];
        yield 'remux with audio slots' => [['capability' => 'remux', 'sharedCopySlots' => 2, 'audioEncoderSlots' => 1], 'Remux deployments run no video or audio encoding slots'];
        yield 'remux with a hardware encoder' => [['capability' => 'remux', 'sharedCopySlots' => 2, 'hardware' => new HardwareEncoder('nvenc')], 'Remux deployments run no video or audio encoding slots'];
        yield 'remux without copy slots' => [['capability' => 'remux'], 'between 1 and 8 copy slots'];
        yield 'remux with nine copy slots' => [['capability' => 'remux', 'sharedCopySlots' => 9], 'between 1 and 8 copy slots'];
        yield 'transcode with copy slots' => [['sharedCopySlots' => 2], 'Transcode deployments run video and audio slots, not copy slots'];
        yield 'unknown capability' => [['capability' => 'full'], 'capability must be transcode or remux'];
    }

    /** @param array{webWorkers?: int, doctrinePoolSlots?: int, ordinaryConsumerLimit?: int} $settings */
    #[DataProvider('sizesBeyondTheirBound')]
    public function testSizeSettingsBeyondTheirBoundAreRefusedByName(array $settings, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        new DeploymentProfile(...$settings);
    }

    /** @return iterable<string, array{array{webWorkers?: int, doctrinePoolSlots?: int, ordinaryConsumerLimit?: int}, string}> */
    public static function sizesBeyondTheirBound(): iterable
    {
        yield 'seventeen web workers' => [['webWorkers' => 17], 'between 1 and 16 web workers'];
        yield 'no web workers' => [['webWorkers' => 0], 'between 1 and 16 web workers'];
        yield 'seventeen Doctrine slots' => [['doctrinePoolSlots' => 17], 'between 1 and 16 database connection slots per web worker'];
        yield 'no ordinary consumers' => [['ordinaryConsumerLimit' => 0], 'between 1 and 4 ordinary consumers'];
        yield 'five ordinary consumers' => [['ordinaryConsumerLimit' => 5], 'between 1 and 4 ordinary consumers'];
    }

    public function testSizeBoundsAreInclusive(): void
    {
        $largest = new DeploymentProfile('remux', 16, 16, 4, sharedCopySlots: 8);
        $smallest = new DeploymentProfile('remux', 1, 1, 1, sharedCopySlots: 1);

        self::assertSame([16, 16, 4, 8], [$largest->webWorkers, $largest->doctrinePoolSlots, $largest->ordinaryConsumerLimit, $largest->sharedCopySlots]);
        self::assertSame([1, 1, 1, 1], [$smallest->webWorkers, $smallest->doctrinePoolSlots, $smallest->ordinaryConsumerLimit, $smallest->sharedCopySlots]);
    }

    /** @param array{int, int} $slots */
    #[DataProvider('disabledKinds')]
    public function testTranscodeAcceptsZeroSlotsForAKind(array $slots): void
    {
        $profile = new DeploymentProfile(videoEncoderSlots: $slots[0], audioEncoderSlots: $slots[1]);

        self::assertSame($slots, [$profile->videoEncoderSlots, $profile->audioEncoderSlots]);
        self::assertSame(array_sum($slots), $profile->mediaChildSlots());
        self::assertEquals($profile, DeploymentProfile::fromJson($profile->toJson()));
    }

    /** @return iterable<string, array{array{int, int}}> */
    public static function disabledKinds(): iterable
    {
        yield 'audio disabled' => [[2, 0]];
        yield 'video disabled' => [[0, 4]];
    }

    public function testTranscodeMaximumsFollowThePoolerClientLimit(): void
    {
        $bounds = (new DeploymentProfile())->mediaSlotBounds();

        self::assertNotNull($bounds);
        // Web: 4 workers × (10 Doctrine + 1 dedicated); 4 ordinary consumers; 3 scheduled consumer;
        // 2 lease agents; 5 scheduler; 5 other control roles; 2 operator clients.
        self::assertSame(44 + 4 + 3 + 2 + 5 + 5 + 2, $bounds->fixedClients);
        self::assertSame(500 - 65, $bounds->mediaJobClients);
        self::assertSame([145, 217], [$bounds->maximumVideoSlots, $bounds->maximumAudioSlots]);
        self::assertNull(self::remux()->mediaSlotBounds());
    }

    public function testSmallerSizesRaiseTheSlotMaximums(): void
    {
        $bounds = (new DeploymentProfile(webWorkers: 2, doctrinePoolSlots: 4, ordinaryConsumerLimit: 1, videoEncoderSlots: 1, audioEncoderSlots: 2))->mediaSlotBounds();

        self::assertNotNull($bounds);
        self::assertSame(10 + 1 + 3 + 2 + 5 + 5 + 2, $bounds->fixedClients);
    }

    public function testTranscodeAcceptsEachKindUpToItsMaximum(): void
    {
        self::assertSame(145, (new DeploymentProfile(videoEncoderSlots: 145, audioEncoderSlots: 0))->videoEncoderSlots);
        self::assertSame(217, (new DeploymentProfile(videoEncoderSlots: 0, audioEncoderSlots: 217))->audioEncoderSlots);
        // 3 × 101 + 2 × 66 = 435 pooled connections, exactly what media jobs may hold.
        $shared = new DeploymentProfile(videoEncoderSlots: 101, audioEncoderSlots: 66);
        self::assertSame([101, 66], [$shared->videoEncoderSlots, $shared->audioEncoderSlots]);
    }

    /** @param array{int, int} $slots */
    #[DataProvider('slotsBeyondTheirBound')]
    public function testTranscodeRejectsSlotsBeyondTheirBoundAndNamesIt(array $slots, string $bound): void
    {
        try {
            new DeploymentProfile(videoEncoderSlots: $slots[0], audioEncoderSlots: $slots[1]);
            self::fail('Slots beyond their bound were accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString($bound, $error->getMessage());
            self::assertStringNotContainsString('profile', $error->getMessage());
        }
    }

    /** @return iterable<string, array{array{int, int}, string}> */
    public static function slotsBeyondTheirBound(): iterable
    {
        yield 'negative video' => [[-1, 1], 'zero or more video slots'];
        yield 'negative audio' => [[1, -1], 'zero or more audio slots'];
        yield 'one video above its maximum' => [[146, 0], 'at most 145 video slots'];
        yield 'one audio above its maximum' => [[0, 218], 'at most 217 audio slots'];
        yield 'both kinds above the shared remainder' => [[101, 67], 'open 437 pooled client connections, more than the 435'];
    }

    public function testJsonBeyondTheBoundsIsRejectedWhenTheProfileIsBuilt(): void
    {
        $fields = json_decode((new DeploymentProfile())->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $fields['videoEncoderSlots'] = 146;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('at most 145 video slots');
        DeploymentProfile::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));
    }

    #[DataProvider('invalidSettings')]
    public function testMissingUnknownAndIncorrectlyTypedSettingsFail(string $field, mixed $value): void
    {
        $fields = json_decode(new DeploymentProfile(videoEncoderSlots: 8, audioEncoderSlots: 12)->toJson(), true, flags: JSON_THROW_ON_ERROR);
        if ($value === null) {
            unset($fields[$field]);
        } else {
            $fields[$field] = $value;
        }
        $this->expectException(\InvalidArgumentException::class);
        json_encode($fields, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION) |> DeploymentProfile::fromJson(...);
    }

    /** @return iterable<string, array{string, mixed}> */
    public static function invalidSettings(): iterable
    {
        yield 'missing size setting' => ['webWorkers', null];
        yield 'missing slot' => ['audioEncoderSlots', null];
        yield 'missing capability' => ['capability', null];
        yield 'retired identifier' => ['identifier', 'full'];
        yield 'unknown setting' => ['memoryMiB', 128];
        yield 'unknown slot kind' => ['subtitleEncoderSlots', 1];
        yield 'string size setting' => ['webWorkers', '4'];
        yield 'string slot' => ['videoEncoderSlots', '8'];
        yield 'fractional slot' => ['audioEncoderSlots', 12.0];
        yield 'boolean slot' => ['videoEncoderSlots', true];
        yield 'negative slot' => ['audioEncoderSlots', -1];
        yield 'numeric capability' => ['capability', 1];
        yield 'unknown capability' => ['capability', 'full'];
    }

    public function testRemuxJsonWithVideoSlotsFails(): void
    {
        $fields = json_decode(self::remux()->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $fields['videoEncoderSlots'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        DeploymentProfile::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public function testUnsetEnvironmentYieldsTheDefault(): void
    {
        $saved = $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] ?? null;
        unset($_SERVER['BAANDER_DEPLOYMENT_PROFILE']);
        $savedEnv = $_ENV['BAANDER_DEPLOYMENT_PROFILE'] ?? null;
        unset($_ENV['BAANDER_DEPLOYMENT_PROFILE']);
        $savedProcess = getenv('BAANDER_DEPLOYMENT_PROFILE');
        putenv('BAANDER_DEPLOYMENT_PROFILE');
        try {
            self::assertEquals(new DeploymentProfile(), DeploymentProfile::fromEnvironment());
        } finally {
            if ($saved !== null) {
                $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = $saved;
            }
            if ($savedEnv !== null) {
                $_ENV['BAANDER_DEPLOYMENT_PROFILE'] = $savedEnv;
            }
            if ($savedProcess !== false) {
                putenv('BAANDER_DEPLOYMENT_PROFILE=' . $savedProcess);
            }
        }
    }

    public function testEnvironmentCarriesTheProfileJson(): void
    {
        $saved = $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] ?? null;
        $profile = self::remux(copySlots: 3);
        $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = $profile->toJson();
        try {
            self::assertEquals($profile, DeploymentProfile::fromEnvironment());
        } finally {
            if ($saved === null) {
                unset($_SERVER['BAANDER_DEPLOYMENT_PROFILE']);
            } else {
                $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = $saved;
            }
        }
    }

    public function testRetiredProfileNameInTheEnvironmentFails(): void
    {
        $saved = $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] ?? null;
        $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = 'full';
        try {
            $this->expectException(\InvalidArgumentException::class);
            DeploymentProfile::fromEnvironment();
        } finally {
            if ($saved === null) {
                unset($_SERVER['BAANDER_DEPLOYMENT_PROFILE']);
            } else {
                $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = $saved;
            }
        }
    }
}
