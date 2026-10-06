<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use App\Auth\Application\Exception\OAuthTokenCacheInvalidationFailed;
use App\Auth\Application\Port\OAuthSecretBundleInterface;
use App\Auth\Application\Port\OAuthTokenInvalidatorInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Throwable;

#[AsCommand(
    name: 'app:auth:rotate-secrets',
    description: 'Prepare or validate an OAuth key bundle; invalidate tokens during offline cutover.',
)]
final class RotateSecretsCommand extends Command
{
    public function __construct(
        private readonly OAuthSecretBundleInterface $bundles,
        private readonly OAuthTokenInvalidatorInterface $tokens,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'prepare, validate, or invalidate')
            ->addOption('directory', null, InputOption::VALUE_REQUIRED, 'Absolute private bundle directory; prepare requires a new directory')
            ->addOption('key-size', null, InputOption::VALUE_REQUIRED, 'RSA bits for prepare only: 2048 (default) or 4096')
            ->addOption('offline', null, InputOption::VALUE_NONE, 'Assert all token issuers, resource servers and background workers are drained and stopped until cutover completes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $action = $input->getArgument('action');
        $directory = $input->getOption('directory');
        $keySize = $input->getOption('key-size');
        $offline = $input->getOption('offline');
        if (!in_array($action, ['prepare', 'validate', 'invalidate'], true)
            || !is_string($directory) || $directory === ''
            || ($action === 'invalidate' && $offline !== true)
            || ($action !== 'invalidate' && $offline === true)
            || ($action !== 'prepare' && $keySize !== null)
            || ($keySize !== null && !in_array($keySize, ['2048', '4096'], true))) {
            $io->error('Use prepare|validate|invalidate with --directory. Only prepare accepts --key-size; invalidate requires --offline.');
            return Command::INVALID;
        }

        try {
            if ($action === 'prepare') {
                $this->bundles->prepare($directory, $keySize === '4096' ? 4096 : 2048);
            }
            // Validate before any database operation, including after preparation.
            $this->bundles->validate($directory);
        } catch (Throwable) {
            $io->error('Bundle preparation or validation failed. No token invalidation was attempted. Preserve any incomplete bundle for inspection; prepare into a new directory.');
            return Command::FAILURE;
        }

        if ($action !== 'invalidate') {
            $io->success($action === 'prepare' ? 'OAuth bundle prepared and validated.' : 'OAuth bundle validated.');
            $io->text('Private configuration file: ' . $directory . '/oauth.env');
            $io->text('Keep the old configuration. Stop and drain every application instance before invalidate --offline.');
            return Command::SUCCESS;
        }

        try {
            $affected = $this->tokens->invalidate();
        } catch (OAuthTokenCacheInvalidationFailed) {
            $io->error('Database invalidation committed, but cache cleanup is unconfirmed. Keep every instance offline and retry invalidate with the same bundle before switching configuration.');
            return Command::FAILURE;
        } catch (Throwable) {
            $io->error('Token invalidation is unconfirmed. Keep every instance offline and retry invalidate with the same bundle. Do not switch configuration or resume service yet.');
            return Command::FAILURE;
        }

        $io->success(sprintf('Invalidated %d OAuth rows and cleared the token cache.', $affected));
        $io->text('While every instance remains offline, install both OAUTH_* values from the private oauth.env in your configuration provider.');
        $io->text('Restart all instances with that same bundle, verify authentication, and then resume traffic. Do not repeat invalidate after service resumes.');
        return Command::SUCCESS;
    }
}
