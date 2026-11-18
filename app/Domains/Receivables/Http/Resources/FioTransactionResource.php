<?php

namespace App\Domains\Receivables\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FioTransactionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'connection_id' => $this->fio_connection_id, 'account_iban' => $this->account_iban,
            'external_id' => $this->external_id, 'transaction_date' => $this->transaction_date->toDateString(),
            'amount' => $this->amount, 'currency_code' => $this->currency_code, 'variable_symbol' => $this->variable_symbol,
            'counterparty_name' => $this->counterparty_name, 'counterparty_account' => $this->counterparty_account,
            'message' => $this->message, 'status' => $this->status, 'reason' => $this->reason,
            'payment_id' => $this->payment_id, 'payment_number' => $this->payment?->payment_number];
    }
}
