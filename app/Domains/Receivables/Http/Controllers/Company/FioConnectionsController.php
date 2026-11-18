<?php

namespace App\Domains\Receivables\Http\Controllers\Company;

use App\Domains\Accounts\Models\Company;
use App\Domains\Receivables\Application\FioConnectionService;
use App\Domains\Receivables\Application\FioSyncService;
use App\Domains\Receivables\Http\Requests\SaveFioConnectionRequest;
use App\Domains\Receivables\Http\Resources\FioConnectionResource;
use App\Domains\Receivables\Models\FioConnection;
use App\Platform\Http\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;

class FioConnectionsController extends Controller
{
    public function __construct(private readonly FioConnectionService $connections, private readonly FioSyncService $sync) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manage company', Company::findOrFail($request->header('company')));

        return FioConnectionResource::collection(FioConnection::query()->where('company_id', $request->header('company'))->get());
    }

    public function store(SaveFioConnectionRequest $request): FioConnectionResource
    {
        return new FioConnectionResource($this->connections->save($request->validated(), (int) $request->header('company'), $request->user()->id));
    }

    public function update(SaveFioConnectionRequest $request, int $connection): FioConnectionResource
    {
        $record = FioConnection::query()->where('company_id', $request->header('company'))->findOrFail($connection);

        return new FioConnectionResource($this->connections->save($request->validated(), $record->company_id, $request->user()->id, $record));
    }

    public function sync(Request $request, int $connection): FioConnectionResource
    {
        $this->authorize('manage company', Company::findOrFail($request->header('company')));
        $record = FioConnection::query()->where('company_id', $request->header('company'))->findOrFail($connection);

        return new FioConnectionResource($this->sync->sync($record));
    }

    public function destroy(Request $request, int $connection): Response
    {
        $this->authorize('manage company', Company::findOrFail($request->header('company')));
        $this->connections->disconnect(FioConnection::query()->where('company_id', $request->header('company'))->findOrFail($connection));

        return response()->noContent();
    }
}
