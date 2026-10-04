<?php

declare(strict_types=1);

namespace App\Shared\Application\Messaging;

/** Feature-owned mappings for the versioned, framework-independent message format. */
interface MessagePayloadCodecInterface
{
    /** @return array<string, class-string> Wire type to message class. */
    public function types(): array;

    /** @return list<string> Ordered fields for this wire type. */
    public function fields(string $type): array;

    /** @return list<mixed> Ordered field values, containing only JSON data. */
    public function encode(object $message): array;

    /** @param array<string, mixed> $p Strictly validated field names. */
    public function decode(string $type, array $p): object;
}
