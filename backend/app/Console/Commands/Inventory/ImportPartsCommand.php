<?php

namespace App\Console\Commands\Inventory;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Product;
use App\Models\StockLevel;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * One-off replacement of the demo product catalog with the real parts list
 * from partinven.xlsx (exported to JSON by a throwaway Python script - no
 * xlsx parser is installed in this Laravel app, and it isn't worth adding one
 * for a single import).
 *
 * Products already referenced by real invoice_items can't be hard-deleted -
 * invoice_items.product_id is restrictOnDelete() specifically so a sold
 * item's fiscal record is never silently orphaned - so those are deactivated
 * instead of removed. Products with any usage sit deactivate; catalog-only
 * demo products are removed outright.
 *
 * Pricing: the spreadsheet's only price column is cost (inventory valuation,
 * not a selling price) per the business owner - price_excl_tax defaults to
 * the same value and is expected to be overridden per-sale at checkout
 * (PosPermissions::PRICE_OVERRIDE) until real sale prices are set.
 * tax_rate (18%) isn't in the source data at all and may need per-product
 * correction later via the Products page. pct_code defaults to 87082990
 * (PCT chapter 87.08 "parts and accessories of motor vehicles - other"),
 * the catch-all code the business confirmed applies to this catalog - sent
 * as 8 raw digits, no dot, since the local SDC rejects the dotted display
 * format ("8708.2990") with "Invalid PCT Code length".
 */
class ImportPartsCommand extends Command
{
    protected $signature = 'parts:import
        {json : Path to the exported parts JSON file}
        {--branch=MULTAN : Branch code to seed initial stock_levels for}
        {--tax-rate=18.00 : Flat tax rate applied to every imported part}
        {--pct-code=87082990 : PCT code applied to every imported part}
        {--dry-run : Report what would happen without writing anything}';

    protected $description = 'Replace the product catalog with parts from an exported partinven.xlsx JSON file';

    public function handle(): int
    {
        $path = $this->argument('json');
        if (! file_exists($path)) {
            $this->error("File not found: {$path}");
            return self::FAILURE;
        }

        $rows = json_decode(file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        $this->info(sprintf('Loaded %d rows from %s', count($rows), $path));

        $branch = Branch::where('code', $this->option('branch'))->first();
        if (! $branch) {
            $this->error("Branch code '{$this->option('branch')}' not found.");
            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $taxRate = (string) $this->option('tax-rate');
        $pctCode = (string) $this->option('pct-code');

        // Every write below is individually guarded by $dryRun, so a dry run
        // never touches the database at all - no transaction/rollback trick needed.
        $run = function () use ($rows, $branch, $dryRun, $taxRate, $pctCode) {
            $this->clearOldCatalog($dryRun);
            $categoryIds = $this->resolveCategories($rows, $dryRun);
            $this->importParts($rows, $categoryIds, $branch, $dryRun, $taxRate, $pctCode);
        };

        if ($dryRun) {
            $run();
            $this->warn('Dry run - nothing was written.');
        } else {
            DB::transaction($run);
        }

        $this->info('Import complete.');
        return self::SUCCESS;
    }

    private function clearOldCatalog(bool $dryRun): void
    {
        $products = Product::all();
        $deleted = 0;
        $deactivated = 0;

        foreach ($products as $product) {
            // invoice_items, stock_adjustments, and purchase_items all restrictOnDelete()
            // on product_id - any one of them blocks a hard delete, not just invoice history.
            $isUsed = DB::table('invoice_items')->where('product_id', $product->id)->exists()
                || DB::table('stock_adjustments')->where('product_id', $product->id)->exists()
                || DB::table('purchase_items')->where('product_id', $product->id)->exists();

            if ($isUsed) {
                $this->line("  Deactivating (has invoice history): {$product->item_code} - {$product->name}");
                if (! $dryRun) {
                    $product->update(['is_active' => false]);
                }
                $deactivated++;
            } else {
                $this->line("  Deleting (no invoice history): {$product->item_code} - {$product->name}");
                if (! $dryRun) {
                    $product->delete();
                }
                $deleted++;
            }
        }

        $this->info("Old catalog: {$deleted} deleted, {$deactivated} deactivated.");
    }

    /** @return array<string, int> category name => id */
    private function resolveCategories(array $rows, bool $dryRun): array
    {
        $names = collect($rows)->pluck('category')->unique()->values();
        $map = [];

        foreach ($names as $name) {
            if ($dryRun) {
                $existing = Category::where('name', $name)->first();
                $map[$name] = $existing?->id ?? 0;
                continue;
            }
            $category = Category::firstOrCreate(['name' => $name]);
            $map[$name] = $category->id;
        }

        $this->info(sprintf('Categories resolved: %s', implode(', ', $names->all())));

        return $map;
    }

    private function importParts(array $rows, array $categoryIds, Branch $branch, bool $dryRun, string $taxRate, string $pctCode): void
    {
        $created = 0;
        $bar = $this->output->createProgressBar(count($rows));

        foreach ($rows as $row) {
            $costPrice = number_format((float) $row['cost_price'], 2, '.', '');

            if (! $dryRun) {
                $product = Product::updateOrCreate(
                    ['item_code' => $row['item_code']],
                    [
                        'category_id' => $categoryIds[$row['category']] ?: null,
                        'name' => $row['name'],
                        'unit' => 'pcs',
                        'pct_code' => $pctCode,
                        'tax_rate' => $taxRate,
                        'price_excl_tax' => $costPrice,
                        'cost_price' => $costPrice,
                        'track_stock' => true,
                        'reorder_level' => $row['reorder_level'],
                        'is_active' => true,
                    ],
                );

                StockLevel::updateOrCreate(
                    ['branch_id' => $branch->id, 'product_id' => $product->id, 'product_variant_id' => null],
                    ['quantity' => $row['on_hand']],
                );
            }

            $created++;
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("Imported/updated {$created} parts.");
    }
}
