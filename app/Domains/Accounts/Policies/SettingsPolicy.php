<?php

namespace App\Domains\Accounts\Policies;

use App\Domains\Accounts\Application\CompanyAccess;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/** Company owners, the full-access Owner role and member platform admins manage settings. */
class SettingsPolicy
{
    use HandlesAuthorization;

    /**
     * Editing the company profile, its settings, or its mail configuration.
     *
     * Declared without a return type, as found.
     */
    public function manageCompany(User $user, Company $company)
    {
        return app(CompanyAccess::class)->canManage($user, $company);
    }
}
