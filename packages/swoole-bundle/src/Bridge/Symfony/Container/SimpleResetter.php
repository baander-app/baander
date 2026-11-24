<?php

declare(strict_types=1);

namespace SwooleBundle\SwooleBundle\Bridge\Symfony\Container;

use Throwable;

final readonly class SimpleResetter implements Resetter
{
    /**
     * @param list<string> $resetMethods
     */
    public function __construct(private array $resetMethods) {}

    public function reset(object $service): void
    {
        $failure = null;

        foreach ($this->resetMethods as $resetMethod) {
            try {
                $service->{$resetMethod}();
            } catch (Throwable $error) {
                // A later method can clear state that outlives this instance, which the pool discards on the rethrow.
                $failure ??= $error;
            }
        }

        if ($failure !== null) {
            throw $failure;
        }
    }
}
