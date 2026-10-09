<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Artist\DeleteArtistCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/artists/{publicId}.
 */
#[AsCommand(
    name: 'app:artist:delete',
    description: 'Delete an artist, its credits and its cover image.',
)]
final class ArtistDeleteCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('public-id', InputArgument::REQUIRED, 'The artist public ID');
        AdminCommandSupport::addForceOption($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');

        $refused = AdminCommandSupport::confirm($input, $io, sprintf('Delete artist %s, its song and album credits and its cover? This cannot be undone.', $publicId));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $result = $this->support->dispatch(new DeleteArtistCommand($publicId));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        return CatalogDeleteOutput::result($input, $io, $result, sprintf('Artist %s', $publicId));
    }
}
