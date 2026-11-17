<?php

namespace App\Platform\Operations\Application;

use Illuminate\Support\Facades\File;

class BuildVersionService
{
    /**
     * The upstream version remains the compatibility version for modules,
     * mobile clients and the upstream release server.
     */
    public function upstream(): string
    {
        return trim(File::get(base_path('version.md')));
    }

    public function fork(): string
    {
        return trim(File::get(base_path('fork-version.md')));
    }

    public function display(): string
    {
        return $this->fork().'-['.$this->upstream().']';
    }

    /**
     * Square brackets cannot be used in Git refs. The fork prefix also keeps
     * these source snapshots outside the upstream release workflows.
     */
    public function tag(): string
    {
        return 'fork-v'.$this->fork().'-upstream-'.$this->upstream();
    }
}
