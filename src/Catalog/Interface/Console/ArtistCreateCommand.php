<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Artist\CreateArtistCommand;
use App\Catalog\Interface\Resource\ArtistResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/artists/.
 */
#[AsCommand(
    name: 'app:artist:create',
    description: 'Create an artist, such as a performer to credit by hand.',
)]
final class ArtistCreateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('name', InputArgument::REQUIRED, 'The artist name');
        ArtistMetadataOptions::add($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $artist = ArtistResource::from($this->support->dispatch(new CreateArtistCommand(
                name: (string) $input->getArgument('name'),
                country: AdminCommandSupport::stringOption($input, 'country'),
                gender: AdminCommandSupport::stringOption($input, 'gender'),
                type: AdminCommandSupport::stringOption($input, 'type'),
                disambiguation: AdminCommandSupport::stringOption($input, 'disambiguation'),
                sortName: AdminCommandSupport::stringOption($input, 'sort-name'),
                biography: AdminCommandSupport::stringOption($input, 'biography'),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $artist);
        }

        $io->success(sprintf('Artist "%s" created with public ID %s.', $artist['name'], $artist['publicId']));

        return Command::SUCCESS;
    }
}
