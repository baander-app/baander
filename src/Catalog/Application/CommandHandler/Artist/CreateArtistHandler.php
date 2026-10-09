<?php

declare(strict_types=1);

namespace App\Catalog\Application\CommandHandler\Artist;

use App\Catalog\Application\Command\Artist\CreateArtistCommand;
use App\Catalog\Application\Port\ArtistPortInterface;
use App\Catalog\Domain\Model\Artist;
use App\Shared\Application\Exception\InvalidInputException;
use InvalidArgumentException;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

final readonly class CreateArtistHandler
{
    public function __construct(
        private ArtistPortInterface $artists,
    ) {
    }

    /**
     * @throws InvalidInputException when the name is empty
     */
    #[AsMessageHandler]
    public function __invoke(CreateArtistCommand $command): Artist
    {
        try {
            $artist = Artist::create(
                name: $command->name,
                country: $command->country,
                gender: $command->gender,
                type: $command->type,
                disambiguation: $command->disambiguation,
                sortName: $command->sortName,
                biography: $command->biography,
            );
        } catch (InvalidArgumentException $exception) {
            throw new InvalidInputException($exception->getMessage(), [], $exception);
        }

        $this->artists->save($artist);

        return $artist;
    }
}
