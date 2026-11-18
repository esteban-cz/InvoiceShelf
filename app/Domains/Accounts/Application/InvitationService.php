<?php

namespace App\Domains\Accounts\Application;

use App\Domains\Accounts\Contracts\CompanyInvitationSender;
use App\Domains\Accounts\Events\InvitationAnswered;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanyInvitation;
use App\Domains\Accounts\Models\User;
use App\Support\SpaTranslations;
use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Silber\Bouncer\BouncerFacade;
use Silber\Bouncer\Database\Role;

class InvitationService
{
    public function __construct(
        private readonly CompanyInvitationSender $companyInvitationSender,
        private readonly CompanyOwnership $ownership,
    ) {}

    /**
     * Invite a user to a company by email with a specific role.
     */
    public function invite(Company $company, string $email, int $roleId, User $invitedBy): CompanyInvitation
    {
        Role::query()->withoutGlobalScopes()->where('scope', $company->id)->findOrFail($roleId);

        // Check for existing pending invitation
        $existing = CompanyInvitation::where('company_id', $company->id)
            ->where('email', $email)
            ->pending()
            ->first();

        if ($existing) {
            throw ValidationException::withMessages([
                'email' => [SpaTranslations::get(app()->getLocale(), 'invitation_errors.pending_email')],
            ]);
        }

        // Check if user is already a member
        $existingUser = User::where('email', $email)->first();
        if ($existingUser && $existingUser->hasCompany($company->id)) {
            throw ValidationException::withMessages([
                'email' => [SpaTranslations::get(app()->getLocale(), 'invitation_errors.already_member')],
            ]);
        }

        $invitation = CompanyInvitation::create([
            'company_id' => $company->id,
            'user_id' => $existingUser?->id,
            'email' => $email,
            'role_id' => $roleId,
            'token' => Str::random(64),
            'status' => CompanyInvitation::STATUS_PENDING,
            'invited_by' => $invitedBy->id,
            'expires_at' => Carbon::now()->addDays(7),
        ]);

        $invitation->load(['company', 'role', 'invitedBy']);

        try {
            $this->companyInvitationSender->send($invitation);
        } catch (\Exception $e) {
            \Log::warning('Failed to send invitation email to '.$email.': '.$e->getMessage());
        }

        return $invitation;
    }

    /**
     * Accept a pending invitation and add the user to the company.
     */
    public function accept(CompanyInvitation $invitation, User $user): void
    {
        DB::transaction(function () use ($invitation, $user): void {
            $locked = CompanyInvitation::query()->lockForUpdate()->findOrFail($invitation->id);
            $this->assertAddressedTo($locked, $user);
            $role = Role::query()->withoutGlobalScopes()->where('scope', $locked->company_id)->find($locked->role_id);
            if (! $locked->isPending() || ! $role) {
                throw ValidationException::withMessages([
                    'invitation' => [SpaTranslations::get(app()->getLocale(), 'invitation_errors.invalid')],
                ]);
            }
            if ($role->name === 'owner' && (! $locked->invitedBy || ! app(CompanyAccess::class)->canManage($locked->invitedBy, $locked->company))) {
                throw new AuthorizationException;
            }

            $user->companies()->syncWithoutDetaching([$locked->company_id]);
            BouncerFacade::scope()->onceTo((int) $locked->company_id, fn () => BouncerFacade::sync($user)->roles([$role->name]));
            if ($role->name === 'owner') {
                $this->ownership->assign($locked->company, $user, (int) $locked->id);
            }
            $locked->update(['status' => CompanyInvitation::STATUS_ACCEPTED, 'user_id' => $user->id]);
            $invitation->setRawAttributes($locked->getAttributes(), true);
        });
        BouncerFacade::refresh();
        InvitationAnswered::dispatch((int) $invitation->id, (int) $invitation->company_id, CompanyInvitation::STATUS_ACCEPTED);
    }

    /** Register and accept atomically, so an invalid invitation never leaves a partial account. */
    public function register(array $attributes): User
    {
        return DB::transaction(function () use ($attributes): User {
            $invitation = CompanyInvitation::query()->where('token', $attributes['invitation_token'])->pending()->first();
            if (! $invitation) {
                throw ValidationException::withMessages(['invitation_token' => [SpaTranslations::get(app()->getLocale(), 'invitation_errors.invalid')]]);
            }
            if (strcasecmp($invitation->email, $attributes['email']) !== 0) {
                throw ValidationException::withMessages(['email' => [SpaTranslations::get(app()->getLocale(), 'invitation_errors.wrong_recipient')]]);
            }
            $user = User::query()->create(Arr::only($attributes, ['name', 'email', 'password']));
            $user->setSettings(['language' => 'default']);
            $this->accept($invitation, $user);

            return $user;
        });
    }

    /**
     * Decline a pending invitation.
     */
    public function decline(CompanyInvitation $invitation, User $user): void
    {
        $this->assertAddressedTo($invitation, $user);

        if (! $invitation->isPending()) {
            throw ValidationException::withMessages([
                'invitation' => [SpaTranslations::get(app()->getLocale(), 'invitation_errors.invalid')],
            ]);
        }

        $invitation->update([
            'status' => CompanyInvitation::STATUS_DECLINED,
            'user_id' => $user->id,
        ]);

        InvitationAnswered::dispatch((int) $invitation->id, (int) $invitation->company_id, CompanyInvitation::STATUS_DECLINED);
    }

    /**
     * Get all pending invitations for a user (by user_id or email).
     */
    public function getPendingForUser(User $user): Collection
    {
        return CompanyInvitation::forUser($user)
            ->pending()
            ->with(['company', 'role', 'invitedBy'])
            ->get();
    }

    /**
     * An invitation is answered by the person it was sent to, and nobody else:
     * holding its token is not enough.
     */
    private function assertAddressedTo(CompanyInvitation $invitation, User $user): void
    {
        if (strcasecmp((string) $user->email, (string) $invitation->email) !== 0) {
            throw new AuthorizationException(SpaTranslations::get(app()->getLocale(), 'invitation_errors.wrong_recipient'));
        }
    }
}
