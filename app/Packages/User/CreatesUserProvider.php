<?php

namespace App\Packages\User;

use Illuminate\Auth\AuthManager;
use Illuminate\Contracts\Auth\UserProvider;
use Illuminate\Contracts\Config\Repository as Config;
use RuntimeException;

trait CreatesUserProvider
{
    abstract protected function getAuthManager(): AuthManager;

    abstract protected function getConfig(): Config;

    protected function createUserProvider(): UserProvider
    {
        $userProvider = $this->getAuthManager()->createUserProvider('users');

        if (!$userProvider) {
            throw new RuntimeException('User provider not found.');
        }

        return $userProvider;
    }
}
