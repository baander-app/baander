<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\HttpKernel;

use Symfony\Component\HttpKernel\KernelInterface;

final class KernelCloner
{
    private bool $isBooted = false;

    public function __construct(
        private readonly KernelInterface $kernel,
    ) {}

    public function clone(): KernelInterface
    {
        if (!$this->isBooted) {
            $this->kernel->boot();
            $this->isBooted = true;
        }

        return clone $this->kernel;
    }
}
