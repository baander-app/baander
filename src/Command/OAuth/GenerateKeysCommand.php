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
        $this->setHelp('Initialize an RSA key pair for OAuth2 JWT token signing. Existing keys must be replaced with app:auth:rotate-secrets.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        /** @var array<string, array{stream: resource|null, dev: int, ino: int}> $created */
        $created = [];
        $complete = false;
        try {
            $paths = [$this->oauthKeysPrivateKeyPath, $this->oauthKeysPublicKeyPath];
            foreach ($paths as $path) {
                if ($path === '' || str_contains($path, '://')) {
                    throw new \RuntimeException('OAuth key targets must be local file paths.');
                }
                clearstatcache(true, $path);
                if (file_exists($path) || is_link($path)) {
                    throw new \RuntimeException(sprintf('Key target already exists: %s. Use app:auth:rotate-secrets to replace existing keys.', $path));
                }
                $directory = dirname($path);
                for ($parent = $directory; ; $parent = dirname($parent)) {
                    if (is_link($parent)) {
                        throw new \RuntimeException(sprintf('Key directory must not contain symlinks: %s', $parent));
                    }
                    if (dirname($parent) === $parent) {
                        break;
                    }
                }
            }
            foreach ($paths as $index => $path) {
                $directory = dirname($path);
                if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
                    throw new \RuntimeException(sprintf('Failed to create directory: %s', $directory));
                }
                $canonicalDirectory = realpath($directory);
                if ($canonicalDirectory === false) {
                    throw new \RuntimeException(sprintf('Failed to resolve key directory: %s', $directory));
                }
                $paths[$index] = $canonicalDirectory . DIRECTORY_SEPARATOR . basename($path);
            }
            if ($paths[0] === $paths[1]) {
                throw new \RuntimeException('Private and public key targets must be different paths.');
            }

            $io->text('Generating RSA key pair...');
            $privateKey = openssl_pkey_new([
                'private_key_bits' => 2048,
                'private_key_type' => OPENSSL_KEYTYPE_RSA,
            ]);
            if ($privateKey === false || !openssl_pkey_export($privateKey, $privatePem)) {
                throw new \RuntimeException('Failed to generate or export private key.');
            }
            $publicKeyDetails = openssl_pkey_get_details($privateKey);
            if ($publicKeyDetails === false) {
                throw new \RuntimeException('Failed to extract public key.');
            }
            $publicPem = $publicKeyDetails['key'];
            $exportedPrivate = openssl_pkey_get_private($privatePem);
            $exportedPublic = openssl_pkey_get_public($publicPem);
            if ($exportedPrivate === false || $exportedPublic === false
                || !openssl_sign('OAuth initialization key pair', $signature, $exportedPrivate, OPENSSL_ALGO_SHA256)
                || openssl_verify('OAuth initialization key pair', $signature, $exportedPublic, OPENSSL_ALGO_SHA256) !== 1
            ) {
                throw new \RuntimeException('Failed to validate generated key pair.');
            }

            foreach ([$paths[0] => $privatePem, $paths[1] => $publicPem] as $path => $pem) {
                // Exclusive creation also protects against targets appearing after the preflight check.
                $previousUmask = umask(0077);
                try {
                    $stream = @fopen($path, 'x+b');
                } finally {
                    umask($previousUmask);
                }
                if ($stream === false) {
                    throw new \RuntimeException(sprintf('Failed to exclusively create key file: %s. Use app:auth:rotate-secrets for existing keys.', $path));
                }
                $stat = fstat($stream);
                if ($stat === false) {
                    // Without descriptor metadata, safe ownership checks are unavailable.
                    // Leave the empty file for operator inspection rather than unlink a replacement.
                    fclose($stream);
                    throw new \RuntimeException(sprintf('Failed to inspect created key file: %s', $path));
                }
                $created[$path] = ['stream' => $stream, 'dev' => $stat['dev'], 'ino' => $stat['ino']];
                $stat = fstat($stream);
                if ($stat === false || ($stat['mode'] & 0777) !== 0600
                    || @fwrite($stream, $pem) !== strlen($pem) || !@fflush($stream) || !@fsync($stream)
                ) {
                    throw new \RuntimeException(sprintf('Failed to securely write key file: %s', $path));
                }
            }
            foreach ($created as $path => $file) {
                clearstatcache(true, $path);
                $stat = @lstat($path);
                if ($stat === false || $stat['dev'] !== $file['dev'] || $stat['ino'] !== $file['ino']) {
                    throw new \RuntimeException(sprintf('Key file was replaced during initialization: %s', $path));
                }
            }
            foreach ($created as $path => $file) {
                $closed = fclose($file['stream']);
                $created[$path]['stream'] = null;
                if (!$closed) {
                    throw new \RuntimeException(sprintf('Failed to close key file: %s', $path));
                }
            }
            $complete = true;
        } catch (\Throwable $exception) {
            $io->error($exception->getMessage());

            return Command::FAILURE;
        } finally {
            foreach ($created as $path => $file) {
                if (!$complete) {
                    clearstatcache(true, $path);
                    $stat = @lstat($path);
                    if ($stat !== false && $stat['dev'] === $file['dev'] && $stat['ino'] === $file['ino']) {
                        if (!@unlink($path)) {
                            $io->error(sprintf('Failed to remove incomplete key file: %s', $path));
                        }
                    }
                }
                if (is_resource($file['stream'])) {
                    fclose($file['stream']);
                }
            }
        }

        $io->success(sprintf('Keys generated: %s and %s', $this->oauthKeysPrivateKeyPath, $this->oauthKeysPublicKeyPath));

        return Command::SUCCESS;
    }
}
