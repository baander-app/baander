<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\DeploymentProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentProfileTest extends TestCase
{
    public function testFullProfileCarriesConfiguredSlotsThroughItsSerializedForm(): void
    {
        $profile = new DeploymentProfile('full', 8, 12);

        self::assertSame([4, 10, 4, 0, 8, 12], [$profile->webWorkers, $profile->doctrinePoolSlots, $profile->ordinaryConsumerLimit, $profile->sharedCopySlots, $profile->videoEncoderSlots, $profile->audioEncoderSlots]);
        self::assertSame(20, $profile->mediaChildSlots());
        self::assertEquals($profile, DeploymentProfile::fromJson($profile->toJson()));
        self::assertEquals($profile, DeploymentProfile::resolve('full', $profile->toJson()));
    }

    public function testHouseholdKeepsItsSharedCopyModeAndRoundTrips(): void
    {
        $household = new DeploymentProfile('household');

        self::assertSame([2, 4, 1, 2, 0, 0], [$household->webWorkers, $household->doctrinePoolSlots, $household->ordinaryConsumerLimit, $household->sharedCopySlots, $household->videoEncoderSlots, $household->audioEncoderSlots]);
        self::assertSame(2, $household->mediaChildSlots());
        self::assertEquals($household, DeploymentProfile::fromJson($household->toJson()));
        self::assertEquals($household, DeploymentProfile::resolve('household', $household->toJson()));
    }

    public function testProfilesReserveTheirOwnHeapAndNativeEnvelope(): void
    {
        $full = new DeploymentProfile('full', 3, 1);
        self::assertSame(256, $full->phpHeapMiB());
        self::assertSame(320, $full->phpProcessMemoryMiB());
        self::assertSame(3136, $full->nativeEnvelopeMiB());
        self::assertSame(128, $full->nativeManagerHeapMiB());
        self::assertSame(1600, $full->mediaMemoryMiB());

        $household = new DeploymentProfile('household');
        self::assertSame(256, $household->phpHeapMiB());
        self::assertSame(320, $household->phpProcessMemoryMiB());
        self::assertSame(512, $household->nativeEnvelopeMiB());
        self::assertSame(128, $household->nativeManagerHeapMiB());
        self::assertSame(960, $household->mediaMemoryMiB());
    }

    public function testHouseholdNativeLimitsKeepTheirSharedCopyValues(): void
    {
        $household = new DeploymentProfile('household');

        self::assertSame(['supervisor' => 0, 'media-control' => 1, 'media-copy-0' => 2, 'media-copy-1' => 2], $household->nativeClientCommandCaps());
        self::assertSame([5, 12, 4, 1], [$household->nativeCommandLimit(), $household->nativeResultLimit(), $household->nativeClientLimit(), $household->mediaHelperLimit()]);
        self::assertSame(['media-copy-0', 'media-copy-1'], $household->mediaClientIds('video'));
        self::assertSame(['media-copy-0', 'media-copy-1'], $household->mediaClientIds('audio'));
    }

    public function testDefaultFullSlotsKeepTheEightCommandLimit(): void
    {
        $full = new DeploymentProfile('full');

        self::assertSame([
            'supervisor' => 0, 'media-control' => 1,
            'media-video-0' => 2, 'media-video-1' => 2, 'media-video-2' => 2,
            'media-audio-0' => 1,
        ], $full->nativeClientCommandCaps());
        self::assertSame([8, 18, 6, 1], [$full->nativeCommandLimit(), $full->nativeResultLimit(), $full->nativeClientLimit(), $full->mediaHelperLimit()]);
        self::assertSame([3136, 1600], [$full->nativeEnvelopeMiB(), $full->mediaMemoryMiB()]);
    }

    public function testEightVideoAndTwelveAudioSlotsDeriveTheirNativeLimits(): void
    {
        $profile = new DeploymentProfile('full', 8, 12);

        self::assertSame([29, 60, 2 + 20], [$profile->nativeCommandLimit(), $profile->nativeResultLimit(), $profile->nativeClientLimit()]);
        self::assertSame('media-video-7', array_last($profile->mediaClientIds('video')));
        self::assertSame('media-audio-11', array_last($profile->mediaClientIds('audio')));
        self::assertNotContains('media-video-8', $profile->mediaClientIds('video'));
    }

    public function testEachVideoSlotAddsAnEncoderAndAHelper(): void
    {
        $base = new DeploymentProfile('full', 8, 12);
        $raised = new DeploymentProfile('full', 9, 12);

        self::assertSame($base->nativeCommandLimit() + 2, $raised->nativeCommandLimit());
        self::assertSame($base->nativeClientLimit() + 1, $raised->nativeClientLimit());
        self::assertSame(2, $raised->nativeClientCommandCaps()['media-video-8']);
    }

    public function testZeroVideoSlotsDeriveNoVideoClients(): void
    {
        $profile = new DeploymentProfile('full', 0, 4);

        self::assertSame([], $profile->mediaClientIds('video'));
        self::assertSame([], array_filter(array_keys($profile->nativeClientCommandCaps()), static fn (string $client): bool => str_starts_with($client, 'media-video-')));
        self::assertSame([5, 12, 6], [$profile->nativeCommandLimit(), $profile->nativeResultLimit(), $profile->nativeClientLimit()]);
    }

    public function testUnknownMediaKindHasNoClients(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new DeploymentProfile('full'))->mediaClientIds('subtitle');
    }

    /** @param array{?int, ?int} $slots */
    #[DataProvider('householdSlotOverrides')]
    public function testHouseholdRejectsSlotOverrides(array $slots): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Household deployment profile');
        new DeploymentProfile('household', ...$slots);
    }

    /** @return iterable<string, array{array{?int, ?int}}> */
    public static function householdSlotOverrides(): iterable
    {
        yield 'zero video' => [[0, null]];
        yield 'zero audio' => [[null, 0]];
        yield 'both kinds' => [[8, 12]];
    }

    /** @param array{int, int} $slots */
    #[DataProvider('disabledKinds')]
    public function testFullProfileAcceptsZeroSlotsForAKind(array $slots): void
    {
        $profile = new DeploymentProfile('full', ...$slots);

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

    public function testFullProfileRejectsSlotsOutsideItsBounds(): void
    {
        foreach ([[-1, 1], [1, -1], [DeploymentProfile::PROVISIONAL_MAXIMUM_SLOTS_PER_KIND + 1, 1], [1, DeploymentProfile::PROVISIONAL_MAXIMUM_SLOTS_PER_KIND + 1]] as [$video, $audio]) {
            try {
                new DeploymentProfile('full', $video, $audio);
                self::fail('Out-of-range slots were accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('0 through ' . DeploymentProfile::PROVISIONAL_MAXIMUM_SLOTS_PER_KIND, $error->getMessage());
            }
        }
    }

    #[DataProvider('invalidSettings')]
    public function testMissingUnknownAndIncorrectlyTypedSettingsFail(string $field, mixed $value): void
    {
        $fields = json_decode(new DeploymentProfile('full', 8, 12)->toJson(), true, flags: JSON_THROW_ON_ERROR);
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
        yield 'missing derived setting' => ['webWorkers', null];
        yield 'missing slot' => ['audioEncoderSlots', null];
        yield 'unknown setting' => ['memoryMiB', 128];
        yield 'unknown slot kind' => ['subtitleEncoderSlots', 1];
        yield 'string derived setting' => ['webWorkers', '4'];
        yield 'string slot' => ['videoEncoderSlots', '8'];
        yield 'fractional slot' => ['audioEncoderSlots', 12.0];
        yield 'boolean slot' => ['videoEncoderSlots', true];
        yield 'negative slot' => ['audioEncoderSlots', -1];
        yield 'numeric identifier' => ['identifier', 1];
    }

    #[DataProvider('contradictoryDerivedSettings')]
    public function testDerivedSettingThatDisagreesWithTheSlotsFails(string $identifier, string $field, int $value): void
    {
        $profile = $identifier === 'full' ? new DeploymentProfile('full', 8, 12) : new DeploymentProfile('household');
        $fields = json_decode($profile->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $fields[$field] = $value;
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('derived settings');
        DeploymentProfile::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));
    }

    /** @return iterable<string, array{string, string, int}> */
    public static function contradictoryDerivedSettings(): iterable
    {
        yield 'full with shared copy slots' => ['full', 'sharedCopySlots', 2];
        yield 'full with household web workers' => ['full', 'webWorkers', 2];
        yield 'household with video slots' => ['household', 'videoEncoderSlots', 1];
        yield 'household without shared copy' => ['household', 'sharedCopySlots', 0];
    }

    public function testExplicitEmptyEnvironmentIdentifierFails(): void
    {
        $saved = $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] ?? null;
        $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = '';
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

    public function testEnvironmentCarriesInheritedSlots(): void
    {
        $saved = [$_SERVER['BAANDER_DEPLOYMENT_PROFILE'] ?? null, $_SERVER['BAANDER_DEPLOYMENT_PROFILE_JSON'] ?? null];
        $profile = new DeploymentProfile('full', 8, 12);
        $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] = 'full';
        $_SERVER['BAANDER_DEPLOYMENT_PROFILE_JSON'] = $profile->toJson();
        try {
            self::assertEquals($profile, DeploymentProfile::fromEnvironment());
        } finally {
            foreach (['BAANDER_DEPLOYMENT_PROFILE', 'BAANDER_DEPLOYMENT_PROFILE_JSON'] as $index => $name) {
                if ($saved[$index] === null) {
                    unset($_SERVER[$name]);
                } else {
                    $_SERVER[$name] = $saved[$index];
                }
            }
        }
    }

    public function testUnknownIdentifierFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeploymentProfile('small');
    }

    public function testInheritedProfileMustMatchSelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeploymentProfile::resolve('household', (new DeploymentProfile('full', 8, 12))->toJson());
    }
}
