<?php

declare(strict_types=1);

namespace App\Session\Application\Port;

enum PlaybackOperation: string
{
    case Endpoint = 'endpoint';
    case Transfer = 'transfer';
    case Acknowledge = 'ack';
    case Observe = 'observe';
    case Control = 'control';
    case Reconcile = 'reconcile';
    case Snapshot = 'snapshot';
    case RecoverLocal = 'recover-local';
    case Close = 'close';
    case Override = 'override';
}
