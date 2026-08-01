<?php

declare(strict_types=1);

namespace App\Auth\Interface\Console;

use Defuse\Crypto\Key;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\TagAwareCacheInterface;

#[AsCommand(
    name: 'app:auth:rotate-secrets',
    description: 'Rotate OAuth 2.0 keys, generate a new encryption key, and invalidate all existing tokens.',
)]
final class RotateSecretsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TagAwareCacheInterface $cache,
        private readonly string $privateKeyPath,
        private readonly string $publicKeyPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('key-size', null, InputOption::VALUE_REQUIRED, 'RSA key size in bits', '2048');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $keySize = (int) $input->getOption('key-size');

        if (!in_array($keySize, [2048, 4096], true)) {
            $io->error('Key size must be 2048 or 4096.');

            return Command::FAILURE;
        }

        // Step 1: Back up existing keys
        $io->section('Backing up existing keys');

        if (!file_exists($this->privateKeyPath)) {
            $io->error(sprintf('Private key not found at %s', $this->privateKeyPath));

            return Command::FAILURE;
        }

        if (!file_exists($this->publicKeyPath)) {
            $io->error(sprintf('Public key not found at %s', $this->publicKeyPath));

            return Command::FAILURE;
        }

        $backupPrivate = $this->privateKeyPath . '.old';
        $backupPublic = $this->publicKeyPath . '.old';

        copy($this->privateKeyPath, $backupPrivate);
        copy($this->publicKeyPath, $backupPublic);
        $io->text(sprintf('Backed up to %s and %s', basename($backupPrivate), basename($backupPublic)));

        // Step 2: Generate new key pair
        $io->section('Generating new key pair');

        $result = openssl_pkey_new([
            'private_key_bits' => $keySize,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($result === false) {
            $io->error('Failed to generate RSA key pair.');

            return Command::FAILURE;
        }

        openssl_pkey_export($result, $privateKeyPem);
        if ($privateKeyPem === false) {
            throw new RuntimeException('Failed to export private key.');
        }
        $publicKeyPem = openssl_pkey_get_details($result)['key'];

        file_put_contents($this->privateKeyPath, $privateKeyPem);
        file_put_contents($this->publicKeyPath, $publicKeyPem);
        $io->text(sprintf('Generated %d-bit RSA key pair', $keySize));

        // Step 3: Generate new encryption key
        $io->section('Generating new encryption key');

        $newEncryptionKey = Key::createNewRandomKey()->saveToAsciiSafeString();
        $io->text('New encryption key generated (see output below)');
        $io->newLine();

        // Step 4: Truncate OAuth token tables
        $io->section('Invalidating existing tokens');

        /** @var Connection $connection */
        $connection = $this->entityManager->getConnection();

        $affected = 0;
        foreach (['oauth_access_tokens', 'oauth_refresh_tokens', 'oauth_auth_codes'] as $table) {
            $count = $connection->executeStatement("DELETE FROM {$table}")->rowCount();
            $io->text(sprintf('Truncated %s (%d rows)', $table, $count));
            $affected += $count;
        }

        // Step 5: Invalidate OAuth token cache
        $io->text('Invalidating OAuth token cache...');
        $this->cache->invalidateTags(['oauth_token']);

        // Step 6: Output summary
        $io->newLine();
        $io->success('Secrets rotated successfully.');
        $io->warning(sprintf('%d tokens invalidated. All users must re-authenticate.', $affected));

        $io->section('Action required');

        $io->text('Add this to your .env file (or env provider):');
        $io->newLine();
        $io->text('AUTH_ENCRYPTION_KEY=' . $newEncryptionKey);
        $io->newLine();
        $io->text('Then restart the application:');
        $io->text('  make stop && make start');

        $io->newLine();
        $io->text('Old keys backed up to:');
        $io->text(sprintf('  %s', $backupPrivate));
        $io->text(sprintf('  %s', $backupPublic));
        $io->text('Remove them once confirmed working.');

        return Command::SUCCESS;
    }
}
