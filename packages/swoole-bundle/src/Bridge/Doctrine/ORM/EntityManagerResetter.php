<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Doctrine\ORM;

use Doctrine\Persistence\ObjectManager;
use Psr\Log\LoggerInterface;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;
use UnexpectedValueException;

final class EntityManagerResetter implements Resetter
{
    public function __construct(
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function reset(object $service): void
    {
        if (!$service instanceof ObjectManager) {
            throw new UnexpectedValueException(
                sprintf('Invalid service - expected %s, got %s', ObjectManager::class, $service::class)
            );
        }

        // Use error_log() instead of the pooled logger to avoid re-entrant pool
        // acquisition during release. The reset() method is called inside
        // BaseServicePool::releaseFromCoroutine(), which holds a pool slot.
        // If the logger is also a pooled service, calling $logger->debug()
        // would try to acquire ANOTHER pool slot — potentially deadlocking
        // when the pool is near capacity (e.g., long-lived streaming coroutines).
        error_log(sprintf(
            '[swoole] Resetting Doctrine EntityManager: %s',
            $service::class,
        ));

        $service->clear();
    }
}
