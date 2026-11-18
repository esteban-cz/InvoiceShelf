<?php

namespace App\Domains\Accounts\Application;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\User;

class CompanyAccess
{
    /** Full company management is separate from platform administration and legal ownership. */
    public function canManage(User $user, ?Company $company = null): bool
    {
        $company ??= Company::query()->find(request()->header('company'));
        if (! $company) {
            return false;
        }
        if ((int) $company->owner_id === (int) $user->id) {
            return true;
        }
        if (! $user->hasCompany((int) $company->id)) {
            return false;
        }

        return $user->isSuperAdmin() || $user->roles()->withoutGlobalScopes()->where('roles.scope', $company->id)->where('roles.name', 'owner')->exists();
    }
}
