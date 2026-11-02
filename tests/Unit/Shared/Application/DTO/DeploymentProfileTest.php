<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\DTO;

use App\Shared\Application\DTO\DeploymentProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentProfileTest extends TestCase
{
    public function testProfilesRoundTripTheirEffectiveSettings(): void
    {
        foreach (['full', 'household'] as $identifier) {
            $profile = new DeploymentProfile($identifier);
            self::assertEquals($profile, DeploymentProfile::fromJson($profile->toJson()));
        }
        $full = new DeploymentProfile();
        self::assertSame([4, 10, 4, 0, 3, 1], [$full->webWorkers, $full->doctrinePoolSlots, $full->ordinaryConsumerLimit, $full->sharedCopySlots, $full->videoEncoderSlots, $full->audioEncoderSlots]);
        $household = new DeploymentProfile('household');
        self::assertSame([2, 4, 1, 2, 0, 0], [$household->webWorkers, $household->doctrinePoolSlots, $household->ordinaryConsumerLimit, $household->sharedCopySlots, $household->videoEncoderSlots, $household->audioEncoderSlots]);
    }

    #[DataProvider('invalidSettings')]
    public function testMissingUnknownAndIncorrectlyTypedSettingsFail(string $kind): void
    {
        $fields = json_decode(new DeploymentProfile()->toJson(), true, flags: JSON_THROW_ON_ERROR);
        if ($kind === 'missing') {
            unset($fields['webWorkers']);
        } elseif ($kind === 'unknown') {
            $fields['memoryMiB'] = 128;
        } else {
            $fields['webWorkers'] = '4';
        }
        $this->expectException(\InvalidArgumentException::class);
        json_encode($fields, JSON_THROW_ON_ERROR) |> DeploymentProfile::fromJson(...);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidSettings(): iterable
    {
        yield 'missing' => ['missing'];
        yield 'unknown' => ['unknown'];
        yield 'type' => ['type'];
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

    public function testUnknownIdentifierFails(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new DeploymentProfile('small');
    }

    public function testContradictoryEffectiveSettingFails(): void
    {
        $fields = json_decode((new DeploymentProfile('household'))->toJson(), true, flags: JSON_THROW_ON_ERROR);
        $fields['videoEncoderSlots'] = 1;
        $this->expectException(\InvalidArgumentException::class);
        DeploymentProfile::fromJson(json_encode($fields, JSON_THROW_ON_ERROR));
    }

    public function testInheritedProfileMustMatchSelection(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        DeploymentProfile::resolve('household', (new DeploymentProfile())->toJson());
    }
}
