<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** Immutable startup capabilities shared by web and worker processes. */
final readonly class DeploymentProfile
{
    /** Placeholder per-kind ceiling until the maximums are derived from the measured cost of a job. */
    public const int PROVISIONAL_MAXIMUM_SLOTS_PER_KIND = 32;

    private const int DEFAULT_FULL_VIDEO_SLOTS = 3;
    private const int DEFAULT_FULL_AUDIO_SLOTS = 1;
    private const int CONTROL_COMMANDS = 1;
    private const int MEDIA_JOB_COMMANDS = 1;
    private const int MEDIA_HELPER_COMMANDS = 1;

    public int $webWorkers;
    public int $doctrinePoolSlots;
    public int $ordinaryConsumerLimit;
    public int $sharedCopySlots;
    public int $videoEncoderSlots;
    public int $audioEncoderSlots;

    /**
     * Full deployments accept operator video and audio slot counts; zero disables a kind and
     * unset counts use the default. Household runs a fixed shared copy pool and accepts none.
     */
    public function __construct(public string $identifier, ?int $videoEncoderSlots = null, ?int $audioEncoderSlots = null)
    {
        [$this->webWorkers, $this->doctrinePoolSlots, $this->ordinaryConsumerLimit,
            $this->sharedCopySlots, $this->videoEncoderSlots, $this->audioEncoderSlots] = match ($identifier) {
                'full' => [4, 10, 4, 0,
                    self::boundedSlots('video', $videoEncoderSlots ?? self::DEFAULT_FULL_VIDEO_SLOTS),
                    self::boundedSlots('audio', $audioEncoderSlots ?? self::DEFAULT_FULL_AUDIO_SLOTS)],
                'household' => $videoEncoderSlots === null && $audioEncoderSlots === null
                    ? [2, 4, 1, 2, 0, 0]
                    : throw new \InvalidArgumentException('Household deployment profile runs a fixed shared copy pool and accepts no video or audio slot values.'),
                default => throw new \InvalidArgumentException('Unknown deployment profile.'),
            };
    }

    public static function resolve(string $identifier, ?string $serialized = null): self
    {
        if ($serialized === null) {
            return new self($identifier);
        }
        $profile = self::fromJson($serialized);
        if ($profile->identifier !== $identifier) {
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
        if (!$value instanceof \stdClass) {
            throw new \InvalidArgumentException('Deployment profile requires a JSON object.');
        }
        $fields = get_object_vars($value);
        $expected = get_object_vars(new self('household'));
        if (array_diff_key($fields, $expected) !== [] || array_diff_key($expected, $fields) !== []) {
            throw new \InvalidArgumentException('Deployment profile has unknown or missing settings.');
        }
        foreach ($fields as $name => $field) {
            if ($name === 'identifier' ? !is_string($field) : !is_int($field)) {
                throw new \InvalidArgumentException('Deployment profile requires a string identifier and JSON integer settings.');
            }
        }
        $profile = $fields['identifier'] === 'household'
            ? new self('household')
            : new self($fields['identifier'], $fields['videoEncoderSlots'], $fields['audioEncoderSlots']);
        $derived = get_object_vars($profile);
        ksort($fields);
        ksort($derived);
        if ($fields !== $derived) {
            throw new \InvalidArgumentException('Deployment profile derived settings disagree with its identifier and slots.');
        }
        return $profile;
    }

    private static function boundedSlots(string $kind, int $slots): int
    {
        if ($slots < 0 || $slots > self::PROVISIONAL_MAXIMUM_SLOTS_PER_KIND) {
            throw new \InvalidArgumentException(sprintf('Full deployment profile accepts 0 through %d %s slots.', self::PROVISIONAL_MAXIMUM_SLOTS_PER_KIND, $kind));
        }
        return $slots;
    }

    public function phpHeapMiB(): int
    {
        return 256;
    }

    public function phpProcessMemoryMiB(): int
    {
        return $this->phpHeapMiB() + 64;
    }

    public function nativeManagerHeapMiB(): int
    {
        return 128;
    }

    public function mediaChildSlots(): int
    {
        return $this->sharedCopySlots + $this->videoEncoderSlots + $this->audioEncoderSlots;
    }

    /**
     * Native client identifiers a media job of the kind may run as, one per configured slot. The
     * household shared copy pool serves both kinds from the same identifiers.
     *
     * @return list<string>
     */
    public function mediaClientIds(string $kind): array
    {
        if (!in_array($kind, ['video', 'audio'], true)) {
            throw new \InvalidArgumentException('Unknown media job kind.');
        }
        [$prefix, $slots] = $this->sharedCopySlots > 0
            ? ['media-copy-', $this->sharedCopySlots]
            : ['media-' . $kind . '-', $kind === 'video' ? $this->videoEncoderSlots : $this->audioEncoderSlots];

        return array_map(static fn (int $index): string => $prefix . $index, $slots === 0 ? [] : range(0, $slots - 1));
    }

    /**
     * Every native client the profile admits, with the commands each may run at once. The
     * supervisor only proves readiness, media control runs one probe, a video or shared copy
     * client runs its encoder or copy plus one helper, and an audio client runs its encoder.
     *
     * @return array<string, int>
     */
    public function nativeClientCommandCaps(): array
    {
        $caps = ['supervisor' => 0, 'media-control' => self::CONTROL_COMMANDS]
            + array_fill_keys($this->mediaClientIds('video'), self::MEDIA_JOB_COMMANDS + self::MEDIA_HELPER_COMMANDS);

        return $this->sharedCopySlots > 0 ? $caps : $caps + array_fill_keys($this->mediaClientIds('audio'), self::MEDIA_JOB_COMMANDS);
    }

    public function nativeCommandLimit(): int
    {
        return array_sum($this->nativeClientCommandCaps());
    }

    /** Retained results, running or awaiting release: twice the command limit plus two. */
    public function nativeResultLimit(): int
    {
        return 2 * $this->nativeCommandLimit() + 2;
    }

    public function nativeClientLimit(): int
    {
        return count($this->nativeClientCommandCaps());
    }

    /** Helpers a video job runs at once inside its native client allowance; further helpers queue. */
    public function mediaHelperLimit(): int
    {
        return self::MEDIA_HELPER_COMMANDS;
    }

    public function toJson(): string
    {
        return json_encode(get_object_vars($this), JSON_THROW_ON_ERROR);
    }
}
