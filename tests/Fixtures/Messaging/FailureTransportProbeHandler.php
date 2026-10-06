<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Messaging;

final class FailureTransportProbeHandler
{
    public function __invoke(FailureTransportProbe $probe): void
    {
        if ($probe->fail) {
            throw new \RuntimeException(sprintf('Probe "%s" failed.', $probe->label));
        }
    }
}
