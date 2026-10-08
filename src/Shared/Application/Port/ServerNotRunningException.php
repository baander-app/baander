<?php

declare(strict_types=1);

namespace App\Shared\Application\Port;

final class ServerNotRunningException extends ServerControlException
{
    public const string MESSAGE = 'no web server is running in this container';

    public function __construct()
    {
        parent::__construct(self::MESSAGE);
    }
}
