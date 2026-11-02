<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** Immutable startup capabilities shared by web and worker processes. */
final readonly class DeploymentProfile
{
    public int $webWorkers;
    public int $doctrinePoolSlots;
    public int $ordinaryConsumerLimit;
    public int $sharedCopySlots;
    public int $videoEncoderSlots;
    public int $audioEncoderSlots;

    public function __construct(public string $identifier = 'full')
    {
        [$this->webWorkers, $this->doctrinePoolSlots, $this->ordinaryConsumerLimit,
            $this->sharedCopySlots, $this->videoEncoderSlots, $this->audioEncoderSlots] = match ($identifier) {
                'full' => [4, 10, 4, 0, 3, 1],
                'household' => [2, 4, 1, 2, 0, 0],
                default => throw new \InvalidArgumentException('Unknown deployment profile.'),
            };
    }

    public static function resolve(string $identifier, ?string $serialized = null): self
    {
        $profile = new self($identifier);
        if ($serialized !== null && self::fromJson($serialized) != $profile) {
            throw new \InvalidArgumentException('Deployment profile does not match its inherited effective settings.');
        }
        return $profile;
    }

    public static function fromEnvironment(): self
    {
        $identifier = $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] ?? $_ENV['BAANDER_DEPLOYMENT_PROFILE'] ?? getenv('BAANDER_DEPLOYMENT_PROFILE');
        $serialized = $_SERVER['BAANDER_DEPLOYMENT_PROFILE_JSON'] ?? $_ENV['BAANDER_DEPLOYMENT_PROFILE_JSON'] ?? getenv('BAANDER_DEPLOYMENT_PROFILE_JSON');
        if (($identifier !== false && !is_string($identifier)) || ($serialized !== false && !is_string($serialized))) {
            throw new \InvalidArgumentException('Deployment profile environment values require strings.');
        }
        return self::resolve($identifier === false ? 'full' : $identifier, $serialized === false || $serialized === '' ? null : $serialized);
    }

    public static function fromJson(string $json): self
    {
        if (strlen($json) > 2048) {
            throw new \InvalidArgumentException('Deployment profile exceeds 2048 bytes.');
        }
        try {
            $value = json_decode($json, false, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \InvalidArgumentException('Deployment profile requires a bounded JSON object.');
        }
        if (!$value instanceof \stdClass || !is_string($value->identifier ?? null)) {
            throw new \InvalidArgumentException('Deployment profile requires an identifier and effective settings.');
        }
        $profile = new self($value->identifier);
        $fields = get_object_vars($value);
        $expected = get_object_vars($profile);
        ksort($fields);
        ksort($expected);
        if ($fields !== $expected) {
            throw new \InvalidArgumentException('Deployment profile has unknown, missing or contradictory effective settings.');
        }
        return $profile;
    }

    public function toJson(): string
    {
        return json_encode(get_object_vars($this), JSON_THROW_ON_ERROR);
    }
}
