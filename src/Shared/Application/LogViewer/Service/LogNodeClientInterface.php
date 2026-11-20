<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer\Service;

interface LogNodeClientInterface
{
    /** @return list<string> */
    public function nodes(): array;

    /**
     * @param array<string, scalar|list<string>|null> $parameters
     * @return array<string, mixed>
     */
    public function get(string $node, string $path, array $parameters = []): array;
}
