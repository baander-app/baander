<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Messenger\Stamp;

use App\Transcode\Domain\Model\TranscodeSession;

final readonly class TranscodeSessionResultStamp implements ResultStampInterface
{
    public function __construct(
        private TranscodeSession $session,
    ) {
    }

    public static function fromResult(mixed $result): ?static
    {
        return $result instanceof TranscodeSession ? new self($result) : null;
    }

    public function getSession(): TranscodeSession
    {
        return $this->session;
    }
}
