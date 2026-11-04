<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use Symfony\Component\DependencyInjection\Attribute\Exclude;

/**
 * The hardware encoder an operator configures for video jobs: the accelerator and, where the
 * accelerator opens a device node, its path. The execution worker receives that device and the
 * device's group; NVENC receives the host's NVIDIA GPUs through the container runtime instead.
 */
#[Exclude]
final readonly class HardwareEncoder
{
    public const array ACCELERATORS = ['vaapi', 'qsv', 'nvenc', 'amf'];

    public function __construct(public string $accelerator, public ?string $device = null)
    {
        if (!in_array($accelerator, self::ACCELERATORS, true)) {
            throw new \InvalidArgumentException('Hardware encoder accelerator must be one of ' . implode(', ', self::ACCELERATORS) . '.');
        }
        if (!$this->usesDevice()) {
            if ($device !== null) {
                throw new \InvalidArgumentException('The nvenc accelerator takes no device path; the NVIDIA container runtime supplies its devices.');
            }
            return;
        }
        if ($device === null) {
            throw new \InvalidArgumentException(sprintf('The %s accelerator requires a device path such as /dev/dri/renderD128.', $accelerator));
        }
        if (strlen($device) > 255 || preg_match('~\A/dev/(?:[A-Za-z0-9_.-]+/)*[A-Za-z0-9_.-]+\z~D', $device) !== 1
            || preg_match('~(?:^|/)\.\.?(?:/|$)~', $device) !== 0) {
            throw new \InvalidArgumentException('Hardware encoder device requires a canonical absolute path under /dev.');
        }
    }

    /** Builds the encoder from its decoded JSON object, rejecting unknown or mistyped fields. */
    public static function fromJsonObject(mixed $value): self
    {
        if (!$value instanceof \stdClass) {
            throw new \InvalidArgumentException('Hardware encoder requires a JSON object.');
        }
        $fields = get_object_vars($value);
        ksort($fields);
        if (array_keys($fields) !== ['accelerator', 'device'] || !is_string($fields['accelerator'])
            || !(is_string($fields['device']) || $fields['device'] === null)) {
            throw new \InvalidArgumentException('Hardware encoder requires exactly a string accelerator and a string or null device.');
        }

        return new self($fields['accelerator'], $fields['device']);
    }

    /** Whether the accelerator opens a device node that the execution worker must receive. */
    public function usesDevice(): bool
    {
        return $this->accelerator !== 'nvenc';
    }
}
