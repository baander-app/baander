<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Kernel;

use RuntimeException;
use SwooleBundle\SwooleBundle\Bridge\Swoole\Swoole;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Bundle\DependencyInjection\ContainerConstants;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\BlockingContainer;
use SwooleBundle\SwooleBundle\Bridge\Symfony\Container\Modifier\Modifier;
use SwooleBundle\SwooleBundle\Reflection\ClassModifier;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @phpstan-ignore trait.unused
 */
trait CoroutinesSupportingKernel
{
    /**
     * for the coroutines to work properly, the kernel __clone method has to be overriden,
     * otherwise the container wouldn't be shared between requests.
     */
    public function __clone()
    {
        // cloned kernel should have a fresh container and other state
    }

    /**
     * this overrides the container class to a container, which is able to block the first instatiation
     * of requested service instance (because class autoloading is IO operation, which switches coroutine context).
     * the blocking ensures that only one service instance will be created concurrently and it will be registered
     * correctly in the container.
     */
    protected function getContainerBaseClass(): string
    {
        return BlockingContainer::class;
    }

    /**
     * this initializes logic which removes the final flag from proxified classes (if they are final).
     */
    protected function initializeContainer(): void
    {
        $lockDirectory = $this->getBuildDir();
        (new Filesystem())->mkdir($lockDirectory);
        $lockPath = $lockDirectory . DIRECTORY_SEPARATOR . $this->getContainerClass() . '.php.swoole.lock';
        $lock = @fopen($lockPath, 'c+');

        if ($lock === false) {
            throw new RuntimeException(sprintf('Could not open container initialization lock "%s".', $lockPath));
        }

        try {
            if (!flock($lock, LOCK_EX)) {
                throw new RuntimeException(sprintf('Could not acquire container initialization lock "%s".', $lockPath));
            }

            ClassModifier::initialize();
            BlockingContainer::initializeMutex(new Swoole());
            parent::initializeContainer();

            if (!$this->areCoroutinesEnabled()) {
                return;
            }

            if (!$this->container instanceof BlockingContainer) {
                throw new \LogicException('Coroutine support requires a blocking service container.');
            }

            if ($this->container->hasParameter(ContainerConstants::PARAM_COROUTINES_FINAL_CLASSES)) {
                /** @var list<class-string> $finalClasses */
                $finalClasses = $this->container->getParameter(ContainerConstants::PARAM_COROUTINES_FINAL_CLASSES);
                ClassModifier::removeFinalFlagsFromClasses($finalClasses);
            }

            Modifier::modifyContainer($this->container, $this->getCacheDir(), $this->isDebug());
            $this->container->set('kernel_original', $this);
            $this->container->set('kernel', $this->container->get('kernel_proxy'));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function areCoroutinesEnabled(): bool
    {
        if (!$this->container->hasParameter(ContainerConstants::PARAM_COROUTINES_ENABLED)) {
            return false;
        }

        return (bool) $this->container->getParameter(ContainerConstants::PARAM_COROUTINES_ENABLED);
    }
}
