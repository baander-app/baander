<?php


declare(strict_types=1);

namespace App\Console\Commands\OAuth;

use App\Models\OAuth\Client;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateClientCommand extends Command
{
    protected $signature = 'oauth:client:create 
                            {--name= : The name of the client}
                            {--redirect= : The redirect URI for the client}
                            {--public : Create a public client (no secret)}
                            {--password : Create a password grant client}
                            {--device : Create a device code flow client}
                            {--personal : Create a personal access client}
                            {--first-party : Mark client as first-party}';

    protected $description = 'Create a new OAuth 2.0 client';

    public function handle(): int
    {
        $name = $this->option('name') ?: $this->ask('What is the name of the client?');
        $redirect = $this->option('redirect') ?: $this->ask('What is the redirect URI?', 'http://localhost');

        $isPublic = $this->option('public');
        $isPassword = $this->option('password');
        $isDevice = $this->option('device');
        $isPersonal = $this->option('personal');
        $isFirstParty = $this->option('first-party');

        if (!$isPublic && !$isPassword && !$isDevice && !$isPersonal) {
            $type = $this->choice('What type of client?', [
                'authorization_code' => 'Authorization Code (web application)',
                'device'             => 'Device Code (limited input devices)',
                'password'           => 'Password Grant (first-party only)',
                'personal'           => 'Personal Access Token',
                'public'             => 'Public Client (mobile/SPA)',
            ], 'authorization_code');

            $isPublic = $type === 'public';
            $isPassword = $type === 'password';
            $isDevice = $type === 'device';
            $isPersonal = $type === 'personal';
        }

        $confidential = !$isPublic && !$isDevice;

        $client = Client::create([
            'id'                     => Str::uuid(),
            'name'                   => $name,
            'secret'                 => $confidential ? Str::random(40) : null,
            'redirect'               => $redirect,
            'personal_access_client' => $isPersonal,
            'password_client'        => $isPassword,
            'device_client'          => $isDevice,
            'confidential'           => $confidential,
            'first_party'            => $isFirstParty,
        ]);

        $this->info('Client created successfully.');
        $this->table(['Attribute', 'Value'], [
            ['Client ID', $client->id],
            ['Client Secret', $client->secret ?: 'N/A (public client)'],
            ['Name', $client->name],
            ['Redirect URI', $client->redirect],
            ['Type', $this->getClientType($client)],
        ]);

        if ($isDevice) {
            $this->newLine();
            $this->comment('Device Flow Endpoints:');
            $this->line('Device Authorization: POST ' . config('app.url') . '/api/oauth/device/authorize');
            $this->line('Token: POST ' . config('app.url') . '/api/oauth/token');
            $this->line('Verification: ' . config('oauth.device_verification_uri'));
        }

        return self::SUCCESS;
    }

    private function getClientType(Client $client): string
    {
        if ($client->device_client) return 'Device Code';
        if ($client->password_client) return 'Password Grant';
        if ($client->personal_access_client) return 'Personal Access';
        if (!$client->confidential) return 'Public (SPA/Mobile)';

        return 'Confidential (Web Application)';
    }
}
