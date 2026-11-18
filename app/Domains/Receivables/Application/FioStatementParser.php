<?php

namespace App\Domains\Receivables\Application;

use DateTimeImmutable;
use Illuminate\Support\Str;

/** Fio's XML preserves decimal amounts and movement IDs without JSON float conversion. */
class FioStatementParser
{
    public function parse(string $body): array
    {
        abort_if(strlen($body) > 10_000_000 || stripos($body, '<!DOCTYPE') !== false, 502, __('fio_invalid_statement'));
        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body, \SimpleXMLElement::class, LIBXML_NONET);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        abort_unless($xml && $xml->getName() === 'AccountStatement' && isset($xml->Info, $xml->TransactionList), 502, __('fio_invalid_statement'));
        $iban = strtoupper(preg_replace('/\s+/', '', (string) $xml->Info->iban));
        $currency = (string) $xml->Info->currency;
        abort_unless(preg_match('/\A[A-Z]{2}[0-9]{2}[A-Z0-9]{11,30}\z/', $iban) && preg_match('/\A[A-Z]{3}\z/', $currency), 502, __('fio_invalid_statement'));
        $transactions = [];
        foreach ($xml->TransactionList->Transaction as $row) {
            $id = (string) $row->column_22;
            $date = substr((string) $row->column_0, 0, 10);
            $parsedDate = DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            $amount = (string) $row->column_1;
            $code = (string) $row->column_14;
            $vs = trim((string) $row->column_5);
            abort_unless(preg_match('/\A[0-9]{1,40}\z/', $id) && $parsedDate && $parsedDate->format('Y-m-d') === $date
                && preg_match('/\A(-?)([0-9]{1,14})(?:\.([0-9]{1,2}))?\z/', $amount, $parts)
                && preg_match('/\A[A-Z]{3}\z/', $code)
                && ($vs === '' || preg_match('/\A[0-9]{1,10}\z/', $vs)), 502, __('fio_invalid_statement'));
            $minor = ((int) $parts[2] * 100 + (int) str_pad($parts[3] ?? '', 2, '0')) * ($parts[1] === '-' ? -1 : 1);
            $account = trim((string) $row->column_2);
            $bank = trim((string) $row->column_3);
            $transactions[] = [
                'external_id' => $id, 'transaction_date' => $date, 'amount' => $minor, 'currency_code' => $code,
                'variable_symbol' => $vs !== '' ? $vs : null,
                'counterparty_account' => Str::limit($account.($bank !== '' ? '/'.$bank : ''), 255, ''),
                'counterparty_name' => Str::limit((string) $row->column_10, 255, ''),
                'message' => Str::limit((string) ($row->column_16 ?? $row->column_7), 2000, ''),
            ];
        }

        return ['iban' => $iban, 'currency_code' => $currency, 'transactions' => $transactions];
    }
}
