<?php

declare(strict_types=1);

namespace Baander\Tooling\Build;

final readonly class BuildOptions
{
    /** @param list<string> $additionalTags
     * @param list<string> $cacheFrom
     * @param list<string> $cacheTo
     */
    public function __construct(
        public bool $buildx = false,
        public array $additionalTags = [],
        public array $cacheFrom = [],
        public array $cacheTo = [],
    ) {
        if (!$buildx && ($cacheFrom !== [] || $cacheTo !== [])) {
            throw new \InvalidArgumentException('Build cache exporters require buildx.');
        }
    }
}
