<?php
declare(strict_types=1);
namespace App\Transcode\Domain\Specification;

final readonly class OrSpecification implements SpecificationInterface
{
    /**
     * @param SpecificationInterface[] $specifications
     */
    public function __construct(
        private array $specifications,
    ) {
    }

    public function isSatisfiedBy(mixed $candidate): bool
    {
        foreach ($this->specifications as $specification) {
            if ($specification->isSatisfiedBy($candidate)) {
                return true;
            }
        }
        return false;
    }
}
