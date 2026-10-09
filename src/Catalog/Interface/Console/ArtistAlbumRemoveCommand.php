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
 * The CLI counterpart of DELETE /api/artists/{publicId}/albums/{albumId}.
 */
#[AsCommand(
    name: 'app:artist:album:remove',
    description: "Remove every one of an artist's credits on an album.",
)]
final class ArtistAlbumRemoveCommand extends Command
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
            ->addArgument('album-id', InputArgument::REQUIRED, 'The album UUID');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');
        $albumId = (string) $input->getArgument('album-id');

        try {
            $this->support->dispatch(new RemoveArtistCreditCommand($publicId, CreditTarget::Album, $albumId));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Artist %s is no longer credited on album %s.', $publicId, $albumId));
        }

        return Command::SUCCESS;
    }
}
