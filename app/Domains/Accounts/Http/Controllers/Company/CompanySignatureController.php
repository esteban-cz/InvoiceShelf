<?php

namespace App\Domains\Accounts\Http\Controllers\Company;

use App\Domains\Accounts\Application\CompanySignatureService;
use App\Domains\Accounts\Http\Requests\CompanySignatureRequest;
use App\Domains\Accounts\Models\Company;
use App\Platform\Http\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CompanySignatureController extends Controller
{
    public function __construct(private readonly CompanySignatureService $signatures) {}

    public function show(Request $request): JsonResponse
    {
        $company = Company::query()->findOrFail($request->header('company'));
        $this->authorize('manage company', $company);

        return response()->json(['data' => $this->signatures->settings($company)])->header('Cache-Control', 'no-store');
    }

    public function update(CompanySignatureRequest $request): JsonResponse
    {
        $company = Company::query()->findOrFail($request->header('company'));
        $this->authorize('manage company', $company);

        return response()->json(['data' => $this->signatures->save(
            $company,
            $request->validated('issuer_name'),
            $request->file('signature'),
            $request->boolean('remove_signature'),
        )]);
    }
}
