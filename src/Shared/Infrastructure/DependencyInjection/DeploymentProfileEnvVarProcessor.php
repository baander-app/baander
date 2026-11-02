<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\DependencyInjection;

use App\Shared\Application\DTO\DeploymentProfile;
use Symfony\Component\DependencyInjection\EnvVarProcessorInterface;

final class DeploymentProfileEnvVarProcessor implements EnvVarProcessorInterface
{
    public function getEnv(string $prefix, string $name, \Closure $getEnv): int
    {
        $identifier = $getEnv($name);
        $serialized = $getEnv('BAANDER_DEPLOYMENT_PROFILE_JSON');
        if (!is_string($identifier) || !is_string($serialized)) {
            throw new \InvalidArgumentException('Deployment profile environment values must be strings.');
        }

        $profile = DeploymentProfile::resolve($identifier, $serialized === '' ? null : $serialized);

        return match ($prefix) {
            'baander_web_workers' => $profile->webWorkers,
            'baander_doctrine_slots' => $profile->doctrinePoolSlots,
            default => throw new \InvalidArgumentException('Unknown deployment profile setting.'),
        };
    }

    public static function getProvidedTypes(): array
    {
        return [
            'baander_web_workers' => 'int',
            'baander_doctrine_slots' => 'int',
        ];
    }
}
