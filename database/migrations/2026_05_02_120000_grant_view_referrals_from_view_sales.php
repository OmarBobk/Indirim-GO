<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

return new class extends Migration
{
    public function up(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        $new = Permission::firstOrCreate(['name' => 'view_referrals', 'guard_name' => 'web']);

        $old = Permission::query()
            ->where('name', 'view_sales')
            ->where('guard_name', 'web')
            ->first();

        if ($old === null) {
            return;
        }

        $roleIds = DB::table('role_has_permissions')
            ->where('permission_id', $old->id)
            ->pluck('role_id')
            ->unique()
            ->all();

        foreach ($roleIds as $roleId) {
            Role::query()->find((int) $roleId)?->givePermissionTo($new);
        }

        $userIds = DB::table('model_has_permissions')
            ->where('permission_id', $old->id)
            ->where('model_type', User::class)
            ->pluck('model_id')
            ->unique()
            ->all();

        foreach ($userIds as $userId) {
            User::query()->find((int) $userId)?->givePermissionTo($new);
        }

        DB::table('role_has_permissions')->where('permission_id', $old->id)->delete();
        DB::table('model_has_permissions')->where('permission_id', $old->id)->delete();
        $old->delete();
    }

    public function down(): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();
    }
};
