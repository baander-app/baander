<?php

declare(strict_types=1);

namespace App\Modules\OAuth\Repositories;

use App\Models\User;
use App\Modules\OAuth\Contracts\UserRepositoryInterface;
use App\Modules\OAuth\Entities\UserEntity;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;

class UserRepository implements UserRepositoryInterface
{
    public function getUserEntityByUserCredentials($username, $password, $grantType, ClientEntityInterface $clientEntity): ?UserEntityInterface
    {
        $user = User::where('email', $username)->first();

        if (!$user || !password_verify($password, $user->password)) {
            return null;
        }

        $userEntity = new UserEntity();
        $userEntity->setIdentifier($user->id);
        $userEntity->setAttribute('email', $user->email);
        $userEntity->setAttribute('name', $user->name);

        return $userEntity;
    }
}
