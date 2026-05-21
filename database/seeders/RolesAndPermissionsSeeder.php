<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Clear cache
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        // Define permissions (assign to roles; app checks these, not role names)
        $permissions = [
            'view_dashboard',
            'manage_users',
            'manage_sections',
            'manage_products',
            'manage_loyalty_tiers',
            'manage_topups',
            'view_referrals',
            'manage_referred_users',
            'create_orders',
            'customer_profile',
            'edit_orders',
            'delete_orders',
            'view_orders',
            'view_fulfillments',
            'manage_fulfillments',
            'view_refunds',
            'process_refunds',
            'view_activities',
            'manage_settlements',
            'manage_bugs',
            'install_pwa_app',
            'manage_user_prices',
            'update_product_prices',
        ];

        // Create permissions
        foreach ($permissions as $permission) {
            Permission::firstOrCreate(['name' => $permission]);
        }

        // Create roles and assign permissions
        // Admin: All permissions
        $admin = Role::firstOrCreate(['name' => 'admin']);
        $admin->givePermissionTo(Permission::all());

        // Salesperson: referral link + salesperson dashboard, orders
        $salesperson = Role::firstOrCreate(['name' => 'salesperson']);
        $salesperson->syncPermissions([
            'view_referrals',
            'manage_referred_users',
            'view_orders',
            'create_orders',
            'edit_orders',
        ]);

        // Supervisor: dashboard, referrals, orders, create orders (no edit)
        $supervisor = Role::firstOrCreate(['name' => 'supervisor']);
        $supervisor->syncPermissions([
            'view_dashboard',
            'view_referrals',
            'view_orders',
            'create_orders',
        ]);

        $customer = Role::firstOrCreate(['name' => 'customer']);
        $customer->givePermissionTo([
            'customer_profile',
        ]);

        // Create Admin User
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@gmail.com',
            'username' => 'admin',
        ]);
        $admin->assignRole('admin');

        // Create Supervisor Users
        $ahmad = User::factory()->create([
            'name' => 'Ahmad',
            'email' => 'ahmad@gmail.com',
            'username' => 'ahmad',
        ]);
        $ahmad->assignRole('supervisor');

        $zain = User::factory()->create([
            'id' => 9, // Zain Salesperson
            'name' => 'Zain',
            'email' => 'zain@gmail.com',
            'username' => 'zain',
        ]);
        $zain->assignRole('supervisor');

        // Create Salespersons Users
        $karman_telekom = User::factory()->create([
            'name' => 'Karman Telekom',
            'email' => 'karmantelekom@gmail.com',
            'username' => 'karmantelekom',
        ]);
        $karman_telekom->assignRole('salesperson');

        $cephane_owner = User::factory()->create([
            'name' => 'Cephane Owner',
            'email' => 'cephane@gmail.com',
            'username' => 'cephane',
        ]);
        $cephane_owner->assignRole('supervisor');

        $zore_owner = User::factory()->create([
            'name' => 'Zore Owner',
            'email' => 'zore@gmail.com',
            'username' => 'zore',
        ]);
        $zore_owner->assignRole('supervisor');

        $simex_owner = User::factory()->create([
            'name' => 'Simex Owner',
            'email' => 'simex@gmail.com',
            'username' => 'simex',
        ]);
        $simex_owner->assignRole('supervisor');

        // Create Customer User
        $customer_A = User::factory()->create([
            'name' => 'Customer A',
            'email' => 'customer@gmail.com',
            'username' => 'customerA',
        ]);
        $customer_A->assignRole('customer');

        // Store user IDs in config for use in other seeders
        config([
            'seeder.users.admin_id' => $admin->id,
            'seeder.users.ahmad_id' => $ahmad->id,
            'seeder.users.zain_id' => $zain->id,
            'seeder.users.karman_owner_id' => $karman_telekom->id,
            'seeder.users.cephane_owner_id' => $cephane_owner->id,
            'seeder.users.zore_owner_id' => $zore_owner->id,
            'seeder.users.simex_owner_id' => $simex_owner->id,
            'seeder.users.customer_id' => $customer_A->id,
        ]);
    }
}
