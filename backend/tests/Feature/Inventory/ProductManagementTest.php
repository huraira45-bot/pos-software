<?php

namespace Tests\Feature\Inventory;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

class ProductManagementTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    public function test_creating_a_product_without_a_second_item_does_not_produce_invalid_sql(): void
    {
        // Regression: StoreProductRequest's unique() rule used to build a raw
        // "unique:products,item_code," string with the ignore-ID appended via
        // string concatenation - on create (no existing product to ignore),
        // that produced an empty-string ID Postgres rejected with "invalid
        // input syntax for type bigint". Rule::unique()->ignore(null) is a
        // no-op instead, which is what create requests need.
        $this->seedRolesAndPermissions();
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/products', [
            'item_code' => 'REG-TEST-001',
            'name' => 'Regression Test Item',
            'unit' => 'pcs',
            'pct_code' => '9999.0000',
            'tax_rate' => '18.00',
            'price_excl_tax' => '500.00',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('products', ['item_code' => 'REG-TEST-001']);
    }

    public function test_created_product_response_reflects_database_defaults_not_stale_in_memory_state(): void
    {
        // Regression: Product::create() without is_active/reorder_level in the
        // payload left those attributes unset on the in-memory model, so the
        // immediate API response showed is_active=false (from casting an unset
        // value) even though Postgres actually applied is_active=true.
        $this->seedRolesAndPermissions();
        $admin = $this->makeUser('admin');

        $response = $this->actingAs($admin)->postJson('/api/products', [
            'item_code' => 'REG-TEST-002',
            'name' => 'Regression Test Item 2',
            'unit' => 'pcs',
            'pct_code' => '9999.0001',
            'tax_rate' => '18.00',
            'price_excl_tax' => '500.00',
        ]);

        $response->assertCreated();
        $response->assertJsonPath('is_active', true);
        $response->assertJsonPath('reorder_level', '0.000');
    }

    public function test_second_product_with_a_different_item_code_can_still_be_created(): void
    {
        $this->seedRolesAndPermissions();
        $admin = $this->makeUser('admin');

        $this->actingAs($admin)->postJson('/api/products', [
            'item_code' => 'REG-TEST-003',
            'name' => 'First',
            'unit' => 'pcs',
            'pct_code' => '9999.0002',
            'tax_rate' => '18.00',
            'price_excl_tax' => '100.00',
        ])->assertCreated();

        $duplicate = $this->actingAs($admin)->postJson('/api/products', [
            'item_code' => 'REG-TEST-003',
            'name' => 'Duplicate code',
            'unit' => 'pcs',
            'pct_code' => '9999.0003',
            'tax_rate' => '18.00',
            'price_excl_tax' => '200.00',
        ]);

        $duplicate->assertStatus(422);
    }
}
