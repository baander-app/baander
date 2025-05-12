<?php

namespace Baander\Transcoder\Pipeline;

namespace Baander\Transcoder\Pipeline;

final class PipelineProcessor
{
    /** @var callable[]|PipelineStepInterface[] */
    private array $steps = [];

    /**
     * Add a step to the pipeline.
     *
     * @param callable|PipelineStepInterface $step
     * @return self
     */
    public function addStep(callable|PipelineStepInterface $step): self
    {
        $this->steps[] = $step;
        return $this;
    }

    /**
     * Process the pipeline with the given context.
     *
     * @param PipelineContext $context
     * @return mixed
     */
    public function process(PipelineContext $context): mixed
    {
        // Reduce steps into a single callable
        $pipeline = array_reduce(
            array_reverse($this->steps),
            fn ($next, $step) => fn ($context) => $step instanceof PipelineStepInterface
                ? $step->process($context, $next)
                : $step($context, $next),
            fn ($context) => $context
        );

        return $pipeline($context);
    }
}