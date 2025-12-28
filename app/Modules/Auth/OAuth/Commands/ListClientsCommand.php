<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Commands;

use App\Models\Auth\OAuth\Client;
use Illuminate\Console\Command;

class ListClientsCommand extends Command
{
    protected $signature = 'oauth:client:list {--show-secrets : Show client secrets}';
    protected $description = 'List all OAuth 2.0 clients';

    public function handle(): int
    {
        $clients = Client::where('revoked', false)->get();

        if ($clients->isEmpty()) {
            $this->info('No OAuth clients found.');
            $this->comment('Create one with: php artisan oauth:client:create');
            return self::SUCCESS;
        }

        $headers = ['ID', 'Name', 'Type', 'Redirect URI', 'Created'];

        if ($this->option('show-secrets')) {
            array_splice($headers, 2, 0, ['Secret']);
        }

        $rows = $clients->map(function (Client $client) {
            $row = [
                $client->id,
                $client->name,
                $this->getClientType($client),
                $client->redirect,
                $client->created_at->format('Y-m-d H:i'),
            ];

            if ($this->option('show-secrets')) {
                array_splice($row, 2, 0, [$client->secret ?: 'N/A']);
            }

            return $row;
        })->toArray();

        $this->table($headers, $rows);

        return self::SUCCESS;
    }

    private function getClientType(Client $client): string
    {
        if ($client->device_client) return 'Device';
        if ($client->password_client) return 'Password';
        if ($client->personal_access_client) return 'Personal';
        if (!$client->confidential) return 'Public';

        return 'Confidential';
    }
}
