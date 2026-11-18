<?php

namespace App\Domains\Receivables\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FioTransaction extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'integer', 'transaction_date' => 'date:Y-m-d'];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(FioConnection::class, 'fio_connection_id');
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }
}
