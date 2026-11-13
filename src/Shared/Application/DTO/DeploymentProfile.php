<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

/** Immutable startup capabilities shared by web and worker processes. */
final readonly class DeploymentProfile
{
    /** Video slots an unset transcode deployment runs with software encoding. */
    private const int DEFAULT_SOFTWARE_VIDEO_SLOTS = 8;
    /**
     * Video slots an unset transcode deployment runs with a hardware encoder. On the reference GPU, an
     * AMD Radeon RX 9070 XT with VAAPI, twelve concurrent 1080p HEVC encodes (the encoder media
     * control selects by default) each stayed 12% ahead of real time; fourteen held only 8%.
     * H.264 hardware encodes stay ahead only up to eight, so a deployment that forces H.264
     * sets its video slots explicitly.
     */
    private const int DEFAULT_HARDWARE_VIDEO_SLOTS = 12;
    /** Audio slots an unset transcode deployment runs. */
    private const int DEFAULT_AUDIO_SLOTS = 12;
    private const int CONTROL_COMMANDS = 1;
    private const int MEDIA_JOB_COMMANDS = 1;
    private const int MEDIA_HELPER_COMMANDS = 1;
    /** Open native peers beyond one per admitted client, for overlapping requests and stray peers. */
    private const int NATIVE_PEER_MARGIN = 4;

    /** Capabilities a deployment runs: re-encoding with video and audio slots, or stream copy only. */
    public const array CAPABILITIES = ['transcode', 'remux'];
    public const int MAXIMUM_WEB_WORKERS = 16;
    public const int MAXIMUM_DOCTRINE_POOL_SLOTS = 16;
    /** The resident ordinary consumer ceiling that worker autoscaling admits. */
    public const int MAXIMUM_ORDINARY_CONSUMERS = 4;
    public const int MAXIMUM_COPY_SLOTS = 8;

    public string $capability;
    public int $webWorkers;
    public int $doctrinePoolSlots;
    public int $ordinaryConsumerLimit;
    public int $sharedCopySlots;
    public int $videoEncoderSlots;
    public int $audioEncoderSlots;
    public ?HardwareEncoder $hardware;

    /**
     * Capability decides what media work runs; the remaining settings size the deployment. The
     * defaults describe a transcode deployment for a 16 GB host. A transcode deployment accepts
     * video and audio slot counts up to the pooler-derived maximums, where zero disables a kind and
     * an unset count takes the default, which for video depends on whether a hardware encoder is
     * configured. A remux deployment runs a shared pool of copy slots and accepts neither encoding
     * slots nor a hardware encoder.
     */
    public function __construct(
        string $capability = 'transcode',
        int $webWorkers = 4,
        int $doctrinePoolSlots = 10,
        int $ordinaryConsumerLimit = 4,
        int $sharedCopySlots = 0,
        ?int $videoEncoderSlots = null,
        ?int $audioEncoderSlots = null,
        ?HardwareEncoder $hardware = null,
    ) {
        if (!in_array($capability, self::CAPABILITIES, true)) {
            throw new \InvalidArgumentException('Deployment capability must be transcode or remux.');
        }
        self::assertWithin($webWorkers, self::MAXIMUM_WEB_WORKERS, 'web workers');
        self::assertWithin($doctrinePoolSlots, self::MAXIMUM_DOCTRINE_POOL_SLOTS, 'database connection slots per web worker');
        self::assertWithin($ordinaryConsumerLimit, self::MAXIMUM_ORDINARY_CONSUMERS, 'ordinary consumers');
        [$this->capability, $this->webWorkers, $this->doctrinePoolSlots, $this->ordinaryConsumerLimit, $this->sharedCopySlots, $this->hardware]
            = [$capability, $webWorkers, $doctrinePoolSlots, $ordinaryConsumerLimit, $sharedCopySlots, $hardware];
        if ($capability === 'remux') {
            if (($videoEncoderSlots ?? 0) !== 0 || ($audioEncoderSlots ?? 0) !== 0 || $hardware !== null) {
                throw new \InvalidArgumentException('Remux deployments run no video or audio encoding slots and no hardware encoder.');
            }
            self::assertWithin($sharedCopySlots, self::MAXIMUM_COPY_SLOTS, 'copy slots');
            [$this->videoEncoderSlots, $this->audioEncoderSlots] = [0, 0];

            return;
        }
        if ($sharedCopySlots !== 0) {
            throw new \InvalidArgumentException('Transcode deployments run video and audio slots, not copy slots.');
        }
        [$this->videoEncoderSlots, $this->audioEncoderSlots] = [$videoEncoderSlots ?? self::defaultSlots('video', $hardware), $audioEncoderSlots ?? self::defaultSlots('audio', $hardware)];
        if ($hardware !== null && $this->videoEncoderSlots === 0) {
            throw new \InvalidArgumentException('A hardware encoder serves video jobs; configure video slots above zero or remove the hardware encoder.');
        }
        $this->mediaSlotBounds()?->assertAccepts($this->videoEncoderSlots, $this->audioEncoderSlots);
    }

    /** Builds the profile from its serialized form; an empty value is the default transcode deployment. */
    public static function resolve(string $serialized): self
    {
        return $serialized === '' ? new self() : self::fromJson($serialized);
    }

    public static function fromEnvironment(): self
    {
        $serialized = $_SERVER['BAANDER_DEPLOYMENT_PROFILE'] ?? $_ENV['BAANDER_DEPLOYMENT_PROFILE'] ?? getenv('BAANDER_DEPLOYMENT_PROFILE');
        if ($serialized !== false && !is_string($serialized)) {
            throw new \InvalidArgumentException('The deployment profile environment value requires a string.');
        }

        return self::resolve($serialized === false ? '' : $serialized);
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
        $expected = get_object_vars(new self());
        if (array_diff_key($fields, $expected) !== [] || array_diff_key($expected, $fields) !== []) {
            throw new \InvalidArgumentException('Deployment profile has unknown or missing settings.');
        }
        foreach ($fields as $name => $field) {
            if (match ($name) { 'capability' => !is_string($field), 'hardware' => $field !== null && !$field instanceof \stdClass, default => !is_int($field) }) {
                throw new \InvalidArgumentException('Deployment profile requires a string capability, a hardware object or null, and JSON integer settings.');
            }
        }
        $fields['hardware'] = $fields['hardware'] === null ? null : HardwareEncoder::fromJsonObject($fields['hardware']);
        $profile = new self(
            $fields['capability'],
            $fields['webWorkers'],
            $fields['doctrinePoolSlots'],
            $fields['ordinaryConsumerLimit'],
            $fields['sharedCopySlots'],
            $fields['videoEncoderSlots'],
            $fields['audioEncoderSlots'],
            $fields['hardware'],
        );
        if ($fields != get_object_vars($profile)) {
            throw new \InvalidArgumentException('Deployment profile settings disagree with its capability.');
        }

        return $profile;
    }

    /** @throws \InvalidArgumentException naming the setting and its bound */
    private static function assertWithin(int $value, int $maximum, string $setting): void
    {
        if ($value < 1 || $value > $maximum) {
            throw new \InvalidArgumentException(sprintf('Deployment size requires between 1 and %d %s.', $maximum, $setting));
        }
    }

    private static function defaultSlots(string $kind, ?HardwareEncoder $hardware): int
    {
        return match (true) {
            $kind === 'audio' => self::DEFAULT_AUDIO_SLOTS,
            $hardware !== null => self::DEFAULT_HARDWARE_VIDEO_SLOTS,
            default => self::DEFAULT_SOFTWARE_VIDEO_SLOTS,
        };
    }

    /** Whether the deployment runs stream copy only, from a shared pool of copy slots. */
    public function isRemux(): bool
    {
        return $this->capability === 'remux';
    }

    /** The video and audio slot maximums of a transcode deployment; remux runs copy slots instead. */
    public function mediaSlotBounds(): ?MediaSlotBounds
    {
        return $this->isRemux() ? null : new MediaSlotBounds($this->webWorkers, $this->doctrinePoolSlots, $this->ordinaryConsumerLimit);
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
     * Native client identifiers a media job of the kind may run as, one per configured slot. A
     * remux deployment's shared copy pool serves both kinds from the same identifiers.
     *
     * @return list<string>
     */
    public function mediaClientIds(string $kind): array
    {
        if (!in_array($kind, ['video', 'audio'], true)) {
            throw new \InvalidArgumentException('Unknown media job kind.');
        }
        [$prefix, $slots] = $this->isRemux()
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

        return $this->isRemux() ? $caps : $caps + array_fill_keys($this->mediaClientIds('audio'), self::MEDIA_JOB_COMMANDS);
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
