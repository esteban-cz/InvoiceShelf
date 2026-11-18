<?php

namespace App\Domains\Receivables\Http\Controllers\Company;

use App\Domains\Accounts\Models\Company;
use App\Domains\Receivables\Application\FioMatchingService;
use App\Domains\Receivables\Http\Requests\MatchFioTransactionRequest;
use App\Domains\Receivables\Http\Resources\FioTransactionResource;
use App\Domains\Receivables\Models\FioTransaction;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class FioTransactionsController extends Controller
{
    public function __construct(private readonly FioMatchingService $matching) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('manage company', Company::findOrFail($request->header('company')));
        $status = in_array($request->query('status'), ['pending', 'matched', 'ignored'], true) ? $request->query('status') : 'pending';

        return FioTransactionResource::collection(FioTransaction::query()->with('payment')->where('company_id', $request->header('company'))
            ->where('status', $status)->orderByDesc('transaction_date')->orderByDesc('id')->paginate(20));
    }

    public function candidates(Request $request, int $transaction): JsonResponse
    {
        $this->authorize('manage company', Company::findOrFail($request->header('company')));
        $row = FioTransaction::query()->where('company_id', $request->header('company'))->findOrFail($transaction);

        return response()->json(['data' => $this->matching->candidates($row)]);
    }

    public function update(MatchFioTransactionRequest $request, int $transaction): FioTransactionResource
    {
        $row = FioTransaction::query()->where('company_id', $request->header('company'))->findOrFail($transaction);

        return new FioTransactionResource($this->matching->review($row, $request->validated(), $request->user()->id));
    }
}
