<?php

namespace App\Domains\Sales\Http\Controllers\Company;

use App\Domains\Sales\Application\Import\InvoiceImportService;
use App\Domains\Sales\Http\Requests\PreviewInvoiceImportRequest;
use App\Domains\Sales\Http\Resources\InvoiceImportResource;
use App\Platform\Http\Controller;

class InvoiceImportController extends Controller
{
    public function __construct(private readonly InvoiceImportService $imports) {}

    public function __invoke(PreviewInvoiceImportRequest $request): InvoiceImportResource
    {
        return (new InvoiceImportResource($this->imports->preview($request->file('file'), (int) $request->header('company'))))
            ->additional(['meta' => ['preview_only' => true]]);
    }
}
