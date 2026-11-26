<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Doctrine;

use App\Shared\Application\Exception\MemberInputBoundException;
use App\Shared\Application\Exception\TransactionDeadlockException;
use App\Shared\Application\Port\TransactionPortInterface;
use Doctrine\DBAL\Exception\DeadlockException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\ContextualProxy;

final readonly class DoctrineTransaction implements TransactionPortInterface
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private Swoole $swoole,
    ) {
    }

    public function run(callable $operation): mixed
    {
        $manager = $this->doctrine->getManager();
        if (!$manager instanceof EntityManagerInterface) {
            throw new \LogicException('Producer transactions require an ORM entity manager.');
        }

        try {
            return $manager->getConnection()->transactional(static function () use ($operation, $manager): mixed {
                $result = $operation();
                $manager->flush();
                return $result;
            });
        } catch (\Throwable $error) {
            // A database rollback leaves managed objects reflecting aborted writes.
            // ORM flush failures can also close the manager entirely.
            if ($manager->isOpen()) {
                $manager->clear();
            } elseif ($manager instanceof ContextualProxy) {
                // Discard only this context's closed manager. Symfony's lazy
                // reset would unset the shared proxy's service-pool property,
                // invalidating every repository that already holds the proxy.
                $manager->getServicePool()->releaseFromCoroutine($this->swoole->getCoroutineId());
            } else {
                $this->doctrine->resetManager();
            }
            // DBAL raises PostgreSQL's deadlock (40P01) and serialization failure (40001) as
            // DeadlockException, and a check violation (23514) as a plain DriverException. Both are
            // converted after the rollback, whether a statement of the operation or its flush failed.
            throw match (true) {
                $error instanceof DeadlockException => new TransactionDeadlockException($error),
                MemberInputBoundViolation::matches($error) => new MemberInputBoundException($error),
                default => $error,
            };
        }
    }
}
