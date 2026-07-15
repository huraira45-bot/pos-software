<?php

namespace Database\Seeders;

use App\Support\PosPermissions;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Three roles matching the brief: cashier, manager, admin. Returns, price
 * override, discount-above-threshold, and void-before-finalize are all listed
 * as permission-gated actions - cashiers get none of them by default (a manager
 * or admin has to step in), which is a common anti-fraud control in retail.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        foreach (PosPermissions::all() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $cashier = Role::firstOrCreate(['name' => 'cashier', 'guard_name' => 'web']);
        $cashier->syncPermissions([]);

        $manager = Role::firstOrCreate(['name' => 'manager', 'guard_name' => 'web']);
        $manager->syncPermissions([
            PosPermissions::PRICE_OVERRIDE,
            PosPermissions::DISCOUNT_ABOVE_THRESHOLD,
            PosPermissions::RETURNS_CREATE,
            PosPermissions::VOID_BEFORE_FINALIZE,
            PosPermissions::STOCK_ADJUST,
            PosPermissions::REPORTS_VIEW,
            PosPermissions::COMPLIANCE_DASHBOARD,
            PosPermissions::PRODUCT_MANAGE,
            PosPermissions::PURCHASE_MANAGE,
            PosPermissions::CUSTOMER_MANAGE,
        ]);

        $admin = Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);
        $admin->syncPermissions(PosPermissions::all());
    }
}
