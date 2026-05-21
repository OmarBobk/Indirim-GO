<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        $permission = Permission::firstOrCreate(['name' => 'manage_referred_users']);
        $salesperson = Role::query()->where('name', 'salesperson')->first();

        if ($salesperson !== null) {
            $salesperson->givePermissionTo($permission);
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }

    public function down(): void
    {
        $permission = Permission::query()->where('name', 'manage_referred_users')->first();

        if ($permission !== null) {
            Role::query()->where('name', 'salesperson')->first()?->revokePermissionTo($permission);
            $permission->delete();
        }

        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
