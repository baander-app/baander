<?php

declare(strict_types=1);

namespace App\Playlist\Domain\Model;

use App\Shared\Domain\Model\Uuid;
use InvalidArgumentException;

final readonly class PlaylistPersistenceSnapshot
{
    /**
     * @param array<string, int> $songPositions
     * @param list<string> $collaboratorIds
     */
    private function __construct(
        public int $version,
        public array $songPositions,
        public array $collaboratorIds,
    ) {
        if ($version < 0) {
            throw new InvalidArgumentException('Playlist persistence version cannot be negative.');
        }
    }

    /**
     * @param list<PlaylistSong> $songs
     * @param list<Uuid> $collaboratorIds
     */
    public static function capture(int $version, array $songs, array $collaboratorIds): self
    {
        $positions = [];
        foreach ($songs as $song) {
            $positions[$song->getSongId()->toString()] = $song->getPosition();
        }
        $collaborators = array_map(static fn (Uuid $id): string => $id->toString(), $collaboratorIds);

        return self::fromMembership($version, $positions, $collaborators);
    }

    /**
     * @param array<string, int> $songPositions Canonical UUID string => position.
     * @param list<string> $collaboratorIds Canonical UUID strings.
     */
    public static function fromMembership(int $version, array $songPositions, array $collaboratorIds): self
    {
        ksort($songPositions);
        sort($collaboratorIds);

        return new self($version, $songPositions, $collaboratorIds);
    }

    public function revision(): string
    {
        return hash('sha256', json_encode(
            [$this->version, $this->songPositions, $this->collaboratorIds],
            JSON_THROW_ON_ERROR,
        ));
    }

    public function hasSameMembership(self $other): bool
    {
        return $this->songPositions === $other->songPositions
            && $this->collaboratorIds === $other->collaboratorIds;
    }
}
