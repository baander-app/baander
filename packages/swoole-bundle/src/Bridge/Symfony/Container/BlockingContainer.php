<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container;

use Override;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Component\Locking\Channel\ChannelMutexFactory;
use SwooleBundle\SwooleBundle\Component\Locking\RecursiveOwner\RecursiveOwnerMutex;
use SwooleBundle\SwooleBundle\Component\Locking\RecursiveOwner\RecursiveOwnerMutexFactory;
use Symfony\Component\DependencyInjection\Container;

abstract class BlockingContainer extends Container
{
    protected static RecursiveOwnerMutex $mutex;

    protected static bool $isMutexInitialized = false;

    protected static string $buildContainerNs = '';

    #[Override]
    public function get(string $id, int $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE): ?object
    {
        // Guard against uninitialized mutex — can occur after worker fork,
        // HMR reload, or when initializeContainer() hasn't been called yet.
        // Fall back to non-blocking parent::get() when no mutex is available.
        if (!self::$isMutexInitialized) {
            return parent::get($id, $invalidBehavior);
        }
        try {
            self::$mutex->acquire();
            $service = parent::get($id, $invalidBehavior);
        } finally {
            self::$mutex->release();
        }

        return $service;
    }

    public static function setBuildContainerNs(string $buildContainerNs): void
    {
        self::$buildContainerNs = $buildContainerNs;
    }

    public static function initializeMutex(Swoole $swoole): void
    {
        if (self::$isMutexInitialized) {
            return;
        }

        self::$mutex = (new RecursiveOwnerMutexFactory($swoole, new ChannelMutexFactory()))->newMutex();
        self::$isMutexInitialized = true;
    }
}
