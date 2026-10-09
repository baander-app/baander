<?php

declare(strict_types=1);

namespace App\Lyrics\Application\CommandHandler;

use App\Lyrics\Application\Command\ApplyLyricsCommand;
use App\Lyrics\Application\DTO\LrclibUnavailable;
use App\Lyrics\Application\Exception\LyricsProviderUnavailableException;
use App\Lyrics\Application\Port\LrclibClientInterface;
use App\Lyrics\Domain\Model\Lyrics;
use App\Lyrics\Domain\Repository\LyricsRepositoryInterface;
use App\Shared\Application\Exception\ConflictException;
use App\Shared\Application\Exception\NotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Handles ApplyLyricsCommand: fetches the LRCLIB record by ID and stores it as the song's lyrics.
 */
#[AsMessageHandler]
final readonly class ApplyLyricsHandler
{
    public function __construct(
        private LrclibClientInterface $lrclibClient,
        private LyricsRepositoryInterface $lyricsRepository,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @throws ConflictException                  when the song already has lyrics, which stay unchanged
     * @throws NotFoundException                  when LRCLIB has no record with the ID
     * @throws LyricsProviderUnavailableException when LRCLIB did not answer
     */
    public function __invoke(ApplyLyricsCommand $command): Lyrics
    {
        $songId = $command->songId;
        if ($this->lyricsRepository->findBySongId($songId) !== null) {
            throw new ConflictException('The song already has lyrics.');
        }

        $result = $this->lrclibClient->getById($command->lrclibResultId);
        if ($result instanceof LrclibUnavailable) {
            throw new LyricsProviderUnavailableException();
        }
        if ($result === null) {
            throw new NotFoundException(sprintf('LRCLIB has no lyrics with ID %d.', $command->lrclibResultId));
        }

        $lyrics = Lyrics::create(
            songId: $songId,
            lyrics: $result->plainLyrics ?? '',
            source: 'lrclib',
            sourceUrl: null,
            isInstrumental: $result->instrumental,
            syncedLyrics: $result->syncedLyrics,
            lrclibId: $result->id,
        );
        $this->lyricsRepository->save($lyrics);

        $this->logger->info('Applied LRCLIB search result to song', [
            'song_id' => $songId->toString(),
            'lrclib_id' => $result->id,
        ]);

        return $lyrics;
    }
}
