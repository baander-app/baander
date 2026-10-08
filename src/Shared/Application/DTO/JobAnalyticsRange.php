<?php

declare(strict_types=1);

namespace App\Shared\Application\DTO;

use App\Shared\Application\Exception\InvalidInputException;

/**
 * The half-open job creation range [from, to) that job analytics cover. It includes its
 * start and excludes its end, so consecutive ranges count every job once.
 */
final readonly class JobAnalyticsRange
{
    private function __construct(
        public \DateTimeImmutable $from,
        public \DateTimeImmutable $to,
    ) {
    }

    /**
     * Without a start the range begins 24 hours before now; without an end it ends now.
     * A range longer than 90 days ends 90 days after its start.
     *
     * @throws InvalidInputException when the end is not after the start; the details name `to`
     */
    public static function resolve(?\DateTimeImmutable $from, ?\DateTimeImmutable $to, ?\DateTimeImmutable $now = null): self
    {
        $now ??= new \DateTimeImmutable();
        $from ??= $now->modify('-24 hours');
        $to ??= $now;

        if ($to <= $from) {
            throw new InvalidInputException('to must be after from.', ['to' => 'to must be after from.']);
        }

        $maxTo = $from->add(new \DateInterval('P90D'));

        return new self($from, $to > $maxTo ? $maxTo : $to);
    }
}
