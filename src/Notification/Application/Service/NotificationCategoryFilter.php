<?php

declare(strict_types=1);

namespace App\Notification\Application\Service;

use App\Notification\Domain\ValueObject\NotificationCategory;

/**
 * Validates a webhook category filter: null (all categories) or a list of supported category values.
 */
final class NotificationCategoryFilter
{
    public static function isValid(mixed $filter): bool
    {
        if ($filter === null) {
            return true;
        }
        if (!is_array($filter) || !array_is_list($filter)) {
            return false;
        }
        foreach ($filter as $category) {
            if (!is_string($category) || NotificationCategory::tryFrom($category) === null) {
                return false;
            }
        }

        return true;
    }
}
