<?php

namespace App\Domains\Contacts\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AresCompanyResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'registration_number' => $this->resource['registration_number'],
            'name' => $this->resource['name'],
            'tax_id' => $this->resource['tax_id'],
            'billing' => $this->resource['billing'],
        ];
    }
}
