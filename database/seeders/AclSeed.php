<?php

namespace Database\Seeders;

use App\Auth\AclFactory;
use App\Auth\Role as RoleEnum;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class AclSeed extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Reset permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        AclFactory::createPermissionsFor('albums');
        AclFactory::createPermissionsFor('libraries');
        AclFactory::createPermissionsFor('users');
        AclFactory::createPermission('user', 'viewLimited');
        AclFactory::createPermission('libraries', 'edit-metadata');
        AclFactory::createPermission('albums', 'edit-metadata');
        AclFactory::createPermission('artists', 'edit-metadata');
        AclFactory::createPermission('genre', 'edit-metadata');
        AclFactory::createPermission('songs', 'edit-metadata');

        $admin = Role::create(['name' => RoleEnum::Admin->value]);
        $admin->givePermissionTo(Permission::all());

        $sep = AclFactory::SEPARATOR;

        $user = Role::create(['name' => RoleEnum::User->value]);
        $user->givePermissionTo([
            'user' . $sep . 'viewLimited',
        ]);
    }
}
