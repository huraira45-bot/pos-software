<?php

namespace App\Services\Customers;

use App\Models\Customer;

/**
 * Parses FBR's downloadable Active Taxpayer List (ATL) export and refreshes
 * atl_status for customers already saved in our system. Deliberately does NOT
 * create new customer records from the ATL - it's a status refresh for
 * customers we already know about, not a source of customer master data.
 *
 * FBR's ATL export format (CSV/Excel column names, encoding) isn't something
 * this project has a confirmed sample of - header matching here is
 * intentionally forgiving (case/whitespace-insensitive, matches a few common
 * column-name variants for the NTN and status columns) so it degrades to a
 * clear "columns not recognized" error rather than silently importing nothing
 * if FBR's actual export differs. Verify against a real downloaded file
 * before relying on this in production.
 */
class AtlImportService
{
    private const NTN_COLUMN_ALIASES = ['ntn', 'registrationno', 'regno', 'registrationnumber'];
    private const STATUS_COLUMN_ALIASES = ['status', 'taxpayerstatus', 'atlstatus'];

    /** @return array{matched:int, updated:int, skipped:int, errors:list<string>} */
    public function importFromCsv(string $filePath): array
    {
        $handle = fopen($filePath, 'r');
        if ($handle === false) {
            return ['matched' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['Could not open the uploaded file.']];
        }

        $header = fgetcsv($handle);
        if ($header === false) {
            fclose($handle);
            return ['matched' => 0, 'updated' => 0, 'skipped' => 0, 'errors' => ['File is empty.']];
        }

        $normalizedHeader = array_map(fn ($h) => preg_replace('/[^a-z0-9]/', '', strtolower(trim((string) $h))), $header);

        $ntnIndex = $this->findColumn($normalizedHeader, self::NTN_COLUMN_ALIASES);
        $statusIndex = $this->findColumn($normalizedHeader, self::STATUS_COLUMN_ALIASES);

        if ($ntnIndex === null || $statusIndex === null) {
            fclose($handle);
            return [
                'matched' => 0, 'updated' => 0, 'skipped' => 0,
                'errors' => [
                    'Could not find NTN and/or Status columns in the file header: ' . implode(', ', $header)
                    . '. Expected a column matching one of [' . implode('/', self::NTN_COLUMN_ALIASES) . '] '
                    . 'and one matching [' . implode('/', self::STATUS_COLUMN_ALIASES) . '].',
                ],
            ];
        }

        $matched = 0;
        $updated = 0;
        $skipped = 0;
        $now = now();

        while (($row = fgetcsv($handle)) !== false) {
            $rawNtn = $row[$ntnIndex] ?? null;
            $rawStatus = $row[$statusIndex] ?? null;
            if ($rawNtn === null) {
                continue;
            }

            $ntn = preg_replace('/\D/', '', (string) $rawNtn);
            if (strlen($ntn) !== 7) {
                $skipped++;
                continue;
            }

            $customer = Customer::where('ntn', $ntn)->first();
            if (! $customer) {
                $skipped++; // not one of our saved customers - not an error, just not relevant
                continue;
            }

            $matched++;
            $status = $this->normalizeStatus((string) $rawStatus);
            $customer->update(['atl_status' => $status, 'atl_checked_at' => $now]);
            $updated++;
        }

        fclose($handle);

        return ['matched' => $matched, 'updated' => $updated, 'skipped' => $skipped, 'errors' => []];
    }

    private function findColumn(array $normalizedHeader, array $aliases): ?int
    {
        foreach ($aliases as $alias) {
            $index = array_search($alias, $normalizedHeader, true);
            if ($index !== false) {
                return $index;
            }
        }

        return null;
    }

    private function normalizeStatus(string $raw): string
    {
        $raw = strtolower(trim($raw));

        return match (true) {
            str_contains($raw, 'active') && ! str_contains($raw, 'in') => Customer::ATL_ACTIVE,
            str_contains($raw, 'inactive') || str_contains($raw, 'in-active') => Customer::ATL_INACTIVE,
            default => Customer::ATL_UNKNOWN,
        };
    }
}
