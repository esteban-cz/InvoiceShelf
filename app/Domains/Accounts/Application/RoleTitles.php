<?php

namespace App\Domains\Accounts\Application;

use App\Domains\Accounts\Models\RolePreset;
use App\Support\SpaTranslations;

class RoleTitles
{
    private const DEFAULTS = ['owner' => ['Owner', 'Majitel'], 'manager' => ['Manager', 'Správce'], 'read-only' => ['Read only', 'Pouze pro čtení']];

    /** Translate shipped labels without renaming role identifiers or user-defined titles. */
    public function display(?string $name, ?string $title, ?string $locale = null): string
    {
        $key = RolePreset::keyFromRoleName($name);
        if ($key && isset(self::DEFAULTS[$key]) && ($title === null || in_array($title, self::DEFAULTS[$key], true))) {
            return SpaTranslations::get($locale ?? app()->getLocale(), 'role_labels.'.$key);
        }

        return $title ?? $name ?? '';
    }
}
