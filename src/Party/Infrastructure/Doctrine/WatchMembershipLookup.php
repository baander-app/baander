<?php

declare(strict_types=1);

namespace App\Party\Infrastructure\Doctrine;

use App\Party\Application\Port\WatchMembershipPortInterface;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class WatchMembershipLookup implements WatchMembershipPortInterface
{
    public function __construct(private Connection $connection) {}

    public function isWatching(Uuid $memberId, Uuid $videoId): bool
    {
        return $this->connection->fetchOne(<<<'SQL'
            SELECT 1 FROM party_sessions session
            WHERE session.is_active = TRUE AND session.video_id = :video
              AND (session.host_user_id = :member OR EXISTS (
                SELECT 1 FROM party_members membership
                WHERE membership.session_id = session.id AND membership.user_id = :member
              ))
            LIMIT 1
            SQL, ['member' => $memberId->toString(), 'video' => $videoId->toString()]) !== false;
    }
}
