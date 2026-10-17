<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

use App\Transcode\Domain\ValueObject\AudioProfile;

#[\Symfony\Component\DependencyInjection\Attribute\Exclude]
final readonly class DesiredTranscodeState
{
    /** @param list<string> $audioLanguages */
    public function __construct(
        public AudioProfile $audioProfile,
        public string $status = 'running',
        public float $position = 0.0,
        public int $segmentIndex = 0,
        public array $audioLanguages = [],
    ) {
        if (!in_array($status, ['running', 'paused', 'cancelled'], true)
            || !is_finite($position) || $position < 0 || $segmentIndex < 0
            || count($audioLanguages) > 64
        ) {
            throw new \InvalidArgumentException('Invalid desired transcode state.');
        }
        foreach ($audioLanguages as $language) {
            if (strlen($language) > 64) {
                throw new \InvalidArgumentException('Invalid audio language.');
            }
        }
    }
}
