<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Artist\ChangeArtistCreditRoleCommand;
use App\Catalog\Application\Command\Artist\CreditTarget;
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
 * The CLI counterpart of PATCH /api/artists/{publicId}/albums/{albumId}.
 */
#[AsCommand(
    name: 'app:artist:album:role',
    description: "Change the role of an artist's credit on an album.",
)]
final class ArtistAlbumRoleCommand extends Command
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
            ->addArgument('role', InputArgument::REQUIRED, 'The new role: primary, featured, producer, composer, conductor, remixer, djmix or other')
            ->addOption('from', null, InputOption::VALUE_REQUIRED, 'The role of the credit to change; required when the artist holds several roles on the album');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $publicId = (string) $input->getArgument('public-id');
        $albumId = (string) $input->getArgument('album-id');
        $role = (string) $input->getArgument('role');

        try {
            $this->support->dispatch(new ChangeArtistCreditRoleCommand(
                $publicId,
                CreditTarget::Album,
                $albumId,
                $role,
                AdminCommandSupport::stringOption($input, 'from'),
            ));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Artist %s is credited as %s on album %s.', $publicId, $role, $albumId));
        }

        return Command::SUCCESS;
    }
}
