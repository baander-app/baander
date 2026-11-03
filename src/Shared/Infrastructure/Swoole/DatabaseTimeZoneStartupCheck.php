<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Swoole;

use App\Shared\Infrastructure\Doctrine\DatabaseTimeZoneGuard;
use SwooleBundle\SwooleBundle\Server\Runtime\Bootable;

/**
 * Refuses to start the web server against a database whose default time zone is not UTC.
 *
 * Implements Bootable: boot() runs once in the server master, before the server starts and
 * forks its workers. It checks on a connection of its own and closes it again, so no worker
 * inherits the socket. An unreachable database also refuses the start.
 */
final class DatabaseTimeZoneStartupCheck implements Bootable
{
    public function __construct(
        #[\SensitiveParameter]
        private readonly string $databaseUrl,
    ) {
    }

    public function boot(array $runtimeConfiguration = []): void
    {
        DatabaseTimeZoneGuard::assertUtcDefaultAt($this->databaseUrl);
    }
}
