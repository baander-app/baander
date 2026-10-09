<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Catalog\Application\Command\Artist\RemoveArtistCreditCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/artists/{publicId}/songs/{songId}.
 */
#[AsCommand(
    name: 'app:artist:song:remove',
    description: "Remove every one of an artist's credits on a song.",
)]
final class ArtistSongRemoveCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('public-id', InputArgument::REQUIRED, 'The public ID of the artist')
            ->addArgument('song-id', InputArgument::REQUIRED, 'The song UUID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');
        $songId = (string) $input->getArgument('song-id');

        try {
            $this->support->dispatch(new RemoveArtistCreditCommand($publicId, CreditTarget::Song, $songId));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Artist %s is no longer credited on song %s.', $publicId, $songId));
        }

        return Command::SUCCESS;
    }
}
