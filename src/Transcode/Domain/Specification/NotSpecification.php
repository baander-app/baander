<?php
declare(strict_types=1);
namespace App\Transcode\Domain\Specification;

final readonly class NotSpecification implements SpecificationInterface
{
    public function __construct(
        private SpecificationInterface $specification,
    ) {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        return !$this->specification->isSatisfiedBy($candidate);
    }
}
