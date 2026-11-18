<?php

namespace App\Domains\Receivables\Console;

use App\Domains\Receivables\Application\FioSyncService;
use App\Domains\Receivables\Models\FioConnection;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('fio:sync')]
#[Description('Import incoming Fio transactions and record exact invoice matches')]
class SyncFioConnections extends Command
{
    public function handle(FioSyncService $sync): int
    {
        if (! config('invoiceshelf.fio_enabled', false)) {
            $this->info('Fio integration is disabled.');

            return self::SUCCESS;
        }
        $failed = false;
        FioConnection::query()->where('enabled', true)->eachById(function (FioConnection $connection) use ($sync, &$failed): void {
            try {
                $sync->sync($connection);
            } catch (\Throwable) {
                // Bank tokens occur in transport URLs: never print the raw exception.
                $failed = true;
                $connection->update(['last_error' => $connection->fresh()?->last_error ?? 'unavailable']);
                $this->warn('Fio connection '.$connection->id.' needs attention. Check payment settings.');
            }
        });

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
