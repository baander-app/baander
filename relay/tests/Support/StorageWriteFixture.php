<?php

declare(strict_types=1);

namespace Baander\RegistryTests;

final class StorageWriteFixture
{
    /** @var resource|null */
    public $context;
    public static string $warning = '';
    public static bool $closed = false;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        self::$closed = false;
        return true;
    }

    public function stream_write(string $data): int
    {
        trigger_error(self::$warning, E_USER_WARNING);
        return 0;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
        self::$closed = true;
    }
}
