<?php

namespace App\Domains\Contacts\Application;

use App\Domains\Contacts\Models\Country;
use App\Support\Net\PrivateNetworkGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;

class AresLookupService
{
    public function lookup(string $ico): array
    {
        return Cache::remember('ares:company:v1:'.$ico, now()->addDay(), function () use ($ico): array {
            $url = 'https://ares.gov.cz/ekonomicke-subjekty-v-be/rest/ekonomicke-subjekty/'.$ico;
            PrivateNetworkGuard::assertAllowed($url);
            try {
                $response = Http::acceptJson()->connectTimeout(5)->timeout(15)->withoutRedirecting()->get($url);
            } catch (\Throwable) {
                abort(503, __('ares_unavailable'));
            }
            if ($response->status() === 404) {
                throw ValidationException::withMessages(['ico' => __('ares_not_found')]);
            }
            if (! $response->successful() || strlen($response->body()) > 1_000_000) {
                abort(503, __('ares_unavailable'));
            }
            $data = $response->json();
            if (! is_array($data) || ($data['ico'] ?? null) !== $ico || ! is_string($data['obchodniJmeno'] ?? null)) {
                abort(503, __('ares_unavailable'));
            }
            $address = is_array($data['sidlo'] ?? null) ? $data['sidlo'] : [];
            $number = (string) ($address['cisloDomovni'] ?? '');
            if (! empty($address['cisloOrientacni'])) {
                $number .= '/'.$address['cisloOrientacni'].($address['cisloOrientacniPismeno'] ?? '');
            }
            $street = $address['nazevUlice'] ?? $address['nazevCastiObce'] ?? $address['nazevObce'] ?? '';
            if (($address['typCisloDomovni'] ?? null) === 2 && $number !== '') {
                $number = 'č. ev. '.$number;
            }
            $zip = (string) ($address['psc'] ?? $address['pscTxt'] ?? '');
            if (preg_match('/\A[0-9]{5}\z/', $zip)) {
                $zip = substr($zip, 0, 3).' '.substr($zip, 3);
            }

            return [
                'registration_number' => $ico,
                'name' => $data['obchodniJmeno'],
                'tax_id' => is_string($data['dic'] ?? null) ? $data['dic'] : null,
                'billing' => [
                    'name' => $data['obchodniJmeno'],
                    'address_street_1' => trim($street.' '.$number),
                    'address_street_2' => null,
                    'city' => $address['nazevObce'] ?? '',
                    'state' => $address['nazevKraje'] ?? null,
                    'zip' => $zip,
                    'country_id' => Country::query()->where('code', $address['kodStatu'] ?? 'CZ')->value('id'),
                ],
            ];
        });
    }
}
