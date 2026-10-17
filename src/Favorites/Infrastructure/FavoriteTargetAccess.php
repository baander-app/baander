<?php

declare(strict_types=1);

namespace App\Favorites\Infrastructure;

use App\Favorites\Application\Port\FavoriteTargetAccessInterface;
use App\Favorites\Domain\ValueObject\FavoriteType;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\SecurityBundle\Security;

final readonly class FavoriteTargetAccess implements FavoriteTargetAccessInterface
{
    public function __construct(private Connection $connection, private Security $security)
    {
    }

    public function assertVisible(Uuid $actorId, FavoriteType $type, PublicId $publicId): void
    {
        $administrator = $this->security->isGranted('ROLE_ADMIN');
        $visibleLibrary = $administrator
            ? 'TRUE'
            : 'EXISTS (SELECT 1 FROM user_library_access access WHERE access.user_id = :actor AND access.library_id = album.library_id)';
        $sql = match ($type) {
            FavoriteType::Song => 'SELECT 1 FROM songs target JOIN albums album ON album.id = target.album_id WHERE target.public_id = :public_id AND ' . $visibleLibrary,
            FavoriteType::Album => 'SELECT 1 FROM albums album WHERE album.public_id = :public_id AND ' . $visibleLibrary,
            FavoriteType::Artist => 'SELECT 1 FROM artists target WHERE target.public_id = :public_id AND (EXISTS ('
                . 'SELECT 1 FROM artist_album credit JOIN albums album ON album.id = credit.album_id WHERE credit.artist_id = target.id AND ' . $visibleLibrary . ') OR EXISTS ('
                . 'SELECT 1 FROM artist_song credit JOIN songs song ON song.id = credit.song_id JOIN albums album ON album.id = song.album_id WHERE credit.artist_id = target.id AND ' . $visibleLibrary . '))',
        };
        if ($administrator && $type === FavoriteType::Artist) {
            $sql = 'SELECT 1 FROM artists WHERE public_id = :public_id';
        }
        $parameters = ['public_id' => $publicId->toString()];
        if (!$administrator) {
            $parameters['actor'] = $actorId->toString();
        }
        if ($this->connection->fetchOne($sql, $parameters) === false) {
            throw new NotFoundException('Favorite target not found.');
        }
    }
}
