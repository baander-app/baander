<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeed extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        User::factory()->create([
            'name'              => 'Test admin',
            'email'             => 'admin@baander.test',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'is_admin'          => true,
        ]);

        User::factory()->create([
            'name'              => 'Test user',
            'email'             => 'user@baander.test',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
            'is_admin'          => false,
        ]);
    }
}
