<?php

namespace App\Domains\Receivables\Application;

use App\Domains\Receivables\Models\FioConnection;
use App\Domains\Receivables\Models\FioTransaction;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class FioSyncService
{
    public function __construct(private readonly FioClient $client, private readonly FioMatchingService $matching) {}

    public function sync(FioConnection $connection): FioConnection
    {
        $lock = Cache::lock('fio:sync:'.$connection->id, 120);
        abort_unless($lock->get(), 429, __('fio_throttled'));
        try {
            $connection->refresh();
            abort_unless($connection->enabled, 422, __('fio_disabled'));
            $connection->update(['last_attempt_at' => now()]);
            $from = $connection->imported_through
                ? $connection->imported_through->copy()->subDays(7)->max($connection->sync_from)
                : $connection->sync_from->copy();
            $to = $from->copy()->addDays(30)->min(today('Europe/Prague'));
            $statement = $this->client->statement($connection->token, $from->toDateString(), $to->toDateString());
            abort_unless($statement['iban'] === $connection->iban && $statement['currency_code'] === $connection->currency_code, 422, __('fio_account_changed'));
            $this->import($connection, $statement, $to->toDateString());
        } catch (HttpExceptionInterface $error) {
            $connection->update(['last_error' => match ($error->getStatusCode()) {
                429 => 'throttled', 422 => 'token_or_account', default => 'unavailable'
            }]);
            throw $error;
        } finally {
            $lock->release();
        }

        return $connection->fresh();
    }

    public function import(FioConnection $connection, array $statement, string $through): void
    {
        // Commit bank rows and cursor together. A crash during matching leaves reviewable rows, never a lost movement.
        $created = DB::transaction(function () use ($connection, $statement, $through): array {
            $ids = [];
            foreach ($statement['transactions'] as $data) {
                $row = FioTransaction::query()->firstOrCreate(['company_id' => $connection->company_id, 'external_id' => $data['external_id']],
                    $data + ['fio_connection_id' => $connection->id, 'account_iban' => $connection->iban,
                        'status' => $data['amount'] > 0 ? 'pending' : 'ignored', 'reason' => $data['amount'] > 0 ? 'review' : 'outgoing']);
                if ($row->wasRecentlyCreated) {
                    $ids[] = $row->id;
                }
            }
            $connection->update(['imported_through' => $through, 'last_synced_at' => now(), 'last_error' => null]);

            return $ids;
        });
        if ($connection->enabled && $connection->auto_match) {
            foreach (FioTransaction::query()->whereIn('id', $created)->get() as $row) {
                $this->matching->autoMatch($row);
            }
        }
    }
}
