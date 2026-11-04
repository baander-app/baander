<?php

declare(strict_types=1);

namespace App\Transcode\Infrastructure\Worker;

use App\Transcode\Infrastructure\FFmpeg\HardwareEncoderUnavailable;
use Symfony\Component\DependencyInjection\Attribute\Exclude;

/** Names why media control failed, for the structured report it writes to stderr. */
#[Exclude]
final class MediaWorkerFailure
{
    /**
     * The report otherwise carries only the failure's class and location, because messages can
     * carry credentials. A hardware encoder failure names its reason, encoder, device and the test
     * encode's output, which hold none.
     *
     * @return array<string, string>
     */
    public static function reason(\Throwable $error): array
    {
        if (!$error instanceof HardwareEncoderUnavailable) {
            return [];
        }

        return ['reason' => HardwareEncoderUnavailable::REASON, 'encoder' => $error->encoder, 'device' => $error->device, 'detail' => $error->getMessage()];
    }
}
