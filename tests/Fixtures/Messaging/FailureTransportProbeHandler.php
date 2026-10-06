<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

final class FailureTransportProbeHandler
{
    public function __invoke(FailureTransportProbe $probe): void
    {
        if (!$probe->fail) {
            return;
        }
        $message = sprintf('Probe "%s" failed.', $probe->label);
        // A label with this prefix exercises the path that skips the retry strategy.
        if (str_starts_with($probe->label, 'unrecoverable')) {
            throw new UnrecoverableMessageHandlingException($message);
        }
        throw new \RuntimeException($message);
    }
}
