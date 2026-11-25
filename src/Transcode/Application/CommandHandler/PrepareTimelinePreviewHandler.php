<?php

declare(strict_types=1);

namespace App\Transcode\Application\CommandHandler;

use App\Transcode\Application\Command\PrepareTimelinePreview;
use App\Transcode\Application\Port\VideoTimelinePreviewPort;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class PrepareTimelinePreviewHandler
{
    public function __construct(
        private VideoTimelinePreviewPort $previews,
    ) {
    }

    #[AsMessageHandler(fromTransport: 'async', sign: true)]
    public function __invoke(PrepareTimelinePreview $command): void
    {
        $this->previews->prepare(
            $command->path,
            $command->identity,
            $command->duration,
            $command->origin,
            $command->sourceHash,
            $command->videoStreamIndex,
        );
    }
}
