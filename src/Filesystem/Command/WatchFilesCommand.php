<?php

declare(strict_types=1);

namespace App\Filesystem\Command;

use App\Filesystem\Watcher\FileWatcher;
use App\Filesystem\Watcher\FileWatchEvent;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:watch-files',
    description: 'Watch directories for file system changes using inotify.',
)]
final class WatchFilesCommand extends Command
{
    public function __construct(
        private readonly FileWatcher $fileWatcher,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('path', 'p', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Directory path to watch', [])
            ->addOption('timeout', 't', InputOption::VALUE_REQUIRED, 'Read timeout in milliseconds', '5000');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $paths = $input->getOption('path');
        $timeout = (int) $input->getOption('timeout');

        foreach ($paths as $path) {
            $this->fileWatcher->watch($path);
        }

        if ($paths === []) {
            $output->writeln('<comment>No paths specified. Watching current directory.</comment>');
        }

        $this->fileWatcher->onEvent(function (FileWatchEvent $event) use ($output): void {
            $typeParts = [];
            if ($event->isCreate()) {
                $typeParts[] = 'CREATE';
            }
            if ($event->isDelete()) {
                $typeParts[] = 'DELETE';
            }
            if ($event->isModify()) {
                $typeParts[] = 'MODIFY';
            }
            if ($event->isMove()) {
                $typeParts[] = 'MOVE';
            }
            if ($event->isCloseWrite()) {
                $typeParts[] = 'CLOSE_WRITE';
            }

            $type = implode('|', $typeParts);
            $dir = $event->isDirectory ? ' [DIR]' : '';
            $output->writeln(sprintf('<info>[%s]</info> %s%s', $type, $event->path, $dir));
        });

        $this->fileWatcher->run($timeout);

        return Command::SUCCESS;
    }
}
