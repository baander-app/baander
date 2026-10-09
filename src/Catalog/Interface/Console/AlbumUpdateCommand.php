<?php

declare(strict_types=1);

namespace App\Catalog\Interface\Console;

use App\Catalog\Application\Command\Album\UpdateAlbumCommand;
use App\Catalog\Interface\Resource\AlbumResource;
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
 * The CLI counterpart of PATCH /api/albums/{publicId}.
 */
#[AsCommand(
    name: 'app:album:update',
    description: "Change an album's metadata, or lock and unlock its fields.",
)]
final class AlbumUpdateCommand extends Command
{
    public function __construct(
        private readonly AdminCommandSupport $support,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('public-id', InputArgument::REQUIRED, 'The public ID of the album')
            ->addOption('title', null, InputOption::VALUE_REQUIRED, 'The new title')
            ->addOption('type', null, InputOption::VALUE_REQUIRED, 'The new release type, such as album, single or ep')
            ->addOption('year', null, InputOption::VALUE_REQUIRED, 'The new release year')
            ->addOption('label', null, InputOption::VALUE_REQUIRED, 'The new record label')
            ->addOption('catalog-number', null, InputOption::VALUE_REQUIRED, 'The new catalog number')
            ->addOption('barcode', null, InputOption::VALUE_REQUIRED, 'The new barcode')
            ->addOption('country', null, InputOption::VALUE_REQUIRED, 'The new release country')
            ->addOption('language', null, InputOption::VALUE_REQUIRED, 'The new language')
            ->addOption('disambiguation', null, InputOption::VALUE_REQUIRED, 'The new disambiguation comment')
            ->addOption('annotation', null, InputOption::VALUE_REQUIRED, 'The new annotation');
        FieldLockOptions::add($this);
        AdminCommandSupport::addJsonOption($this);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $album = AlbumResource::from($this->support->dispatch(new UpdateAlbumCommand(
                publicId: (string) $input->getArgument('public-id'),
                title: AdminCommandSupport::stringOption($input, 'title'),
                type: AdminCommandSupport::stringOption($input, 'type'),
                year: AdminCommandSupport::integerOption($input, 'year'),
                label: AdminCommandSupport::stringOption($input, 'label'),
                catalogNumber: AdminCommandSupport::stringOption($input, 'catalog-number'),
                barcode: AdminCommandSupport::stringOption($input, 'barcode'),
                country: AdminCommandSupport::stringOption($input, 'country'),
                language: AdminCommandSupport::stringOption($input, 'language'),
                disambiguation: AdminCommandSupport::stringOption($input, 'disambiguation'),
                annotation: AdminCommandSupport::stringOption($input, 'annotation'),
                lock: FieldLockOptions::read($input, FieldLockOptions::LOCK),
                unlock: FieldLockOptions::read($input, FieldLockOptions::UNLOCK),
            )));
        } catch (Throwable $exception) {
            return AdminCommandSupport::fail($io, $exception);
        }

        if (AdminCommandSupport::wantsJson($input)) {
            return AdminCommandSupport::json($io, $album);
        }

        $io->success(sprintf('Album "%s" (%s) updated.', $album['title'], $album['publicId']));

        return Command::SUCCESS;
    }
}
