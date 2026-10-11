<?php

declare(strict_types=1);

namespace App\Tests\Unit\Recommendation\Infrastructure\Swoole;

use App\Recommendation\Infrastructure\Swoole\RecommendationJobCleanupService;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Driver\PDO\Exception as PdoDriverException;
use Doctrine\DBAL\Exception\ConnectionException;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Event\ServerStartedEvent;

final class RecommendationJobCleanupServiceTest extends TestCase
{
    public function testAnUnreachableDatabaseIsLoggedOnceAndTheServerStarts(): void
    {
        $connection = $this->createMock(Connection::class);
        $connection->expects(self::once())->method('transactional')->willThrowException(
            new ConnectionException(PdoDriverException::new(new \PDOException('could not translate host name "postgres" to address')), null),
        );
        $entityManager = $this->createStub(EntityManagerInterface::class);
        $entityManager->method('getConnection')->willReturn($connection);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects(self::once())->method('warning')->with(
            'Could not clean up orphaned recommendation jobs at server start',
            self::callback(static fn (array $context): bool => str_contains($context['error'], 'could not translate host name')),
        );
        $logger->expects(self::never())->method('info');

        (new RecommendationJobCleanupService($entityManager, $logger))->onServerStarted(
            (new \ReflectionClass(ServerStartedEvent::class))->newInstanceWithoutConstructor(),
        );
    }
}
