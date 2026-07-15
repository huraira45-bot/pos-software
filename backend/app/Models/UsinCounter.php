<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsinCounter extends Model
{
    protected $fillable = ['terminal_id', 'usin_type', 'last_value'];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
