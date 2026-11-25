<?php

declare(strict_types=1);

namespace App\Shared\Interface\Validator;

use Symfony\Component\Validator\Constraint;

/**
 * Bounds a decoded JSON value by the bytes of its compact encoding: UTF-8 and slashes unescaped,
 * as a browser's JSON.stringify writes it. A value that cannot be encoded, such as a float
 * overflowed to infinity, is refused too.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE)]
final class JsonEncodedSize extends Constraint
{
    public string $message = 'This value must encode to at most {{ limit }} bytes of JSON.';

    public string $unencodableMessage = 'This value cannot be encoded as JSON.';

    /** @param list<string>|null $groups */
    public function __construct(
        public readonly int $max,
        ?array $groups = null,
        mixed $payload = null,
    ) {
        parent::__construct(null, $groups, $payload);
    }
}
