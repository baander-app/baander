<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\LoginFailureThrottleInterface;
use App\Shared\Domain\Model\Email;

/** Records reserved and refunded failures and answers a configurable wait. */
final class RecordingLoginFailureThrottle implements LoginFailureThrottleInterface
{
    public ?int $retryAfter = null;

    /** @var list<string> the addresses whose reserved failure is still charged */
    public array $failures = [];

    public int $reservations = 0;

    public function reserve(Email $email): ?int
    {
        if ($this->retryAfter !== null) {
            return $this->retryAfter;
        }
        $this->failures[] = $email->toString();
        ++$this->reservations;

        return null;
    }

    public function refund(Email $email): void
    {
        $charged = array_search($email->toString(), $this->failures, true);
        if ($charged === false) {
            throw new \LogicException('A refund must follow a reservation of the same address.');
        }
        unset($this->failures[$charged]);
        $this->failures = array_values($this->failures);
    }
}
