<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Auth;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\Connection as DriverConnection;
use Doctrine\DBAL\Driver\Middleware;
use Doctrine\DBAL\Driver\Middleware\AbstractConnectionMiddleware;
use Doctrine\DBAL\Driver\Middleware\AbstractDriverMiddleware;
use Doctrine\DBAL\Driver\PDO\Exception;
use PDOException;
use SensitiveParameter;

/**
 * A lost commit acknowledgment at the driver: PostgreSQL commits, then the driver
 * reports the connection lost (SQLSTATE 08006), as a network failure after the commit
 * does. Fires once per IssuanceFaults::COMMIT_THROW_ONCE flag.
 */
final class CommitFaultMiddleware implements Middleware
{
    public function wrap(Driver $driver): Driver
    {
        return new class ($driver) extends AbstractDriverMiddleware {
            public function connect(
                #[SensitiveParameter]
                array $params,
            ): DriverConnection {
                return new class (parent::connect($params)) extends AbstractConnectionMiddleware {
                    public function commit(): void
                    {
                        parent::commit();
                        $directory = IssuanceFaults::directory();
                        if ($directory !== null && IssuanceFaults::consume($directory, IssuanceFaults::COMMIT_THROW_ONCE)) {
                            $lost = new PDOException('injected lost commit acknowledgment');
                            $lost->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];

                            throw Exception::new($lost);
                        }
                    }
                };
            }
        };
    }
}
