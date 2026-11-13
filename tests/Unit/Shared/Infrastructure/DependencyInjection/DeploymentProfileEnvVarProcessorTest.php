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
    public function testWebCapacityUsesTheConfiguredSize(string $serialized, int $workers, int $connections): void
    {
        $processor = new DeploymentProfileEnvVarProcessor();
        $environment = static fn (string $name): string => $name === 'BAANDER_DEPLOYMENT_PROFILE'
            ? $serialized
            : throw new \LogicException('Unexpected environment lookup.');

        self::assertSame($workers, $processor->getEnv('baander_web_workers', 'BAANDER_DEPLOYMENT_PROFILE', $environment));
        self::assertSame($connections, $processor->getEnv('baander_doctrine_slots', 'BAANDER_DEPLOYMENT_PROFILE', $environment));
    }

    /** @return iterable<string, array{string, int, int}> */
    public static function profileSettings(): iterable
    {
        yield 'unset default' => ['', 4, 10];
        yield 'remux at four gigabytes' => [new DeploymentProfile('remux', 2, 4, 1, sharedCopySlots: 2)->toJson(), 2, 4];
        yield 'one web worker' => [new DeploymentProfile(webWorkers: 1, doctrinePoolSlots: 3)->toJson(), 1, 3];
    }

    public function testRetiredProfileNameCannotStartWebWorkers(): void
    {
        $processor = new DeploymentProfileEnvVarProcessor();
        $this->expectException(\InvalidArgumentException::class);
        $processor->getEnv('baander_web_workers', 'BAANDER_DEPLOYMENT_PROFILE', static fn (string $name): string => 'full');
    }
}
