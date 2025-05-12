<?php

namespace Baander\Transcoder\Pipeline;

interface PipelineStepInterface
{
    /**
     * Execute the step.
     *
     * @param PipelineContext $context
     * @param callable|null $next Callable for the next pipeline step.
     * @return mixed
     */
    public function process(PipelineContext $context, ?callable $next): mixed;
}