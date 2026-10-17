<?php

declare(strict_types=1);

namespace App\Tests\Unit\QoL;

use App\QoL\Application\Port\QoLJobIdentityInterface;

/** Stable distinct public identities for governor fixtures that have no database. */
final class QoLPublicIds implements QoLJobIdentityInterface
{
    public static function forId(string $id): string
    {
        return substr(hash('sha256', $id), 0, 21);
    }

    public function publicIds(array $internalIds): array
    {
        $result = [];
        foreach ($internalIds as $id) {
            $result[$id] = self::forId($id);
        }
        return $result;
    }
}
