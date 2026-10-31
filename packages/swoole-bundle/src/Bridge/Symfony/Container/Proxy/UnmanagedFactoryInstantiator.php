<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Proxy;

use ProxyManager\Factory\AccessInterceptorValueHolderFactory;
use ProxyManager\Proxy\AccessInterceptorInterface;
use RuntimeException;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Initializer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Resetter;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\ServicePoolContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\ServicePool\UnmanagedFactoryServicePool;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Locking\Mutex;
use SwooleBundle\SwooleBundle\Component\Locking\MutexFactory;

final readonly class UnmanagedFactoryInstantiator
{
    private Mutex $instantiation;

    public function __construct(
        private AccessInterceptorValueHolderFactory $proxyFactory,
        private Instantiator $instantiator,
        private ServicePoolContainer $servicePoolContainer,
        private MutexFactory $limitLocking,
        private MutexFactory $newInstanceLocking,
        private Swoole $swoole,
        MutexFactory $instantiationLocking,
    ) {
        $this->instantiation = $instantiationLocking->newMutex();
    }

    /**
     * @template RealObjectType of object
     * @param RealObjectType $instance
     * @param array<array{
     *     factoryMethod: string,
     *     returnType: class-string,
     *     limit?: int,
     *     resetter?: Resetter,
     *     initializer?: Initializer
     * }> $factoryConfigs
     * @return RealObjectType
     */
    public function newInstance(object $instance, array $factoryConfigs, int $globalInstancesLimit): object
    {
        /**
         * @var array<string, callable(
         *  AccessInterceptorInterface<RealObjectType>&RealObjectType=,
         *  RealObjectType=,
         *  string=,
         *  array<string, mixed>=,
         *  bool=
         * ): mixed> $prefixInterceptors
         */
        $prefixInterceptors = [];
        $servicePoolContainer = $this->servicePoolContainer;
        $instantiator = $this->instantiator;
        $swoole = $this->swoole;

        if (empty($factoryConfigs)) {
            throw new RuntimeException(sprintf('Factory methods missing for class %s', $instance::class));
        }

        foreach ($factoryConfigs as $factoryConfig) {
            $factoryMethod = $factoryConfig['factoryMethod'];

            if (!method_exists($instance, $factoryMethod)) {
                throw new RuntimeException(sprintf('Missing method %s in class %s', $factoryMethod, $instance::class));
            }

            $mutex = $this->newInstanceLocking->newMutex();
            /**
             * @var callable(
             *  AccessInterceptorInterface<RealObjectType>&RealObjectType=,
             *  RealObjectType=,
             *  string=,
             *  array<string, mixed>=,
             *  bool=
             * ): mixed $interceptor
             * @phpstan-ignore varTag.nativeType
             */
            $interceptor = function (
                object $proxy,
                object $instance,
                string $method,
                array $params,
                bool &$returnEarly, // phpcs:ignore
            ) use ($servicePoolContainer, $instantiator, $factoryConfig, $mutex, $globalInstancesLimit, $swoole) {
                $returnEarly = true;
                $factoryInstantiator = static function () use ($instance, $method, $params, $mutex): object {
                    $mutex->acquire();

                    try {
                        $service = $instance->{$method}(...array_values($params));
                    } finally {
                        $mutex->release();
                    }

                    return $service;
                };
                $this->instantiation->acquire();
                try {
                    $limitMutex = $this->limitLocking->newMutex();
                    $instancesLimit = $factoryConfig['limit'] ?? $globalInstancesLimit;
                    $resetter = $factoryConfig['resetter'] ?? null;
                    $initializer = $factoryConfig['initializer'] ?? null;
                    $servicePool = new UnmanagedFactoryServicePool(
                        $factoryInstantiator,
                        $swoole,
                        $limitMutex,
                        $instancesLimit,
                        $resetter,
                        $initializer,
                    );
                    $servicePoolContainer->addPool($servicePool);

                    return $instantiator->newInstance($servicePool, $factoryConfig['returnType']);
                } finally {
                    $this->instantiation->release();
                }
            };

            $prefixInterceptors[$factoryMethod] = $interceptor;
        }

        $this->instantiation->acquire();
        try {
            return $this->proxyFactory->createProxy($instance, $prefixInterceptors);
        } finally {
            $this->instantiation->release();
        }
    }
}
