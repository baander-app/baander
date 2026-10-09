<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Cover\CoverOwner;
use App\Catalog\Application\Command\Cover\RemoveCoverCommand;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of DELETE /api/artists/{publicId}/cover.
 */
#[AsCommand(
    name: 'app:artist:cover:remove',
    description: 'Remove an artist cover and delete its image files.',
)]
final class ArtistCoverRemoveCommand extends Command
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

        $refused = AdminCommandSupport::confirm($input, $io, sprintf('Remove the cover of artist %s and delete its image files?', $publicId));
        if ($refused !== null) {
            return $refused;
        }

        try {
            $this->support->dispatch(new RemoveCoverCommand(CoverOwner::Artist, $publicId));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (!AdminCommandSupport::wantsJson($input)) {
            $io->success(sprintf('Cover of artist %s removed.', $publicId));
        }

        return Command::SUCCESS;
    }
}
