<?php

namespace App\Platform\Operations\Http;

use App\Platform\Http\Controller;
use App\Platform\Operations\Application\BuildVersionService;
use App\Platform\Operations\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public probe describing the running build.
 *
 * The fork's display version and upstream compatibility version, the release
 * channel and whether the in-app updater is available, without authentication.
 */
class AppVersionController extends Controller
{
    public function __construct(
        private readonly BuildVersionService $version,
    ) {}

    /**
     * @return JsonResponse
     */
    public function __invoke(Request $request)
    {
        return response()->json([
            'version' => $this->version->upstream(),
            'fork_version' => $this->version->fork(),
            'display_version' => $this->version->display(),
            'channel' => $this->releaseChannel(),
            'containerized' => (bool) config('invoiceshelf.containerized'),
        ]);
    }

    /**
     * The stored channel, self-healing: the first caller to find nothing stored
     * gets "stable" and leaves that default behind for everyone after them.
     */
    private function releaseChannel(): mixed
    {
        $stored = Setting::getSetting('updater_channel');

        if (! is_null($stored)) {
            return $stored;
        }

        Setting::setSetting('updater_channel', 'stable');

        return 'stable';
    }
}
