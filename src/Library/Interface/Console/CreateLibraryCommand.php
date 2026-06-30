<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Filesystem\Domain\ValueObject\FilesystemType;
use App\Library\Application\Command\CreateLibraryCommand as CreateLibraryCommandMessage;
use App\Library\Domain\ValueObject\LibraryPath;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\ValueObject\LibraryType;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'app:library:create',
    description: 'Create a new media library.',
)]
final class CreateLibraryCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::REQUIRED, 'Library name')
            ->addArgument('path', InputArgument::REQUIRED, 'Absolute path to the media directory')
            ->addArgument('type', InputArgument::REQUIRED, 'Library type ('.implode(', ', array_column(LibraryType::cases(), 'value')).')')
            ->addOption('filesystem-type', null, InputOption::VALUE_REQUIRED, 'Filesystem backend ('.implode(', ', array_column(FilesystemType::cases(), 'value')).')', 'local')
            ->addOption('slug', 's', InputOption::VALUE_REQUIRED, 'URL-friendly slug (auto-generated from name if omitted)')
            ->addOption('sort-order', null, InputOption::VALUE_REQUIRED, 'Sort order', '0');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $name = $input->getArgument('name');
        $path = $input->getArgument('path');
        $typeString = $input->getArgument('type');
        $filesystemTypeString = $input->getOption('filesystem-type');
        $slugString = $input->getOption('slug');
        $sortOrder = (int) $input->getOption('sort-order');

        try {
            $libraryType = LibraryType::from($typeString);
        } catch (\ValueError) {
            $io->error(sprintf(
                'Invalid type "%s". Allowed values: %s',
                $typeString,
                implode(', ', array_column(LibraryType::cases(), 'value')),
            ));

            return Command::FAILURE;
        }

        try {
            $filesystemType = FilesystemType::from($filesystemTypeString);
        } catch (\ValueError) {
            $io->error(sprintf(
                'Invalid filesystem type "%s". Allowed values: %s',
                $filesystemTypeString,
                implode(', ', array_column(FilesystemType::cases(), 'value')),
            ));

            return Command::FAILURE;
        }

        try {
            $libraryPath = new LibraryPath($path);
            $librarySlug = $slugString !== null ? new LibrarySlug($slugString) : LibrarySlug::fromName($name);
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $envelope = $this->commandBus->dispatch(new CreateLibraryCommandMessage(
                name: $name,
                slug: $librarySlug,
                path: $libraryPath,
                type: $libraryType,
                filesystemType: $filesystemType,
                sortOrder: $sortOrder,
            ));

            $library = $envelope->last(HandledStamp::class)?->getResult();

            if (!$library instanceof \App\Library\Domain\Model\Library) {
                throw new \RuntimeException('Handler did not return a Library instance.');
            }
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to create library: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Library created successfully.');
        $io->table(
            ['Property', 'Value'],
            [
                ['UUID', $library->getId()->toString()],
                ['Name', $library->getName()],
                ['Slug', $library->getSlug()->toString()],
                ['Path', $library->getPath()->toString()],
                ['Type', $library->getType()->value],
                ['Filesystem', $library->getFilesystemType()->value],
                ['Sort Order', (string) $library->getSortOrder()],
            ],
        );

        return Command::SUCCESS;
    }
}
