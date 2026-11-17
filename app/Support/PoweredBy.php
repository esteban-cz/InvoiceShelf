<?php

namespace App\Support;

use App\Platform\Operations\Application\BuildVersionService;

/**
 * The "Powered by" line and the link to the running version's source code.
 *
 * The Blade shell, the thin-client manifest and the mail partial all read the
 * line from here, so a host that renames or hides it does so everywhere.
 */
final class PoweredBy
{
    /**
     * The line's name and address, or null on a white-label install.
     *
     * @return array{name: string, url: string}|null
     */
    public static function clientState(): ?array
    {
        if (! config('invoiceshelf.powered_by.enabled')) {
            return null;
        }

        return [
            'name' => (string) config('invoiceshelf.powered_by.name'),
            'url' => (string) config('invoiceshelf.powered_by.url'),
        ];
    }

    /**
     * Where the source code of the version that is running can be had.
     */
    public static function sourceUrl(): string
    {
        $version = app(BuildVersionService::class);

        return str_replace(
            ['{version}', '{fork_version}', '{fork_tag}'],
            [rawurlencode($version->upstream()), rawurlencode($version->fork()), rawurlencode($version->tag())],
            (string) config('invoiceshelf.source_url'),
        );
    }
}
