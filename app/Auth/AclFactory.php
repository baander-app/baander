<?php

namespace App\Auth;

use Spatie\Permission\Models\Permission;

class AclFactory
{
    public const array CRUD_METHODS = ['viewAny', 'update', 'store', 'delete', 'forceDelete'];
    public const string SEPARATOR = '.';
    public const array GUARDS = ['api', 'web'];

    public static function createPermission(string $name, string $permission)
    {
        Permission::create(['name' => $name . AclFactory::SEPARATOR . $permission]);
    }

    public static function createPermissionsFor(string $subject)
    {
        $models = [];

        foreach (self::CRUD_METHODS as $method) {
            $models[] = Permission::create(['name' => $subject . AclFactory::SEPARATOR . $method]);
        }

        return $models;
    }
}