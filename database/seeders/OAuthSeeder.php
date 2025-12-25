<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Auth\OAuth\Client;
use App\Models\Auth\OAuth\Scope;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class OAuthSeeder extends Seeder
{
    public function run(): void
    {
        $this->createScopes();
        $this->createDefaultClients();
    }

    private function createScopes(): void
    {
        $scopes = config('oauth.default_scopes', []);

        foreach ($scopes as $id => $description) {
            Scope::firstOrCreate(
                ['id' => $id],
                ['description' => $description]
            );
        }

        $this->command->info('OAuth scopes created.');
    }

    private function createDefaultClients(): void
    {
        // First-party web client
        Client::firstOrCreate(
            ['name' => 'Bånder Web Application'],
            [
                'secret' => Str::random(40),
                'redirect' => config('app.url') . '/auth/callback',
                'personal_access_client' => false,
                'password_client' => false,
                'device_client' => false,
                'confidential' => true,
                'first_party' => true,
            ]
        );

        // Device client for smart TVs, etc.
        Client::firstOrCreate(
            ['name' => 'Bånder Device Client'],
            [
                'id' => Str::uuid(),
                'secret' => null,
                'redirect' => 'urn:ietf:wg:oauth:2.0:oob',
                'personal_access_client' => false,
                'password_client' => false,
                'device_client' => true,
                'confidential' => false,
                'first_party' => true,
            ]
        );

        // Mobile/SPA public client
        Client::firstOrCreate(
            ['name' => 'Bånder Mobile/SPA'],
            [
                'secret' => null,
                'redirect' => 'http://localhost',
                'personal_access_client' => false,
                'password_client' => false,
                'device_client' => false,
                'confidential' => false,
                'first_party' => true,
            ]
        );

        $this->command->info('Default OAuth clients created.');
    }
}
