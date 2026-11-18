<?php

namespace App\Domains\Sales\Application\Import;

use DateTimeImmutable;
use Illuminate\Support\Str;

/** Conservative suggestions from text, never instructions or executable markup. */
class InvoiceImportParser
{
    private const NUMBER = '-?\d+(?:[ .,\x{00a0}]\d{3})*(?:[.,]\d{1,4})?';

    private const MONEY = '-?\d[\d .\x{00a0},]*[\d]|-?\d';

    public function parse(string $text): array
    {
        $text = str_replace(["\r\n", "\r", "\f", "\xc2\xa0", "\xe2\x80\xaf"], ["\n", "\n", "\n", ' ', ' '], $text);
        $lines = explode("\n", $text);
        $ucto = str_contains(Str::ascii(mb_strtolower($text)), 'ucto2000.cz');
        $warnings = [];
        $number = null;
        foreach ($lines as $line) {
            $ascii = Str::ascii($line);
            if (preg_match('/(?:faktura\s+.*?cislo|cislo\s+faktury|invoice\s*(?:number|no\.?|#)|faktura\s*(?:c\.|#))\s*[:#]?\s*([A-Z0-9][A-Z0-9\/_.-]{1,99})/i', $ascii, $m)
                || preg_match('/^\s*(?:faktura|invoice)\s+([A-Z0-9][A-Z0-9\/_.-]{2,99})\s*$/i', $ascii, $m)) {
                if (preg_match('/\d/', $m[1])) {
                    $number = $m[1];
                    break;
                }
            }
        }
        $invoiceDate = $this->dateAfter($lines, 'datum\s+vystaveni(?:\s+dokladu)?|vystaveno|invoice\s+date|issue\s+date|date\s+of\s+issue');
        $dueDate = $this->dateAfter($lines, 'datum\s+splatnosti|splatnost|splatne\s+do|due\s+date|payment\s+due');
        $currency = $this->currency($text);
        $customer = $this->party($lines, 'customer', $ucto);
        $supplier = $this->party($lines, 'supplier', $ucto);
        $symbol = $this->after($lines, '/(?:variabilni\s+symbol|variable\s+symbol|\bVS)\s*[:#]?\s*([0-9]{1,10})\b/i');
        $account = $this->after($lines, '/(?:cislo\s+uctu|bank\s+account|account\s+number)\s*:?\s*((?:[0-9]{1,6}-)?[0-9]{1,10}\/[0-9]{4})\b/i');
        $iban = $this->after($lines, '/\bIBAN\s*:?\s*([A-Z]{2}[0-9]{2}(?:[ ]?[A-Z0-9]){11,30})/i');
        $bank = $this->after($lines, '/(?:penezni\s+ustav|bank\s+name|banka)\s*:\s*(.+)/i');
        if ($ucto) {
            foreach ($lines as $line) {
                if (preg_match('/peněžní\s+ústav\s+(.+?)(?:\s{3,}|$)/ui', $line, $m)) {
                    $bank = trim($m[1]);
                }
            }
        }
        $total = null;
        foreach ($lines as $line) {
            $ascii = Str::ascii($line);
            if (preg_match('/(?:castka|celkem\s*(?:k\s+uhrade)?|k\s+uhrade|amount\s+due|grand\s+total|total\s+due|^\s*total)\s*[:.=]*\s*=?\s*('.self::MONEY.')\s*(?:Kc|CZK|EUR|USD|GBP|€|\$|£)?\s*$/iu', $ascii, $m)) {
                $candidate = $this->minor($m[1]);
                if ($candidate !== null) {
                    $total = $candidate;
                }
            }
        }
        [$items, $itemWarnings] = $this->items($lines, $ucto);
        $warnings = array_merge($warnings, $itemWarnings);
        if (! $number) {
            $warnings[] = 'number_missing';
        }
        if (! $invoiceDate) {
            $warnings[] = 'invoice_date_missing';
        }
        if (! $dueDate) {
            $warnings[] = 'due_date_missing';
        }
        if (! $currency) {
            $warnings[] = 'currency_missing';
        }
        if ($total === null) {
            $warnings[] = 'total_missing';
        }
        if (! $customer['name'] && ! $customer['registration_number']) {
            $warnings[] = 'customer_missing';
        }
        if (! $items) {
            $warnings[] = 'items_missing';
        }
        if (! $ucto) {
            $warnings[] = 'generic_format';
        }
        $issuer = null;
        $notes = [];
        foreach ($lines as $line) {
            if (preg_match('/(?:Vystavil|Issued\s+by)\s*:?\s*(.+)/ui', $line, $m)) {
                $issuer = trim($m[1]);
            }
            if (preg_match('/^\s*(?:Fakturuji\s+Vám\s+.+|(?:Poznámka|Poznámky|Notes?)\s*:\s*.+)\s*$/ui', $line)) {
                $notes[] = trim($line);
            }
            if (preg_match('/^\s*(?:ŽL\b|Zapsán\b|Zapsána\b|Nejsem\s+plátce\b|Nejsme\s+plátci\b)/ui', $line)) {
                $notes[] = trim($line);
            }
        }

        return ['format' => $ucto ? 'ucto2000' : 'generic', 'invoice_number' => $number, 'invoice_date' => $invoiceDate,
            'due_date' => $dueDate, 'currency_code' => $currency, 'total' => $total, 'items' => $items,
            'notes' => $notes ? implode("\n", array_unique($notes)) : null, 'issuer_name' => $issuer,
            'supplier' => $supplier, 'customer' => $customer,
            'payment_qr' => ($iban || $account || $symbol) ? ['iban' => $iban ?: $account, 'variable_symbol' => $symbol, 'bank_name' => $bank] : null,
            'warnings' => array_values(array_unique($warnings))];
    }

    private function currency(string $text): ?string
    {
        if (preg_match('/\bCZK\b|Kč\b/u', $text)) {
            return 'CZK';
        }
        if (preg_match('/\bEUR\b|€/u', $text)) {
            return 'EUR';
        }
        if (preg_match('/\bUSD\b|\$/u', $text)) {
            return 'USD';
        }
        if (preg_match('/\bGBP\b|£/u', $text)) {
            return 'GBP';
        }

        return preg_match('/\b(CHF|PLN|HUF|NOK|SEK|DKK|CAD|AUD|JPY)\b/', $text, $m) ? $m[1] : null;
    }

    private function after(array $lines, string $pattern): ?string
    {
        foreach ($lines as $line) {
            if (preg_match($pattern, Str::ascii($line), $m, PREG_OFFSET_CAPTURE)) {
                return trim(mb_substr($line, $m[1][1], strlen($m[1][0])));
            }
        }

        return null;
    }

    private function dateAfter(array $lines, string $labels): ?string
    {
        foreach ($lines as $index => $line) {
            $ascii = Str::ascii($line);
            if (! preg_match('/(?:'.$labels.')\s*:?\s*(.*)$/i', $ascii, $m)) {
                continue;
            }
            $value = trim($m[1]);
            if ($value === '') {
                $value = trim($lines[$index + 1] ?? '');
            }
            if (! preg_match('/\b(\d{4}[-\/.]\d{1,2}[-\/.]\d{1,2}|\d{1,2}[.\/-]\s*\d{1,2}[.\/-]\s*\d{4})\b/', $value, $dateMatch)) {
                continue;
            }
            $raw = preg_replace('/\s+/', '', $dateMatch[1]);
            foreach (['!Y-m-d', '!Y/m/d', '!Y.m.d', '!d.m.Y', '!d/m/Y', '!d-m-Y'] as $format) {
                $parsed = DateTimeImmutable::createFromFormat($format, $raw);
                $errors = DateTimeImmutable::getLastErrors();
                if ($parsed && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0))) {
                    return $parsed->format('Y-m-d');
                }
            }
        }

        return null;
    }

    private function party(array $lines, string $kind, bool $ucto): array
    {
        $party = ['name' => null, 'registration_number' => null, 'tax_id' => null, 'email' => null, 'phone' => null,
            'address_street_1' => null, 'address_street_2' => null, 'city' => null, 'zip' => null, 'country_code' => null];
        $start = null;
        $column = 0;
        $label = $kind === 'customer' ? 'odberatel|bill\s+to|customer|buyer' : 'dodavatel|supplier|seller|from';
        foreach ($lines as $index => $line) {
            if (! preg_match('/\b(?:'.$label.')\b/i', Str::ascii($line), $m, PREG_OFFSET_CAPTURE)) {
                continue;
            }
            if ($kind === 'customer' && preg_match('/cislo\s+odberatele/i', Str::ascii($line))) {
                continue;
            }
            $start = $index;
            if ($ucto && $kind === 'customer') {
                $column = max(0, strpos(Str::ascii($line), 'odberatel'));
            }
            break;
        }
        if ($start === null) {
            return $party;
        }
        $supplierWidth = 62;
        if ($ucto && $kind === 'supplier') {
            foreach ($lines as $line) {
                if (preg_match('/(?:variabilni\s+symbol|konstantni\s+symbol|penezni\s+ustav|cislo\s+uctu)/i', Str::ascii($line), $match, PREG_OFFSET_CAPTURE)) {
                    $supplierWidth = min($supplierWidth, $match[0][1]);
                }
            }
        }
        $content = [];
        foreach (array_slice($lines, $start, 13) as $offset => $line) {
            if ($ucto && $kind === 'supplier') {
                $line = mb_substr($line, 0, $supplierWidth);
            }
            if ($column > 0) {
                $line = mb_substr($line, $column);
            }
            $ascii = Str::ascii($line);
            if ($offset > 0 && preg_match('/(?:platebni\s+podminky|datum\s+|due\s+date|invoice\s+date|polozk[ay]|description|\bitems?\b|\btotal\b|pen[ei]zni\s+ustav|cislo\s+uctu|odberatel|bill\s+to)/i', $ascii)) {
                break;
            }
            if (preg_match('/\bIC(?:O)?\s*:?\s*(\d{8})\b/i', $ascii, $m)) {
                $party['registration_number'] = $m[1];
            }
            if (preg_match('/\bDIC\s*:?\s*([A-Z]{2}[A-Z0-9]{4,15})\b/i', $ascii, $m)) {
                $party['tax_id'] = $m[1];
            }
            if (preg_match('/\b(?:Tel(?:efon)?|Phone)\s*:?\s*(\+?[0-9][0-9 ()-]{5,25})/i', $ascii, $m)) {
                $party['phone'] = trim($m[1]);
            }
            if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $line, $m)) {
                $party['email'] = $m[1];

                continue;
            }
            if ($offset === 0) {
                preg_match('/^.*?(?:'.$label.')\s*:?\s*/i', Str::ascii($line), $prefix);
                $line = mb_substr($line, mb_strlen($prefix[0] ?? ''));
                if (preg_match('/\b(?:IC|DIC|VAT|Registration)\b/i', $line)) {
                    continue;
                }
            }
            $line = trim($line);
            if ($line === '' || preg_match('/^(?:IC(?:O)?|DIC|VAT|Registration|E-?mail|Tel(?:efon)?|Phone)\b/i', Str::ascii($line))) {
                continue;
            }
            if (preg_match('/([A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,})/i', $line, $m)) {
                $party['email'] = $m[1];

                continue;
            }
            if (preg_match('/^\s*(\d{3})\s?(\d{2})\s+(.+)$/u', $line, $m)) {
                $party['zip'] = $m[1].' '.$m[2];
                $party['city'] = trim($m[3]);
                $party['country_code'] = 'CZ';
                break;
            }
            $content[] = $line;
        }
        $streetIndex = null;
        foreach ($content as $index => $line) {
            if (preg_match('/\d/', $line) && ! preg_match('/^(?:jednotek|s\.r\.o\.|a\.s\.)/iu', $line)) {
                $streetIndex = $index;
            }
        }
        if ($streetIndex !== null) {
            $party['name'] = trim(implode(' ', array_slice($content, 0, $streetIndex))) ?: null;
            $party['address_street_1'] = $content[$streetIndex];
            $party['address_street_2'] = implode(', ', array_slice($content, $streetIndex + 1)) ?: null;
        } else {
            $party['name'] = trim(implode(' ', $content)) ?: null;
        }
        foreach ($party as $key => $value) {
            if (is_string($value)) {
                $party[$key] = mb_substr($value, 0, 255);
            }
        }

        return $party;
    }

    private function items(array $lines, bool $ucto): array
    {
        $items = [];
        $warnings = [];
        $start = 0;
        $order = $ucto ? 'price_quantity' : null;
        foreach ($lines as $index => $line) {
            $ascii = Str::ascii(mb_strtolower($line));
            if (preg_match('/(?:polozk[ay]|popis|description|items?)\b/', $ascii) && preg_match('/(?:cena|price|mnozstvi|quantity|qty)/', $ascii)) {
                $start = $index + 1;
                $quantityAt = preg_match('/mnozstvi|quantity|qty/', $ascii, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
                $priceAt = preg_match('/jednotkova\s+cena|unit\s+price|price|cena/', $ascii, $m, PREG_OFFSET_CAPTURE) ? $m[0][1] : false;
                if ($quantityAt !== false && $priceAt !== false) {
                    $order = $priceAt < $quantityAt ? 'price_quantity' : 'quantity_price';
                }
                break;
            }
        }
        $pending = '';
        foreach (array_slice($lines, $start) as $line) {
            $ascii = Str::ascii(trim($line));
            if (preg_match('/^(?:celkem|mezisoucet|sub\s*total|grand\s+total|total|DPH|VAT|Tax|Pocet\s+stran|Vystavil|Issued\s+by|Poznamk|Notes?\b|ZL\b)/i', $ascii)) {
                if ($items) {
                    break;
                } else {
                    continue;
                }
            }
            if (preg_match('/Fakturuji|Fakturujeme|Description|Thank\s+you/i', $ascii) || trim($line) === '') {
                continue;
            }
            $pattern = '/^\s*(.+?)\s{2,}('.self::NUMBER.')\s*(ks|hod|h|pcs|hours)?\s{2,}('.self::NUMBER.')\s*(ks|hod|h|pcs|hours)?\s{2,}('.self::NUMBER.')(?:\s*(?:Kč|CZK|EUR|USD|€|\$))?(?:\s+([0-9]{1,2}(?:[.,][0-9]{1,2})?\s*%?|[-–]))?\s*$/ui';
            if (! preg_match($pattern, $line, $m)) {
                if ($start > 0 && ! preg_match('/\d[.,]\d{2}/', $line) && mb_strlen(trim($line)) < 250) {
                    $pending = trim($pending.' '.trim($line));
                }

                continue;
            }
            $price = $this->minor($order === 'price_quantity' ? $m[2] : $m[4]);
            $quantity = $this->quantity($order === 'price_quantity' ? $m[4] : $m[2]);
            $lineTotal = $this->minor($m[6]);
            if ($price === null || $price < 0 || $quantity === null || $quantity <= 0 || $lineTotal === null || $lineTotal < 0) {
                $warnings[] = 'items_uncertain';

                continue;
            }
            $name = trim($pending.' '.$m[1]);
            $pending = '';
            $vat = isset($m[7]) && is_numeric(str_replace([',', '%', ' '], ['.', '', ''], $m[7])) ? (float) str_replace([',', '%', ' '], ['.', '', ''], $m[7]) : null;
            $items[] = ['name' => mb_substr($name, 0, 255), 'quantity' => $quantity, 'price' => $price, 'total' => $lineTotal, 'unit_name' => ($order === 'price_quantity' ? ($m[5] ?? '') : ($m[3] ?? '')) ?: null, 'vat_percent' => $vat];
            if (abs((int) round($price * $quantity) - $lineTotal) > 1) {
                $warnings[] = 'line_total_mismatch';
            }
            if (count($items) >= 200) {
                $warnings[] = 'items_truncated';
                break;
            }
        }
        if ($items && $order === null) {
            $warnings[] = 'item_order_assumed';
        }

        return [$items, $warnings];
    }

    public function minor(string $value): ?int
    {
        $value = preg_replace('/[\s\x{00a0}]/u', '', trim($value));
        if (! preg_match('/\A-?[0-9]+(?:[.,][0-9]+)*\z/', $value)) {
            return null;
        }
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        $last = max(strrpos($value, '.') === false ? -1 : strrpos($value, '.'), strrpos($value, ',') === false ? -1 : strrpos($value, ','));
        $decimals = $last >= 0 ? strlen($value) - $last - 1 : 0;
        if ($last >= 0 && $decimals <= 2) {
            $whole = preg_replace('/[.,]/', '', substr($value, 0, $last));
            $fraction = str_pad(substr($value, $last + 1), 2, '0');
        } elseif ($last < 0 || $decimals === 3) {
            $whole = preg_replace('/[.,]/', '', $value);
            $fraction = '00';
        } else {
            return null;
        }
        if (strlen($whole) > 10) {
            return null;
        }
        $amount = (int) $whole * 100 + (int) $fraction;

        return $amount <= 999_999_999_999 ? ($negative ? -$amount : $amount) : null;
    }

    private function quantity(string $value): ?float
    {
        $value = str_replace(',', '.', $value);

        return preg_match('/\A[0-9]+(?:\.[0-9]{1,4})?\z/', $value) && (float) $value <= 1_000_000 ? (float) $value : null;
    }
}
