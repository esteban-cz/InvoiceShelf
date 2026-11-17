<?php

namespace App\Domains\Sales\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceBankAccount extends Model
{
    protected $table = 'invoice_bank_accounts';

    protected $fillable = ['company_id', 'iban', 'bank_name'];
}
