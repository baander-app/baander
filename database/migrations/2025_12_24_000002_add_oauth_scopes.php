<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add Sanctum abilities as OAuth scopes
        $scopes = [
            ['id' => 'access-api', 'description' => 'Access API endpoints', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'access-broadcasting', 'description' => 'Access broadcasting channels', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'access-stream', 'description' => 'Access media streams', 'created_at' => now(), 'updated_at' => now()],
            ['id' => 'issue-access-token', 'description' => 'Issue new access tokens (refresh)', 'created_at' => now(), 'updated_at' => now()],
        ];

        DB::table('oauth_scopes')->insert($scopes);
    }

    public function down(): void
    {
        DB::table('oauth_scopes')->whereIn('id', [
            'access-api',
            'access-broadcasting',
            'access-stream',
            'issue-access-token',
        ])->delete();
    }
};
