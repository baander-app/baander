<?php

declare(strict_types=1);

namespace App\Shared\Application\LogViewer;

interface LogViewerInterface
{
    /** @return array<string, mixed> */
    public function sources(): array;

    /** @return array<string, mixed> */
    public function entries(LogQuery $query): array;

    /** @return array<string, mixed> */
    public function detail(string $id): array;

    /** @return array<string, mixed> */
    public function query(LogSqlQuery $query): array;
}
