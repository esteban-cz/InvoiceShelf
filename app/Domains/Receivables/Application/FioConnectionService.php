<?php

namespace App\Domains\Receivables\Application;

use App\Domains\Receivables\Models\FioConnection;
use App\Domains\Receivables\Models\FioTransaction;
use Carbon\Carbon;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class FioConnectionService
{
    public function __construct(private readonly FioClient $client, private readonly FioSyncService $sync) {}

    public function save(array $input, int $companyId, int $creatorId, ?FioConnection $connection = null): FioConnection
    {
        $statement = null;
        if (! empty($input['token'])) {
            $from = $connection ? today('Europe/Prague')->toDateString() : $input['sync_from'];
            $to = $connection ? $from : min(today('Europe/Prague')->toDateString(), Carbon::parse($from)->addDays(30)->toDateString());
            $statement = $this->client->statement($input['token'], $from, $to);
            if ($connection && ($statement['iban'] !== $connection->iban || $statement['currency_code'] !== $connection->currency_code)) {
                throw ValidationException::withMessages(['token' => __('fio_account_changed')]);
            }
        }
        $lock = Cache::lock('fio:setup:'.$companyId, 60);
        abort_unless($lock->get(), 429, __('fio_throttled'));
        try {
            if (! $connection && FioConnection::query()->where('company_id', $companyId)->where('iban', $statement['iban'])->where('currency_code', $statement['currency_code'])->exists()) {
                throw ValidationException::withMessages(['token' => __('fio_account_exists')]);
            }
            $attributes = Arr::except($input, ['token']);
            if (! empty($input['token'])) {
                $attributes['token'] = $input['token'];
            }
            if ($connection) {
                $connection->update($attributes + ['last_error' => null]);
            } else {
                $connection = FioConnection::query()->create($attributes + ['company_id' => $companyId, 'creator_id' => $creatorId,
                    'iban' => $statement['iban'], 'currency_code' => $statement['currency_code']]);
                FioTransaction::query()->where('company_id', $companyId)
                    ->where('account_iban', $connection->iban)->where('currency_code', $connection->currency_code)
                    ->whereNull('fio_connection_id')->update(['fio_connection_id' => $connection->id]);
                $this->sync->import($connection, $statement, $to);
            }
        } finally {
            $lock->release();
        }

        return $connection->fresh();
    }

    public function disconnect(FioConnection $connection): void
    {
        $lock = Cache::lock('fio:sync:'.$connection->id, 120);
        abort_unless($lock->get(), 429, __('fio_throttled'));
        try {
            DB::transaction(function () use ($connection): void {
                $connection->transactions()->update(['fio_connection_id' => null]);
                $connection->delete();
            });
        } finally {
            $lock->release();
        }
    }
}
