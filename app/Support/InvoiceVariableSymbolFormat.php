<?php

namespace App\Support;

enum InvoiceVariableSymbolFormat: string
{
    public const SETTING = 'invoice_variable_symbol_format';

    case Manual = 'manual';
    case InvoiceNumber = 'invoice_number';
    case YearSequence = 'year_sequence';
    case Sequence = 'sequence';

    public function generate(string $invoiceNumber, string $invoiceDate, int $sequence): ?string
    {
        $symbol = match ($this) {
            self::Manual => '',
            self::InvoiceNumber => preg_replace('/[^0-9]/', '', $invoiceNumber),
            self::YearSequence => $this->yearSequence($invoiceDate, $sequence),
            self::Sequence => str_pad((string) $sequence, 6, '0', STR_PAD_LEFT),
        };

        return preg_match('/\A[0-9]{1,10}\z/', $symbol) ? $symbol : null;
    }

    private function yearSequence(string $invoiceDate, int $sequence): string
    {
        return preg_match('/\A([0-9]{4})[-\/]/', $invoiceDate, $date)
            ? $date[1].str_pad((string) $sequence, 3, '0', STR_PAD_LEFT)
            : '';
    }
}
