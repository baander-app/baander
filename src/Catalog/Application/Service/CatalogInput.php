<?php

declare(strict_types=1);

namespace App\Catalog\Application\Service;

use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Domain\Model\PublicId;

/**
 * Input rules the catalog use cases share.
 */
final class CatalogInput
{
    public const string INVALID_PUBLIC_ID = 'Invalid public ID format.';

    /**
     * @throws InvalidInputException when the value is not a public ID
     */
    public static function publicId(string $value): PublicId
    {
        try {
            return PublicId::fromString($value);
        } catch (\InvalidArgumentException $exception) {
            throw new InvalidInputException(self::INVALID_PUBLIC_ID, previous: $exception);
        }
    }

    /**
     * The fields to lock and unlock, from a complete list that replaces the locked fields
     * and from single fields to lock or unlock. Locks are applied last, after the edit, so a field in both lists ends locked.
     *
     * @param string[]          $current the fields locked now
     * @param list<string>|null $replace
     * @param list<string>      $lock
     * @param list<string>      $unlock
     * @return array{lock: list<string>, unlock: list<string>}
     */
    public static function lockChanges(array $current, ?array $replace, array $lock, array $unlock): array
    {
        if ($replace !== null) {
            $lock = [...$replace, ...$lock];
            $unlock = [...array_values(array_diff($current, $replace)), ...$unlock];
        }

        return [
            'lock' => array_values(array_unique($lock)),
            'unlock' => array_values(array_unique($unlock)),
        ];
    }
}
