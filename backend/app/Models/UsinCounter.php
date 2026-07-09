<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsinCounter extends Model
{
    protected $primaryKey = 'terminal_id';
    public $incrementing = false;

    protected $fillable = ['terminal_id', 'last_value'];

    public function terminal(): BelongsTo
    {
        return $this->belongsTo(Terminal::class);
    }
}
