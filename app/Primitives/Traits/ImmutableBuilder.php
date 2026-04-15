<?php

namespace App\Primitives\Traits;

/**
 * Base trait for immutable builder classes.
 *
 * All builder methods must return new instances via clone(), never $this.
 * This enables safe parallel operations on the same base instance.
 */
trait ImmutableBuilder
{
    /**
     * Create an independent copy of this instance.
     */
    public function clone(): static
    {
        return clone $this;
    }
}
