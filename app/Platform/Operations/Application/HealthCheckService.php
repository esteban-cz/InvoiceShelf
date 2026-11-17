<?php

namespace App\Platform\Operations\Application;

use Illuminate\Database\DatabaseManager;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Support\Str;
use Throwable;

class HealthCheckService
{
    public function __construct(
        private readonly DatabaseManager $database,
        private readonly FilesystemManager $filesystems,
    ) {}

    /**
     * @return array{status: string, database: string, uploads: string}
     */
    public function check(): array
    {
        $database_connected = $this->databaseConnected();
        $uploads_writable = $this->uploadsWritable();

        return [
            'status' => $database_connected && $uploads_writable ? 'healthy' : 'unhealthy',
            'database' => $database_connected ? 'connected' : 'unavailable',
            'uploads' => $uploads_writable ? 'writable' : 'not writable',
        ];
    }

    private function databaseConnected(): bool
    {
        try {
            $this->database->connection()->select('SELECT 1', [], false);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Exercise the configured upload disk and remove the unique probe even
     * when a write fails after creating a partial file.
     */
    private function uploadsWritable(): bool
    {
        try {
            $disk = $this->filesystems->disk(config('media-library.disk_name'));
            $path = '.health-check-'.Str::uuid();

            try {
                $written = $disk->put($path, 'health-check');
            } finally {
                $deleted = $disk->delete($path);
            }

            return $written && $deleted;
        } catch (Throwable) {
            return false;
        }
    }
}
