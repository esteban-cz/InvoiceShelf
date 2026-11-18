<?php

namespace App\Domains\Receivables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FioConnection extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['token'];

    protected function casts(): array
    {
        return ['token' => 'encrypted', 'enabled' => 'boolean', 'auto_match' => 'boolean',
            'sync_from' => 'date:Y-m-d', 'imported_through' => 'date:Y-m-d',
            'last_synced_at' => 'datetime', 'last_attempt_at' => 'datetime'];
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FioTransaction::class);
    }
}
