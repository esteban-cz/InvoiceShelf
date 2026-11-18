<?php

namespace App\Domains\Accounts\Application;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanyInvitation;
use App\Domains\Accounts\Models\User;
use Illuminate\Support\Facades\DB;
use Silber\Bouncer\BouncerFacade;
use Silber\Bouncer\Database\Role;

class CompanyOwnership
{
    /** Assign one recorded owner; platform administrators retain their company access. */
    public function assign(Company $company, ?User $owner, ?int $acceptedInvitation = null): void
    {
        DB::transaction(function () use ($company, $owner, $acceptedInvitation): void {
            $locked = Company::query()->lockForUpdate()->findOrFail($company->id);
            $role = Role::query()->withoutGlobalScopes()->where('scope', $company->id)->where('name', 'owner')->firstOrFail();
            $manager = Role::query()->withoutGlobalScopes()->where('scope', $company->id)->where('name', 'preset:manager')->first();
            $formerOwners = $locked->users()->whereHas('roles', fn ($query) => $query->withoutGlobalScopes()->where('roles.id', $role->id))->get();
            foreach ($formerOwners as $member) {
                if ($member->isSuperAdmin() || $member->id === $owner?->id) {
                    continue;
                }
                BouncerFacade::scope()->onceTo((int) $company->id, fn () => BouncerFacade::sync($member)->roles($manager ? [$manager->name] : []));
            }
            if ($owner) {
                $owner->companies()->syncWithoutDetaching([$company->id]);
                BouncerFacade::scope()->onceTo((int) $company->id, fn () => BouncerFacade::sync($owner)->roles(['owner']));
            }
            $locked->update(['owner_id' => $owner?->id]);
            $company->owner_id = $owner?->id;
            $company->unsetRelation('owner');
            CompanyInvitation::query()->where('company_id', $company->id)->where('role_id', $role->id)->pending()
                ->when($acceptedInvitation !== null, fn ($query) => $query->whereKeyNot($acceptedInvitation))
                ->update(['status' => CompanyInvitation::STATUS_EXPIRED]);
        });
        BouncerFacade::refresh();
    }
}
