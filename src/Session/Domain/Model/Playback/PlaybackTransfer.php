<?php

declare(strict_types=1);

namespace App\Session\Domain\Model\Playback;

use DomainException;

/** The source stays authoritative until a matching target acknowledgement. */
final class PlaybackTransfer
{
    public function __construct(
        public readonly string $attemptId,
        public readonly ?string $source,
        public readonly string $target,
        public readonly string $desiredState,
        private float $cursor,
        private string $phase = 'preparing',
    ) {
        if (!is_finite($cursor) || $cursor < 0) {
            throw new DomainException('A transfer requires a finite source cursor.');
        }
        if (PlaybackState::tryFrom($desiredState) === null) {
            throw new DomainException('Invalid transfer playback state.');
        }
    }

    public static function begin(string $attemptId, ?string $source, string $target, string $desiredState, float $position): self
    {
        return new self($attemptId, $source, $target, $desiredState, $position);
    }

    public function acknowledge(string $outcome, ?float $position = null): void
    {
        if ($outcome === 'rejected') {
            // An explicit refusal settles the transfer: a destination that refuses to
            // prepare or start is not playing, and a source that refuses to quiesce
            // keeps playing as the authoritative runner. Only a destination refusing
            // to stop while it may already be playing stays uncertain.
            $this->phase = match ($this->phase) {
                'preparing', 'quiescing', 'starting' => 'failed',
                'uncertain' => 'uncertain',
                default => throw new DomainException('Transfer is already terminal.'),
            };
            return;
        }
        if ($outcome === 'quiesced' && ($position === null || !is_finite($position) || $position < 0)) {
            throw new DomainException('Quiescence must report a finite source cursor.');
        }
        $this->phase = match ([$this->phase, $outcome]) {
            ['preparing', 'prepared'] => $this->source === null ? 'starting' : 'quiescing',
            ['quiescing', 'quiesced'] => 'starting',
            ['starting', 'started'] => 'committed',
            ['uncertain', 'stopped'] => 'failed',
            default => throw new DomainException('Acknowledgement does not match the transfer phase.'),
        };
        if ($outcome === 'quiesced') {
            $this->cursor = $position;
        }
    }

    public function expire(): void
    {
        $this->phase = match ($this->phase) {
            'preparing' => 'failed',
            'quiescing', 'starting', 'uncertain' => 'uncertain',
            default => $this->phase,
        };
    }

    public function state(): string
    {
        return $this->phase;
    }
    public function position(): float
    {
        return $this->cursor;
    }
    public function command(): ?string
    {
        return match ($this->phase) {
            'preparing' => 'prepare',
            'quiescing' => 'quiesce',
            'starting' => 'start',
            'uncertain' => 'stop',
            default => null,
        };
    }
    public function commandEndpoint(): string
    {
        return $this->phase === 'quiescing' ? ($this->source ?? $this->target) : $this->target;
    }
}
