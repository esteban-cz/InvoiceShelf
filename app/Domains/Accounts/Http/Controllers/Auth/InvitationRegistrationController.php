<?php

namespace App\Domains\Accounts\Http\Controllers\Auth;

use App\Domains\Accounts\Application\InvitationService;
use App\Domains\Accounts\Application\RoleTitles;
use App\Domains\Accounts\Http\Requests\InvitationRegistrationRequest;
use App\Domains\Accounts\Models\CompanyInvitation;
use App\Domains\Accounts\Models\User;
use App\Platform\Http\Controller;
use App\Support\SpaTranslations;
use Illuminate\Http\JsonResponse;

class InvitationRegistrationController extends Controller
{
    public function __construct(
        private readonly InvitationService $invitationService,
    ) {}

    /**
     * Get invitation details by token (public endpoint for registration page).
     */
    public function details(string $token): JsonResponse
    {
        $invitation = CompanyInvitation::where('token', $token)
            ->pending()
            ->with(['company', 'role'])
            ->first();

        if (! $invitation) {
            return response()->json([
                'error' => SpaTranslations::get(app()->getLocale(), 'invitation_errors.invalid'),
            ], 404);
        }

        return response()->json([
            'email' => $invitation->email,
            'company_name' => $invitation->company->name,
            'role_name' => app(RoleTitles::class)->display($invitation->role->name, $invitation->role->title),
        ]);
    }

    /**
     * Register a new user and auto-accept the invitation.
     */
    public function register(InvitationRegistrationRequest $request): JsonResponse
    {
        $user = $this->invitationService->register($request->validated());

        return response()->json([
            'type' => 'Bearer',
            'token' => $user->createToken('web')->plainTextToken,
        ]);
    }
}
