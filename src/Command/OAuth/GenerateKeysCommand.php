<?php

declare(strict_types=1);

namespace App\Command\OAuth;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:oauth:generate-keys',
    description: 'Generate OAuth2 private and public keys for JWT signing.',
)]
final class GenerateKeysCommand extends Command
{
    public function __construct(
        private readonly string $oauthKeysPrivateKeyPath,
        private readonly string $oauthKeysPublicKeyPath,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp('This command generates RSA key pair for OAuth2 JWT token signing.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $privateKeyDir = dirname($this->oauthKeysPrivateKeyPath);

        if (!is_dir($privateKeyDir)) {
            if (!mkdir($privateKeyDir, 0755, true) && !is_dir($privateKeyDir)) {
                $io->error(sprintf('Failed to create directory: %s', $privateKeyDir));

                return Command::FAILURE;
            }
        }

        if (file_exists($this->oauthKeysPrivateKeyPath)) {
            if (!$io->confirm('Private key already exists. Overwrite?', false)) {
                $io->note('Key generation aborted.');

                return Command::SUCCESS;
            }
        }

        $io->text('Generating RSA key pair...');

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);

        if ($privateKey === false) {
            $io->error('Failed to generate private key.');

            return Command::FAILURE;
        }

        if (!openssl_pkey_export_to_file($privateKey, $this->oauthKeysPrivateKeyPath)) {
            $io->error('Failed to write private key file.');

            return Command::FAILURE;
        }

        chmod($this->oauthKeysPrivateKeyPath, 0600);

        $publicKeyDetails = openssl_pkey_get_details($privateKey);
        if ($publicKeyDetails === false) {
            $io->error('Failed to extract public key.');

            return Command::FAILURE;
        }

        if (!file_put_contents($this->oauthKeysPublicKeyPath, $publicKeyDetails['key'])) {
            $io->error('Failed to write public key file.');

            return Command::FAILURE;
        }

        chmod($this->oauthKeysPublicKeyPath, 0600);

        $io->success(sprintf('Keys generated: %s and %s', $this->oauthKeysPrivateKeyPath, $this->oauthKeysPublicKeyPath));

        return Command::SUCCESS;
    }
}
