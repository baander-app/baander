<?php

namespace Baander\Transcoder\Pipeline;

final class ContextValidator
{
    /**
     * Validate context structure for DASH transcoding.
     */
    public function validateDASHContext(array $context): void
    {
        if (empty($context['outputDirectory'])) {
            throw new \InvalidArgumentException('DASH context is missing the "outputDirectory" key.');
        }
    }

    /**
     * Validate context structure for HLS transcoding.
     */
    public function validateHLSContext(array $context): void
    {
        if (empty($context['outputDirectory'])) {
            throw new \InvalidArgumentException('HLS context is missing the "outputDirectory" key.');
        }
    }
}