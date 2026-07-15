<?php

namespace App\Observers;

use App\Models\Terminal;
use App\Models\UsinCounter;
use App\Services\Fiscal\UsinGenerator;

class TerminalObserver
{
    public function created(Terminal $terminal): void
    {
        foreach (array_keys(UsinGenerator::SEPARATORS) as $usinType) {
            UsinCounter::query()->firstOrCreate(
                ['terminal_id' => $terminal->id, 'usin_type' => $usinType],
                ['last_value' => 0],
            );
        }
    }
}
