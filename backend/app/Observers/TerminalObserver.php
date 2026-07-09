<?php

namespace App\Observers;

use App\Models\Terminal;
use App\Models\UsinCounter;

class TerminalObserver
{
    public function created(Terminal $terminal): void
    {
        UsinCounter::query()->firstOrCreate(
            ['terminal_id' => $terminal->id],
            ['last_value' => 0],
        );
    }
}
