<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Transcode\Domain\Model\TranscodeSession;
use Symfony\Component\Messenger\Stamp\StampInterface;

final readonly class TranscodeSessionResultStamp implements StampInterface
{
    public function __construct(
        private TranscodeSession $session,
    ) {
    }

    public static function fromResult(mixed $result): ?self
    {
        return $result instanceof TranscodeSession ? new self($result) : null;
    }

    public function getSession(): TranscodeSession
    {
        return $this->session;
    }
}
