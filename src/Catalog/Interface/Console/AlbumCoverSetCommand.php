<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Cover\CoverOwner;
use App\Catalog\Application\Command\Cover\SetCoverCommand;
use App\Catalog\Interface\Resource\CoverImageResource;
use App\Shared\Interface\Console\AdminCommandSupport;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

/**
 * The CLI counterpart of POST /api/albums/{publicId}/cover.
 */
#[AsCommand(
    name: 'app:album:cover:set',
    description: 'Set or replace an album cover from an image file in the container.',
)]
final class AlbumCoverSetCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('public-id', InputArgument::REQUIRED, 'The album public ID')
            ->addArgument('path', InputArgument::REQUIRED, 'A JPEG, PNG or WebP file of at most 10 MB; it is copied, not moved');
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $cover = CoverImageResource::from($this->support->dispatch(new SetCoverCommand(
                CoverOwner::Album,
                (string) $input->getArgument('public-id'),
                (string) $input->getArgument('path'),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $cover);
        }

        $io->success(sprintf('Album cover set: image %s, %dx%d, %d bytes.', $cover['publicId'], $cover['width'], $cover['height'], $cover['size']));

        return Command::SUCCESS;
    }
}
