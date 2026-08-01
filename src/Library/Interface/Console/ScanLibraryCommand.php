<?php

declare(strict_types=1);

namespace App\Library\Interface\Console;

use App\Library\Application\Command\ScanLibraryCommand as ScanLibraryCommandMessage;
use App\Library\Domain\ValueObject\LibrarySlug;
use App\Library\Domain\Model\Library;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

#[AsCommand(
    name: 'app:library:scan',
    description: 'Scan a media library by slug.',
)]
final class ScanLibraryCommand extends Command
{
    public function __construct(
        private readonly MessageBusInterface $commandBus,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('slug', InputArgument::REQUIRED, 'Library slug');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        try {
            $librarySlug = new LibrarySlug($input->getArgument('slug'));
        } catch (\InvalidArgumentException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        }

        try {
            $envelope = $this->commandBus->dispatch(new ScanLibraryCommandMessage(
                librarySlug: $librarySlug,
            ));

            $library = $envelope->last(HandledStamp::class)?->getResult();

            if (!$library instanceof Library) {
                throw new \RuntimeException('Handler did not return a Library instance.');
            }
        } catch (\RuntimeException $e) {
            $io->error($e->getMessage());

            return Command::FAILURE;
        } catch (\Throwable $e) {
            $io->error('Failed to scan library: '.$e->getMessage());

            return Command::FAILURE;
        }

        $io->success('Library scan completed successfully.');
        $io->table(
            ['Property', 'Value'],
            [
                ['UUID', $library->getId()->toString()],
                ['Name', $library->getName()],
                ['Slug', $library->getSlug()->toString()],
            ],
        );

        return Command::SUCCESS;
    }
}
