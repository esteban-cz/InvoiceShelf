<?php

namespace App\Domains\Contacts\Http\Controllers\Company;

use App\Domains\Contacts\Application\AresLookupService;
use App\Domains\Contacts\Http\Requests\AresLookupRequest;
use App\Domains\Contacts\Http\Resources\AresCompanyResource;
use App\Domains\Contacts\Models\Customer;
use App\Platform\Http\Controller;

class AresLookupController extends Controller
{
    public function __construct(private readonly AresLookupService $ares) {}

    public function __invoke(AresLookupRequest $request): AresCompanyResource
    {
        $this->authorize('viewAny', Customer::class);

        return new AresCompanyResource($this->ares->lookup($request->validated('ico')));
    }
}
