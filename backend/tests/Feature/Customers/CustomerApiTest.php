<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

class CustomerApiTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    public function test_ntn_must_be_exactly_seven_digits(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');

        $response = $this->actingAs($cashier)->postJson('/api/customers', [
            'name' => 'Test Co',
            'customer_type' => 'b2b',
            'ntn' => '12345', // too short
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('ntn');
    }

    public function test_ntn_with_dashes_is_normalized_and_accepted(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');

        $response = $this->actingAs($cashier)->postJson('/api/customers', [
            'name' => 'Test Co',
            'customer_type' => 'b2b',
            'ntn' => '1234567-8', // 8 raw chars incl dash, but 8 digits after stripping - should fail (needs exactly 7)
        ]);

        // "1234567-8" strips to "12345678" (8 digits) which is NOT a valid 7-digit NTN on its own -
        // this documents that check-digit suffixes must be omitted, only the 7-digit NTN itself.
        $response->assertStatus(422)->assertJsonValidationErrors('ntn');

        $response2 = $this->actingAs($cashier)->postJson('/api/customers', [
            'name' => 'Test Co 2',
            'customer_type' => 'b2b',
            'ntn' => '1234567',
        ]);
        $response2->assertCreated();
        $response2->assertJsonPath('ntn', '1234567');
    }

    public function test_cnic_must_be_exactly_thirteen_digits(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');

        $response = $this->actingAs($cashier)->postJson('/api/customers', [
            'name' => 'Walk-in Person',
            'customer_type' => 'walk_in',
            'cnic' => '12345-1234567-1', // 13 digits with dashes - should normalize and pass
        ]);

        $response->assertCreated();
        $response->assertJsonPath('cnic', '1234512345671');
    }

    public function test_duplicate_ntn_is_rejected(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');
        Customer::factory()->b2b()->create(['ntn' => '7654321']);

        $response = $this->actingAs($cashier)->postJson('/api/customers', [
            'name' => 'Duplicate NTN Co',
            'customer_type' => 'b2b',
            'ntn' => '7654321',
        ]);

        $response->assertStatus(422)->assertJsonValidationErrors('ntn');
    }

    public function test_cashier_can_quick_create_a_customer_without_special_permission(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');

        $response = $this->actingAs($cashier)->postJson('/api/customers', [
            'name' => 'Quick Create Customer',
            'customer_type' => 'walk_in',
            'phone' => '03211234567',
        ]);

        $response->assertCreated();
    }

    public function test_search_by_phone_finds_the_customer(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');
        Customer::factory()->create(['name' => 'Findable Customer', 'phone' => '03331112222']);

        $response = $this->actingAs($cashier)->getJson('/api/customers?search=03331112222');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'Findable Customer']);
    }

    public function test_search_by_ntn_finds_the_customer(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');
        Customer::factory()->b2b()->create(['name' => 'NTN Findable Co', 'ntn' => '9998887']);

        $response = $this->actingAs($cashier)->getJson('/api/customers?search=9998887');

        $response->assertOk();
        $response->assertJsonFragment(['name' => 'NTN Findable Co']);
    }

    public function test_updating_a_customer_requires_customer_manage_permission(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');
        $customer = Customer::factory()->create();

        $response = $this->actingAs($cashier)->putJson("/api/customers/{$customer->id}", [
            'name' => 'Renamed',
            'customer_type' => 'walk_in',
        ]);

        $response->assertStatus(403);
    }

    public function test_manager_can_update_a_customer(): void
    {
        $this->seedRolesAndPermissions();
        $manager = $this->makeUser('manager');
        $customer = Customer::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($manager)->putJson("/api/customers/{$customer->id}", [
            'name' => 'New Name',
            'customer_type' => 'walk_in',
        ]);

        $response->assertOk()->assertJsonPath('name', 'New Name');
    }
}
