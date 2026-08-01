<?php

declare(strict_types=1);

namespace App\Command\Dev;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Process\Process;

#[AsCommand(
    name: 'app:dev:setup',
    description: 'Bootstrap development environment: run migrations, generate OAuth keys, seed OAuth clients, create dev users.',
)]
final class SetupCommand extends Command
{
    public function __construct(
        private readonly KernelInterface $kernel,
        private readonly Filesystem $filesystem,
        private readonly string $projectDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('fresh', 'f', InputOption::VALUE_NONE, 'Drop all tables and clear cache before setup')
            ->addOption('skip-keys', 'k', InputOption::VALUE_NONE, 'Skip OAuth key generation')
            ->setHelp('This command bootstraps the development environment.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $fresh = $input->getOption('fresh');
        $skipKeys = $input->getOption('skip-keys');

        $io->title('Baander Development Setup');

        if ($fresh) {
            $io->section('Fresh Install');
            $io->text('Dropping tables and clearing cache...');

            $this->runConsole($io, ['doctrine:schema:drop', '--force', '--full-database']);
            $this->clearCache($io);
        }

        $io->section('Database Migrations');
        $this->runConsole($io, ['doctrine:migrations:migrate', '--no-interaction']);

        if (!$skipKeys) {
            $io->section('OAuth Keys');
            $this->runConsole($io, ['app:oauth:generate-keys', '--no-interaction']);
        }

        $io->section('OAuth Clients');
        $this->runConsole($io, ['app:auth:setup-clients', '--no-interaction']);

        $io->section('Dev Users');
        $this->runConsole($io, ['app:dev:create-users', '--no-interaction']);

        $io->success('Development environment setup complete!');
        $io->text([
            '  Email              Password   Roles',
            '  admin@baander.test admin      ROLE_ADMIN, ROLE_SUPER_ADMIN',
            '  user@baander.test  user       ROLE_USER',
        ]);

        return Command::SUCCESS;
    }

    /**
     * Run another console command as an isolated subprocess.
     *
     * Subprocesses boot a fresh kernel, so they are immune to the cache-clear
     * performed by --fresh — which deletes compiled container classes that the
     * parent process has already loaded.
     */
    private function runConsole(SymfonyStyle $io, array $args): void
    {
        $process = new Process(['php', 'bin/console', ...$args], $this->projectDir);
        $process->run();

        if (!$process->isSuccessful()) {
            $io->error(sprintf('Command failed: %s', implode(' ', $args)));
            $io->text($process->getErrorOutput() ?: $process->getOutput());

            return;
        }

        $io->text(sprintf('<info>✓</info> %s', implode(' ', $args)));
    }

    private function clearCache(SymfonyStyle $io): void
    {
        $cacheDir = $this->kernel->getCacheDir();
        $this->filesystem->remove($cacheDir);
        $io->text(sprintf('<info>✓</info> Cache cleared: %s', $cacheDir));
    }
}
