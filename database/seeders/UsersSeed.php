<?php

namespace Database\Seeders;

use App\Auth\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UsersSeed extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $adminUser = User::factory()->create([
            'name'              => 'Test admin',
            'email'             => 'admin@baander.test',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $adminUser->assignRole(Role::Admin->value);

        $normalUser = User::factory()->create([
            'name'              => 'Test user',
            'email'             => 'user@baander.test',
            'password'          => Hash::make('password'),
            'email_verified_at' => now(),
        ]);

        $normalUser->assignRole(Role::User->value);
    }
}
