<?php

namespace App\Domains\Receivables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FioConnectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'name' => $this->name, 'iban' => $this->iban, 'currency_code' => $this->currency_code,
            'enabled' => $this->enabled, 'auto_match' => $this->auto_match, 'payment_method_id' => $this->payment_method_id,
            'sync_from' => $this->sync_from->toDateString(), 'imported_through' => $this->imported_through?->toDateString(),
            'last_synced_at' => $this->last_synced_at?->toIso8601String(), 'last_error' => $this->last_error];
    }
}
