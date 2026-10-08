<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Genre;

use App\Catalog\Application\Command\Genre\DeleteGenreCommand;
use App\Catalog\Application\Port\GenrePortInterface;
use App\Shared\Application\Exception\NotFoundException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class DeleteGenreHandler
{
    public function __construct(
        private GenrePortInterface $genres,
    ) {
    }

    /** @throws NotFoundException when no genre has the slug */
    #[AsMessageHandler]
    public function __invoke(DeleteGenreCommand $command): void
    {
        $genre = $this->genres->findBySlug($command->slug);
        if ($genre === null) {
            throw new NotFoundException(sprintf('Genre "%s" not found.', $command->slug));
        }

        $this->genres->delete($genre);
    }
}
