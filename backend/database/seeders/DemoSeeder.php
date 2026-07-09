<?php

namespace Database\Seeders;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Terminal;
use App\Models\User;
use App\Services\Inventory\PurchaseService;
use Illuminate\Database\Seeder;

/**
 * Demo/dev fixture data: two branches, terminals in mock fiscal mode (safe by
 * default - switch a terminal to fbr_sandbox explicitly once real PRAL
 * credentials exist), a small catalog, one supplier, and one user per role.
 * Paired with demo:run-flow, which drives an actual sale/return through this data.
 */
class DemoSeeder extends Seeder
{
    public function run(): void
    {
        $lahore = Branch::firstOrCreate(
            ['code' => 'LHR-01'],
            [
                'name' => 'Main Branch',
                'address_line1' => 'Shop 12, Liberty Market',
                'city' => 'Lahore',
                'ntn' => '1234567-8',
                'strn' => '32-11-2233-445-56',
                'tax_office_name' => 'RTO Lahore',
                'phone' => '042-35750000',
            ],
        );

        $karachi = Branch::firstOrCreate(
            ['code' => 'KHI-01'],
            [
                'name' => 'Clifton Branch',
                'address_line1' => 'Block 5, Clifton',
                'city' => 'Karachi',
                'ntn' => '1234567-8',
                'strn' => '17-22-3344-556-67',
                'tax_office_name' => 'RTO Karachi',
                'phone' => '021-35870000',
            ],
        );

        $lahoreT1 = Terminal::firstOrCreate(
            ['branch_id' => $lahore->id, 'code' => 'T1'],
            ['name' => 'Counter 1', 'fbr_pos_id' => 100001, 'fiscal_mode' => 'mock'],
        );
        Terminal::firstOrCreate(
            ['branch_id' => $lahore->id, 'code' => 'T2'],
            ['name' => 'Counter 2', 'fbr_pos_id' => 100002, 'fiscal_mode' => 'mock'],
        );
        Terminal::firstOrCreate(
            ['branch_id' => $karachi->id, 'code' => 'T1'],
            ['name' => 'Counter 1', 'fbr_pos_id' => 200001, 'fiscal_mode' => 'mock'],
        );

        $apparel = Category::firstOrCreate(['name' => 'Apparel']);
        $footwear = Category::firstOrCreate(['name' => 'Footwear']);
        $accessories = Category::firstOrCreate(['name' => 'Accessories']);

        $products = [
            ['item_code' => 'APP-001', 'name' => "Men's Cotton T-Shirt", 'category_id' => $apparel->id, 'pct_code' => '6109.1000', 'tax_rate' => '18.00', 'price_excl_tax' => '1200.00'],
            ['item_code' => 'APP-002', 'name' => "Women's Kurti", 'category_id' => $apparel->id, 'pct_code' => '6204.4200', 'tax_rate' => '18.00', 'price_excl_tax' => '2500.00'],
            ['item_code' => 'APP-003', 'name' => 'Denim Jeans', 'category_id' => $apparel->id, 'pct_code' => '6203.4200', 'tax_rate' => '18.00', 'price_excl_tax' => '3200.00'],
            ['item_code' => 'FW-001', 'name' => 'Leather Sandals', 'category_id' => $footwear->id, 'pct_code' => '6403.9900', 'tax_rate' => '18.00', 'price_excl_tax' => '2800.00'],
            ['item_code' => 'FW-002', 'name' => 'Running Shoes', 'category_id' => $footwear->id, 'pct_code' => '6404.1100', 'tax_rate' => '18.00', 'price_excl_tax' => '5500.00'],
            ['item_code' => 'ACC-001', 'name' => 'Leather Belt', 'category_id' => $accessories->id, 'pct_code' => '4203.3000', 'tax_rate' => '18.00', 'price_excl_tax' => '1500.00'],
            ['item_code' => 'ACC-002', 'name' => 'Handloom Scarf (zero-rated)', 'category_id' => $accessories->id, 'pct_code' => '6214.9000', 'tax_rate' => '0.00', 'price_excl_tax' => '900.00'],
        ];

        foreach ($products as $product) {
            Product::firstOrCreate(
                ['item_code' => $product['item_code']],
                [...$product, 'unit' => 'pcs', 'track_stock' => true, 'reorder_level' => '5.000'],
            );
        }

        $supplier = Supplier::firstOrCreate(
            ['name' => 'Faisalabad Textile Traders'],
            ['ntn' => '7654321-0', 'phone' => '041-8500000'],
        );

        $admin = User::firstOrCreate(
            ['email' => 'admin@pos.test'],
            ['name' => 'System Admin', 'password' => 'password'],
        );
        $admin->syncRoles(['admin']);

        $manager = User::firstOrCreate(
            ['email' => 'manager@pos.test'],
            ['name' => 'Branch Manager', 'password' => 'password', 'branch_id' => $lahore->id],
        );
        $manager->syncRoles(['manager']);

        $cashier1 = User::firstOrCreate(
            ['email' => 'cashier1@pos.test'],
            ['name' => 'Cashier One', 'password' => 'password', 'branch_id' => $lahore->id],
        );
        $cashier1->syncRoles(['cashier']);

        $cashier2 = User::firstOrCreate(
            ['email' => 'cashier2@pos.test'],
            ['name' => 'Cashier Two', 'password' => 'password', 'branch_id' => $karachi->id],
        );
        $cashier2->syncRoles(['cashier']);

        // Stock the Lahore counter so the demo script's sale doesn't start at
        // (and drift further into) negative stock.
        if ($lahore->stockLevels()->count() === 0) {
            $purchaseService = app(PurchaseService::class);
            $purchase = $purchaseService->createDraft([
                'branch_id' => $lahore->id,
                'supplier_id' => $supplier->id,
                'reference_no' => 'GRN-DEMO-001',
                'items' => Product::all()->map(fn (Product $p) => [
                    'product_id' => $p->id,
                    'quantity' => '50',
                    'unit_cost' => bcmul((string) $p->price_excl_tax, '0.6', 2),
                ])->all(),
            ], $admin);
            $purchaseService->receive($purchase);
        }

        $this->command?->info('Demo data ready. Users: admin@pos.test / manager@pos.test / cashier1@pos.test / cashier2@pos.test (password: "password")');
        $this->command?->info("Lahore terminal for demo:run-flow: terminal_id={$lahoreT1->id}");
    }
}
