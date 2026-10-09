<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Artist\AddArtistCreditCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/artists/{publicId}/albums.
 */
#[AsCommand(
    name: 'app:artist:album:add',
    description: 'Credit an artist on an album with a role.',
)]
final class ArtistAlbumAddCommand extends Command
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
            ->addArgument('album-id', InputArgument::REQUIRED, 'The album UUID')
            ->addArgument('role', InputArgument::REQUIRED, 'The role: primary, featured, producer, composer, conductor, remixer, djmix or other');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');
        $albumId = (string) $input->getArgument('album-id');
        $role = (string) $input->getArgument('role');

        try {
            $this->support->dispatch(new AddArtistCreditCommand($publicId, CreditTarget::Album, $albumId, $role));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Artist %s is credited as %s on album %s.', $publicId, $role, $albumId));
        }

        return Command::SUCCESS;
    }
}
