<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use App\Auth\Application\Port\LoginFailureThrottleInterface;
use App\Shared\Domain\Model\Email;

/** Records charged failures and answers a configurable wait. */
final class RecordingLoginFailureThrottle implements LoginFailureThrottleInterface
{
    public ?int $retryAfter = null;

    /** @var list<string> */
    public array $failures = [];

    public function retryAfter(Email $email): ?int
    {
        return $this->retryAfter;
    }

    public function recordFailure(Email $email): void
    {
        $this->failures[] = $email->toString();
    }
}
