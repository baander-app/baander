<?php

declare(strict_types=1);

namespace App\Transcode\Application\DTO;

/** Why a client's playback sessions are no longer held. */
enum PlaybackEndReason: string
{
    /** The sessions ended without a fault, by stale-session expiry or with their jobs: the client may start again. */
    case Expired = 'expired';
    /** An encode failed: starting it again would fail again. */
    case Failed = 'failed';
    /** The sessions were cancelled on purpose: starting again would undo the cancel. */
    case Cancelled = 'cancelled';
}
