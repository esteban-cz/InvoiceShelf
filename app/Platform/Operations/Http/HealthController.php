<?php

namespace App\Platform\Operations\Http;

use App\Platform\Http\Controller;
use App\Platform\Operations\Application\HealthCheckService;
use App\Platform\Operations\Http\Resources\HealthResource;
use Illuminate\Http\JsonResponse;

class HealthController extends Controller
{
    public function __construct(
        private readonly HealthCheckService $health,
    ) {}

    /**
     * Public readiness probe for uptime monitors.
     */
    public function __invoke(): JsonResponse
    {
        $health = $this->health->check();

        return (new HealthResource($health))->response()
            ->setStatusCode($health['status'] === 'healthy' ? 200 : 503)
            ->header('Cache-Control', 'no-store');
    }
}
