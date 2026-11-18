<?php

namespace App\Domains\Receivables\Application;

use App\Support\Net\PrivateNetworkGuard;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class FioClient
{
    public function __construct(private readonly FioStatementParser $parser) {}

    public function statement(#[\SensitiveParameter] string $token, string $from, string $to): array
    {
        abort_unless(preg_match('/\A[a-zA-Z0-9]{64}\z/', $token), 422, __('fio_invalid_token'));
        abort_unless(Cache::add('fio:request:'.hash('sha256', $token), true, 35), 429, __('fio_throttled'));
        // Fio requires the token in its path. Never throw/log a transport exception containing this URL.
        $url = 'https://fioapi.fio.cz/v1/rest/periods/'.$token.'/'.$from.'/'.$to.'/transactions.xml';
        PrivateNetworkGuard::assertAllowed('https://fioapi.fio.cz');
        try {
            $response = Http::accept('application/xml')->connectTimeout(5)->timeout(30)->withoutRedirecting()->get($url);
        } catch (\Throwable) {
            abort(503, __('fio_unavailable'));
        }
        abort_if($response->status() === 409, 429, __('fio_throttled'));
        abort_if(in_array($response->status(), [401, 403, 500], true), 422, __('fio_invalid_token'));
        abort_unless($response->successful(), 503, __('fio_unavailable'));

        return $this->parser->parse($response->body());
    }
}
