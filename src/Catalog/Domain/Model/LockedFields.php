<?php

declare(strict_types=1);

namespace App\Catalog\Domain\Model;

use InvalidArgumentException;

/**
 * The field lock rules Album, Song and Artist share.
 *
 * A locked field keeps its value: an edit that gives it a value is rejected as a whole. Only the
 * aggregate's own lockable fields can be locked or unlocked.
 *
 * @internal used by the catalog aggregates only
 */
final class LockedFields
{
    /**
     * @param string[]             $lockable
     * @param 'lock'|'unlock'      $action
     *
     * @throws InvalidArgumentException when the field is not one of the lockable fields
     */
    public static function assertLockable(array $lockable, string $field, string $action): void
    {
        if (!in_array($field, $lockable, true)) {
            throw new InvalidArgumentException(sprintf('Cannot %s unknown field "%s".', $action, $field));
        }
    }

    /**
     * @param string[]             $locked the fields locked now
     * @param array<string, mixed> $values the edit, by field name; null leaves a field as it is
     *
     * @throws InvalidArgumentException naming the first locked field the edit gives a value
     */
    public static function assertUnlocked(array $locked, array $values): void
    {
        foreach ($values as $field => $value) {
            if ($value !== null && in_array($field, $locked, true)) {
                throw new InvalidArgumentException(sprintf('Field "%s" is locked and cannot be updated.', $field));
            }
        }
    }
}
