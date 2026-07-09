<?php

namespace Tests\Feature\Customers;

use App\Models\Customer;
use App\Services\Customers\AtlImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesPosTestData;
use Tests\TestCase;

class AtlImportTest extends TestCase
{
    use CreatesPosTestData, RefreshDatabase;

    private function writeCsv(array $rows): string
    {
        $path = tempnam(sys_get_temp_dir(), 'atl_test_');
        $handle = fopen($path, 'w');
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        fclose($handle);

        return $path;
    }

    public function test_import_updates_atl_status_for_matching_customers_by_ntn(): void
    {
        $active = Customer::factory()->b2b()->create(['ntn' => '1112223']);
        $inactive = Customer::factory()->b2b()->create(['ntn' => '4445556']);
        $unrelated = Customer::factory()->b2b()->create(['ntn' => '7778889']);

        $csv = $this->writeCsv([
            ['NTN', 'Status'],
            ['1112223', 'Active'],
            ['4445556', 'In-Active'],
            ['9999999', 'Active'], // not one of our customers - should be skipped, not an error
        ]);

        $result = app(AtlImportService::class)->importFromCsv($csv);

        $this->assertSame(2, $result['matched']);
        $this->assertSame(2, $result['updated']);
        $this->assertEmpty($result['errors']);

        $this->assertSame(Customer::ATL_ACTIVE, $active->fresh()->atl_status);
        $this->assertSame(Customer::ATL_INACTIVE, $inactive->fresh()->atl_status);
        $this->assertSame(Customer::ATL_UNKNOWN, $unrelated->fresh()->atl_status); // untouched
        $this->assertNotNull($active->fresh()->atl_checked_at);

        unlink($csv);
    }

    public function test_import_reports_a_clear_error_when_columns_are_not_recognized(): void
    {
        $csv = $this->writeCsv([
            ['Some Column', 'Another Column'],
            ['foo', 'bar'],
        ]);

        $result = app(AtlImportService::class)->importFromCsv($csv);

        $this->assertSame(0, $result['matched']);
        $this->assertNotEmpty($result['errors']);

        unlink($csv);
    }

    public function test_atl_status_endpoint_requires_customer_manage_permission(): void
    {
        $this->seedRolesAndPermissions();
        $cashier = $this->makeUser('cashier');

        $this->actingAs($cashier)->getJson('/api/customers-atl/status')->assertStatus(403);
    }

    public function test_atl_status_endpoint_reports_last_refresh_and_counts(): void
    {
        $this->seedRolesAndPermissions();
        $admin = $this->makeUser('admin');
        Customer::factory()->b2b()->atlActive()->create();
        Customer::factory()->b2b()->atlInactive()->create();

        $response = $this->actingAs($admin)->getJson('/api/customers-atl/status');

        $response->assertOk();
        $response->assertJsonPath('counts.active', 1);
        $response->assertJsonPath('counts.inactive', 1);
        $this->assertNotNull($response->json('last_refreshed_at'));
    }
}
