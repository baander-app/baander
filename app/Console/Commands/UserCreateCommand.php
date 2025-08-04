<?php

namespace App\Console\Commands;

use App\Models\User;
use Hash;
use Illuminate\Console\Command;
use Throwable;

class UserCreateCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'user:create {--admin}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create a new user';

    /**
     * Execute the console command.
     * @throws Throwable
     */
    public function handle()
    {
        $is_admin = false;
        if ($this->option('admin')) {
            $is_admin = true;
        }

        $data = $this->getUserData();
        if ((new \App\Models\User)->whereEmail($data['email'])->exists()) {
            $this->error('User already exists');
            return 1;
        }

        $user = new User($data + ['is_admin' => $is_admin]);
        $user->forceFill(['email_verified_at' => now()]);
        $user->saveOrFail();

        $this->info('User created successfully.');
    }

    private function getUserData(): array
    {
        return [
            'name'     => $this->ask('name'),
            'email'    => $this->ask('email'),
            'password' => Hash::make($this->secret('password')),
        ];
    }
}
