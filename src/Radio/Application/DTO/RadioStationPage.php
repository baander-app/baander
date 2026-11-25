<?php

declare(strict_types=1);

namespace App\Radio\Application\DTO;

/** A page of the station list and the number of stations that match its filters. */
final readonly class RadioStationPage
{
    /** @param list<array<string, mixed>> $stations */
    public function __construct(
        public array $stations,
        public int $total,
    ) {
    }
}
