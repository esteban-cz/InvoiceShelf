<?php

namespace App\Platform\Operations\Http\Admin;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\User;
use App\Platform\Http\Controller;
use App\Platform\Operations\Application\BuildVersionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class AdminDashboardController extends Controller
{
    public function __construct(
        private readonly BuildVersionService $version,
    ) {}

    public function index(): JsonResponse
    {
        $dbDriver = config('database.default');
        $dbVersion = $this->getDatabaseVersion($dbDriver);

        return response()->json([
            'app_version' => $this->version->display(),
            'php_version' => phpversion(),
            'database' => [
                'driver' => $dbDriver,
                'version' => $dbVersion,
            ],
            'counts' => [
                'companies' => Company::count(),
                'users' => User::count(),
            ],
        ]);
    }

    private function getDatabaseVersion(string $driver): ?string
    {
        try {
            return match ($driver) {
                'mysql' => DB::selectOne('SELECT VERSION() as version')?->version,
                'pgsql' => DB::selectOne('SHOW server_version')?->server_version,
                'sqlite' => DB::selectOne('SELECT sqlite_version() as version')?->version,
                default => null,
            };
        } catch (\Throwable) {
            return null;
        }
    }
}
