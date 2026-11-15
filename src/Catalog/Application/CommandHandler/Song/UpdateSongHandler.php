<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Song;

use App\Catalog\Application\Command\Song\UpdateSongCommand;
use App\Catalog\Application\Port\SongPortInterface;
use App\Catalog\Application\Service\CatalogInput;
use App\Catalog\Domain\Model\Song;
use App\Shared\Application\Exception\InvalidInputException;
use App\Shared\Application\Exception\NotFoundException;
use App\Shared\Application\Port\TransactionPortInterface;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Lifts the unlocked fields, applies the edit, then locks the newly locked fields, and saves the
 * song only when all of it is accepted. A field locked before the request refuses the edit; one
 * request may unlock a field and change it, or change a field and lock it.
 *
 * The edit runs in one transaction that locks the song's row and then reads the song, so it waits
 * for a match apply, a retag or a credit change that holds the row and edits the song as that
 * writer left it. What it saves keeps a matched mark such a writer set and leaves dropped one it
 * dropped. The row is the only lock it takes, so it never waits on another writer in a cycle.
 */
final readonly class UpdateSongHandler
{
    public function __construct(
        private SongPortInterface $songs,
        private TransactionPortInterface $transaction,
    ) {
    }

    /**
     * @throws InvalidInputException when the public ID is malformed, a lock names an unknown field,
     *                               the edit changes a locked field, or a value is invalid
     * @throws NotFoundException     when no song has the public ID
     */
    #[AsMessageHandler]
    public function __invoke(UpdateSongCommand $command): Song
    {
        $songId = ($this->songs->findByPublicId(CatalogInput::publicId($command->publicId)) ?? throw self::notFound($command))->getId();

        return $this->transaction->run(function () use ($command, $songId): Song {
            $this->songs->lockInIdOrder([$songId]);
            $song = $this->songs->findFreshByUuid($songId) ?? throw self::notFound($command);

            $locks = CatalogInput::lockChanges($song->getLockedFields(), $command->lockedFields, $command->lock, $command->unlock);

            try {
                foreach ($locks['unlock'] as $field) {
                    $song->unlockField($field);
                }

                $song->updateMetadata(
                    title: $command->title,
                    track: $command->track,
                    disc: $command->disc,
                    year: $command->year,
                    comment: $command->comment,
                    lyrics: $command->lyrics,
                    explicit: $command->explicit,
                );
                foreach ($locks['lock'] as $field) {
                    $song->lockField($field);
                }
            } catch (InvalidArgumentException $exception) {
                throw new InvalidInputException($exception->getMessage(), [], $exception);
            }

            $this->songs->save($song);

            return $song;
        });
    }

    private static function notFound(UpdateSongCommand $command): NotFoundException
    {
        return new NotFoundException(sprintf('Song "%s" not found.', $command->publicId));
    }
}
