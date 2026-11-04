<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** Immutable startup capabilities shared by web and worker processes. */
final readonly class DeploymentProfile
{
    /** Video slots an unset full profile runs with software encoding. */
    private const int DEFAULT_SOFTWARE_VIDEO_SLOTS = 8;
    /**
     * Video slots an unset full profile runs with a hardware encoder: the most concurrent 1080p
     * hardware encodes that each stayed ahead of real time on the reference GPU, an AMD Radeon
     * RX 9070 XT with VAAPI, for both encoders the accelerator offers. H.264 bounds it; HEVC
     * stayed ahead at 14.
     */
    private const int DEFAULT_HARDWARE_VIDEO_SLOTS = 8;
    /** Audio slots an unset full profile runs. */
    private const int DEFAULT_AUDIO_SLOTS = 12;
    private const int CONTROL_COMMANDS = 1;
    private const int MEDIA_JOB_COMMANDS = 1;
    private const int MEDIA_HELPER_COMMANDS = 1;
    /** Open native peers beyond one per admitted client, for overlapping requests and stray peers. */
    private const int NATIVE_PEER_MARGIN = 4;

    public int $webWorkers;
    public int $doctrinePoolSlots;
    public int $ordinaryConsumerLimit;
    public int $sharedCopySlots;
    public int $videoEncoderSlots;
    public int $audioEncoderSlots;

    /**
     * Full deployments accept operator video and audio slot counts up to the pooler-derived
     * maximums; zero disables a kind and unset counts use the default, which for video depends on
     * whether a hardware encoder is configured. Household runs a fixed shared copy pool and
     * accepts neither slots nor a hardware encoder.
     */
    public function __construct(public string $identifier, ?int $videoEncoderSlots = null, ?int $audioEncoderSlots = null, public ?HardwareEncoder $hardware = null)
    {
        [$this->webWorkers, $this->doctrinePoolSlots, $this->ordinaryConsumerLimit, $this->sharedCopySlots] = match ($identifier) {
            'full' => [4, 10, 4, 0],
            'household' => $videoEncoderSlots === null && $audioEncoderSlots === null && $hardware === null
                ? [2, 4, 1, 2]
                : throw new \InvalidArgumentException('Household deployment profile runs a fixed shared copy pool and accepts no video or audio slot values or hardware encoder.'),
            default => throw new \InvalidArgumentException('Unknown deployment profile.'),
        };
        [$this->videoEncoderSlots, $this->audioEncoderSlots] = $identifier === 'full'
            ? [$videoEncoderSlots ?? self::defaultSlots('video', $hardware), $audioEncoderSlots ?? self::defaultSlots('audio', $hardware)]
            : [0, 0];
        if ($hardware !== null && $this->videoEncoderSlots === 0) {
            throw new \InvalidArgumentException('A hardware encoder serves video jobs; configure video slots above zero or remove the hardware encoder.');
        }
        $this->mediaSlotBounds()?->assertAccepts($this->videoEncoderSlots, $this->audioEncoderSlots);
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
            if (match ($name) { 'identifier' => !is_string($field), 'hardware' => $field !== null && !$field instanceof \stdClass, default => !is_int($field) }) {
                throw new \InvalidArgumentException('Deployment profile requires a string identifier, a hardware object or null, and JSON integer settings.');
            }
        }
        $hardware = $fields['hardware'] === null ? null : HardwareEncoder::fromJsonObject($fields['hardware']);
        $profile = $fields['identifier'] === 'household' && $hardware === null
            ? new self('household')
            : new self($fields['identifier'], $fields['videoEncoderSlots'], $fields['audioEncoderSlots'], $hardware);
        $fields['hardware'] = $hardware;
        if ($fields != get_object_vars($profile)) {
            throw new \InvalidArgumentException('Deployment profile derived settings disagree with its identifier and slots.');
        }
        return $profile;
    }

    private static function defaultSlots(string $kind, ?HardwareEncoder $hardware): int
    {
        return match (true) {
            $kind === 'audio' => self::DEFAULT_AUDIO_SLOTS,
            $hardware !== null => self::DEFAULT_HARDWARE_VIDEO_SLOTS,
            default => self::DEFAULT_SOFTWARE_VIDEO_SLOTS,
        };
    }

    /** The slot maximums of a profile that takes slot values; household's slots are fixed. */
    public function mediaSlotBounds(): ?MediaSlotBounds
    {
        return $this->sharedCopySlots > 0 ? null : new MediaSlotBounds($this->webWorkers, $this->doctrinePoolSlots, $this->ordinaryConsumerLimit);
    }

    /** Identifies the profile's complete settings; the worker boot row records it for web health. */
    public function fingerprint(): string
    {
        return hash('sha256', $this->toJson());
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

    /** Open peers the native daemon accepts before further peers wait in its listen backlog. */
    public function nativePeerLimit(): int
    {
        return $this->nativeClientLimit() + self::NATIVE_PEER_MARGIN;
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
