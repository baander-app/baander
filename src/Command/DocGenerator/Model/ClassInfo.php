<?php

declare(strict_types=1);

namespace App\Command\DocGenerator\Model;

final readonly class ClassInfo
{
    /**
     * @param string $fqcn Fully qualified class name
     * @param string $shortName Short class name
     * @param string $namespace Namespace
     * @param string $layer DDD layer (Domain, Application, Infrastructure, Interface, or actual dir name)
     * @param string $description Docblock description
     * @param list<string> $interfaces Implemented interface short names
     * @param list<string> $properties Public property names
     * @param bool $isAggregateRoot Has matching repository interface
     * @param bool $isValueObject Is a value object (enum or final readonly without repo)
     * @param bool $isEnum Is a PHP enum
     */
    public function __construct(
        public string $fqcn,
        public string $shortName,
        public string $namespace,
        public string $layer,
        public string $description = '',
        public array $interfaces = [],
        public array $properties = [],
        public bool $isAggregateRoot = false,
        public bool $isValueObject = false,
        public bool $isEnum = false,
    ) {}
}
