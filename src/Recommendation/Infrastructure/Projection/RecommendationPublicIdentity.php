<?php

declare(strict_types=1);

namespace App\Recommendation\Infrastructure\Projection;

use App\Recommendation\Application\Port\RecommendationPublicIdentityInterface;
use App\Recommendation\Domain\Model\Recommendation;
use App\Recommendation\Domain\Repository\RecommendationRepositoryInterface;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Domain\Model\PublicId;
use App\Shared\Domain\Model\Uuid;
use Doctrine\DBAL\Connection;

final readonly class RecommendationPublicIdentity implements RecommendationPublicIdentityInterface
{
    public function __construct(private Connection $connection, private RecommendationRepositoryInterface $recommendations) {}

    public function internalEntityId(string $type, string $publicId, Uuid $actorId, bool $administrator): Uuid
    {
        try { $id = PublicId::fromString($publicId); }
        catch (\InvalidArgumentException) { throw new NotFoundException('Recommended entity not found.'); }
        $row = $this->entity($type, 'public_id', $id->toString(), $actorId, $administrator);
        return $row === null ? throw new NotFoundException('Recommended entity not found.') : Uuid::fromString($row['id']);
    }

    public function publicEntityId(string $type, string $internalId, Uuid $actorId, bool $administrator): ?string
    {
        try { $id = Uuid::fromString($internalId); }
        catch (\InvalidArgumentException) { return null; }
        return $this->entity($type, 'id', $id->toString(), $actorId, $administrator)['public_id'] ?? null;
    }

    /** @return array{id: string, public_id: string}|null */
    private function entity(string $type, string $field, string $value, Uuid $actorId, bool $administrator): ?array
    {
        $table = match ($type) { 'song' => 'songs', 'album' => 'albums', 'artist' => 'artists', 'movie' => 'movies', 'video' => 'videos', default => null };
        if ($table === null) { return null; }
        $visibility = match ($type) {
            'album', 'movie' => 'EXISTS (SELECT 1 FROM user_library_access access WHERE access.library_id = entity.library_id AND access.user_id = :actor)',
            'song' => 'EXISTS (SELECT 1 FROM albums album JOIN user_library_access access ON access.library_id = album.library_id WHERE album.id = entity.album_id AND access.user_id = :actor)',
            'artist' => '(EXISTS (SELECT 1 FROM artist_album relation JOIN albums album ON album.id = relation.album_id JOIN user_library_access access ON access.library_id = album.library_id WHERE relation.artist_id = entity.id AND access.user_id = :actor) OR EXISTS (SELECT 1 FROM artist_song relation JOIN songs song ON song.id = relation.song_id JOIN albums album ON album.id = song.album_id JOIN user_library_access access ON access.library_id = album.library_id WHERE relation.artist_id = entity.id AND access.user_id = :actor))',
            'video' => 'EXISTS (SELECT 1 FROM movie_video relation JOIN movies movie ON movie.id = relation.movie_id JOIN user_library_access access ON access.library_id = movie.library_id WHERE relation.video_id = entity.id AND access.user_id = :actor)',
            default => 'FALSE',
        };
        $parameters = ['identity' => $value];
        if (!$administrator) { $parameters['actor'] = $actorId->toString(); }
        $row = $this->connection->fetchAssociative('SELECT entity.id, entity.public_id FROM ' . $table . ' entity WHERE entity.' . $field . ' = :identity' . ($administrator ? '' : ' AND ' . $visibility), $parameters);
        return $row === false ? null : ['id' => (string) $row['id'], 'public_id' => (string) $row['public_id']];
    }

    public function project(Recommendation $recommendation, Uuid $actorId, bool $administrator): ?array
    {
        if (!$administrator && $recommendation->getUserId() !== null && !$recommendation->getUserId()->equals($actorId)) { return null; }
        $source = $this->publicEntityId((string) $recommendation->getSourceType(), $recommendation->getSourceId(), $actorId, $administrator);
        $target = $this->publicEntityId((string) $recommendation->getTargetType(), $recommendation->getTargetId(), $actorId, $administrator);
        if ($source === null || $target === null) { return null; }
        $user = $recommendation->getUserId();
        $userPublicId = $user === null ? null : $this->connection->fetchOne('SELECT public_id FROM users WHERE id = :id', ['id' => $user->toString()]);
        if ($user !== null && !is_string($userPublicId)) { return null; }
        return [
            'public_id' => $recommendation->getPublicId()->toString(), 'name' => $recommendation->getName(),
            'source_type' => (string) $recommendation->getSourceType(), 'source_id' => $source,
            'target_type' => (string) $recommendation->getTargetType(), 'target_id' => $target,
            'score' => $recommendation->getScore(), 'position' => $recommendation->getPosition(), 'user_id' => $userPublicId,
            'created_at' => $recommendation->getCreatedAt()->format(\DateTimeInterface::ATOM), 'updated_at' => $recommendation->getUpdatedAt()->format(\DateTimeInterface::ATOM),
        ];
    }

    public function recommendationId(string $publicId, Uuid $actorId, bool $administrator): Uuid
    {
        try {
            $recommendation = $this->recommendations->findByPublicId(PublicId::fromString($publicId));
        } catch (\InvalidArgumentException) {
            throw new NotFoundException('Recommendation not found.');
        }
        if ($recommendation === null || (!$administrator && !$recommendation->getUserId()?->equals($actorId))) {
            throw new NotFoundException('Recommendation not found.');
        }
        return $recommendation->getId();
    }
}
