<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Doctrine;

use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManagerInterface;
use Override;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy\ContextualProxy;
use Symfony\Component\DependencyInjection\Container;

final class ContextualRegistry extends Registry
{
    /**
     * @param array<string, string> $connections
     * @param array<string, string> $entityManagers
     */
    public function __construct(
        Container $container,
        array $connections,
        array $entityManagers,
        string $defaultConnection,
        string $defaultEntityManager,
        private readonly Swoole $swoole,
    ) {
        parent::__construct($container, $connections, $entityManagers, $defaultConnection, $defaultEntityManager);
    }

    #[Override]
    protected function resetService($name): void
    {
        if (!$this->container->initialized($name)) {
            return;
        }

        $manager = $this->container->get($name);
        if (!$manager instanceof ContextualProxy || !$manager instanceof EntityManagerInterface) {
            parent::resetService($name);

            return;
        }

        // Native lazy reset would mutate the wrapper shared by every coroutine.
        $manager->close();
        $manager->getServicePool()->releaseFromCoroutine($this->swoole->getCoroutineId());
    }
}
