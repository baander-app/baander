<?php

declare(strict_types=1);

namespace App\Modules\Auth\OAuth\Repositories;

use App\Models\User;
use App\Modules\Auth\OAuth\Contracts\UserRepositoryInterface;
use App\Modules\Auth\OAuth\Entities\UserEntity;
use Illuminate\Support\Facades\Hash;
use League\OAuth2\Server\Entities\ClientEntityInterface;
use League\OAuth2\Server\Entities\UserEntityInterface;

class UserRepository implements UserRepositoryInterface
{
    public function getUserEntityByUserCredentials($username, $password, $grantType, ClientEntityInterface $clientEntity): ?UserEntityInterface
    {
        $user = User::whereEmail($username)->first();

        if (!$user || !Hash::check($password, $user->password)) {
            return null;
        }

        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($password);
            $user->save();
        }

        $userEntity = new UserEntity();
        $userEntity->setIdentifier($user->id);
        $userEntity->setAttribute('email', $user->email);
        $userEntity->setAttribute('name', $user->name);

        return $userEntity;
    }
}
