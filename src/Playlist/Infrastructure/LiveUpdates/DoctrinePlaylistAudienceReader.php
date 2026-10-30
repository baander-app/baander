<?php

declare(strict_types=1);

namespace App\Playlist\Infrastructure\LiveUpdates;

use App\Playlist\Application\Port\PlaylistAudienceReader;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class DoctrinePlaylistAudienceReader implements PlaylistAudienceReader
{
    public function __construct(private Connection $connection) {}

    public function readablePlaylistIds(Uuid $principal, bool $administrator): array
    {
        if ($administrator) {
            return $this->connection->fetchFirstColumn('SELECT id::text FROM playlists ORDER BY id');
        }

        return $this->connection->fetchFirstColumn(
            'SELECT p.id::text FROM playlists p WHERE p.user_id = ? OR EXISTS (SELECT 1 FROM playlist_collaborators c WHERE c.playlist_id = p.id AND c.user_id = ?) ORDER BY p.id',
            [$principal->toString(), $principal->toString()],
        );
    }
}
