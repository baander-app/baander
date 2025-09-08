<?php

declare(strict_types=1);

namespace App\Console\Commands\OAuth;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use phpseclib3\Crypt\RSA;

class InstallCommand extends Command
{
    protected $signature = 'oauth:install 
                            {--force : Overwrite existing keys}
                            {--length=4096 : The length of the private key}';

    protected $description = 'Install OAuth 2.0 server keys and configuration';

    public function handle(): int
    {
        $this->info('Installing OAuth 2.0 server...');

        if ($this->generateKeys()) {
            $this->info('OAuth 2.0 server installed successfully.');
            $this->comment('Keys stored in: ' . storage_path());

            $this->newLine();
            $this->comment('Next steps:');
            $this->line('1. Run: php artisan oauth:scope:install');
            $this->line('2. Run: php artisan oauth:client:create');
            $this->line('3. Run migrations if not done: php artisan migrate');

            return self::SUCCESS;
        }

        return self::FAILURE;
    }

    private function generateKeys(): bool
    {
        $privateKeyPath = storage_path('oauth-private.key');
        $publicKeyPath = storage_path('oauth-public.key');

        if (File::exists($privateKeyPath) && !$this->option('force')) {
            if (!$this->confirm('OAuth keys already exist. Do you want to overwrite them?')) {
                $this->info('Installation cancelled.');
                return false;
            }
        }

        $this->info('Generating RSA key pair...');

        try {
            $key = RSA::createKey($this->option('length'));

            File::put($privateKeyPath, (string) $key);
            File::put($publicKeyPath, (string) $key->getPublicKey());

            $this->info('✓ Private key: ' . $privateKeyPath);
            $this->info('✓ Public key: ' . $publicKeyPath);

            return true;
        } catch (\Exception $e) {
            $this->error('Failed to generate keys: ' . $e->getMessage());
            return false;
        }
    }
}
