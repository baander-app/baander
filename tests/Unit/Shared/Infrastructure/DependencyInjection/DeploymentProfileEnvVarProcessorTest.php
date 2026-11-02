<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Infrastructure\DependencyInjection;

use App\Shared\Application\DTO\DeploymentProfile;
use App\Shared\Infrastructure\DependencyInjection\DeploymentProfileEnvVarProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeploymentProfileEnvVarProcessorTest extends TestCase
{
    #[DataProvider('profileSettings')]
    public function testWebCapacityUsesTheSelectedProfile(string $identifier, int $workers, int $connections): void
    {
        $processor = new DeploymentProfileEnvVarProcessor();
        $environment = static fn (string $name): string => match ($name) {
            'BAANDER_DEPLOYMENT_PROFILE' => $identifier,
            'BAANDER_DEPLOYMENT_PROFILE_JSON' => '',
            default => throw new \LogicException('Unexpected environment lookup.'),
        };

        self::assertSame($workers, $processor->getEnv('baander_web_workers', 'BAANDER_DEPLOYMENT_PROFILE', $environment));
        self::assertSame($connections, $processor->getEnv('baander_doctrine_slots', 'BAANDER_DEPLOYMENT_PROFILE', $environment));
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function profileSettings(): iterable
    {
        yield 'full' => ['full', 4, 10];
        yield 'household' => ['household', 2, 4];
    }

    public function testUnknownProfileCannotStartWebWorkers(): void
    {
        $processor = new DeploymentProfileEnvVarProcessor();
        $this->expectException(\InvalidArgumentException::class);
        $processor->getEnv('baander_web_workers', 'BAANDER_DEPLOYMENT_PROFILE', static fn (string $name): string => $name === 'BAANDER_DEPLOYMENT_PROFILE' ? 'unknown' : '');
    }

    public function testWebCapacityRejectsConflictingInheritedSettings(): void
    {
        $serialized = new DeploymentProfile()->toJson();
        $environment = static fn (string $name): string => $name === 'BAANDER_DEPLOYMENT_PROFILE' ? 'household' : $serialized;

        $this->expectException(\InvalidArgumentException::class);
        new DeploymentProfileEnvVarProcessor()->getEnv('baander_web_workers', 'BAANDER_DEPLOYMENT_PROFILE', $environment);
    }
}
