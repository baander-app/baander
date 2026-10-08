<?php

declare(strict_types=1);

namespace App\Metadata\Application\CommandHandler;

use App\Metadata\Application\Command\SyncMetadataCommand;
use App\Metadata\Application\MetadataSyncOrchestrator;
use App\Shared\Application\Exception\InvalidInputException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * The metadata sync the admin page and `app:metadata:sync` start.
 *
 * Returns the number of jobs queued: one per library for the full sync, and one per
 * album and song for the genre sync.
 */
#[AsMessageHandler]
final readonly class SyncMetadataHandler
{
    public function __construct(
        private MetadataSyncOrchestrator $orchestrator,
    ) {
    }

    /** @throws InvalidInputException for an unknown source */
    public function __invoke(SyncMetadataCommand $command): int
    {
        return match ($command->source) {
            null => $this->orchestrator->syncAll(includeSongs: true),
            SyncMetadataCommand::SOURCE_GENRES => $this->orchestrator->syncGenres(forceUpdate: true, includeSongs: true),
            default => throw new InvalidInputException(
                sprintf(
                    'Unknown metadata sync source "%s". Use "%s", or leave the source out to sync every library.',
                    $command->source,
                    SyncMetadataCommand::SOURCE_GENRES,
                ),
                ['source' => $command->source],
            ),
        };
    }
}
