<?php

use App\Platform\Modules\Runtime\ModuleCompatibility;
use App\Platform\Operations\Application\BuildVersionService;
use Illuminate\Support\Facades\File;

test('fork releases have a display label and valid source tag', function (string $fork, string $upstream, string $display, string $tag) {
    File::partialMock()->shouldReceive('get')->with(base_path('fork-version.md'))->andReturn($fork."\n");
    File::partialMock()->shouldReceive('get')->with(base_path('version.md'))->andReturn($upstream."\n");

    $version = app(BuildVersionService::class);

    expect($version->fork())->toBe($fork)
        ->and($version->upstream())->toBe($upstream)
        ->and($version->display())->toBe($display)
        ->and($version->tag())->toBe($tag)
        ->and(ModuleCompatibility::satisfies($version->upstream(), '>=3.0.0-alpha.4'))->toBeTrue();
})->with([
    'first fork release' => ['1.0.8', '3.0.0-alpha.15', '1.0.8-[3.0.0-alpha.15]', 'fork-v1.0.8-upstream-3.0.0-alpha.15'],
    'next fork release' => ['1.0.9', '3.0.0-alpha.15', '1.0.9-[3.0.0-alpha.15]', 'fork-v1.0.9-upstream-3.0.0-alpha.15'],
    'upstream upgrade' => ['1.1.0', '3.0.0-alpha.16', '1.1.0-[3.0.0-alpha.16]', 'fork-v1.1.0-upstream-3.0.0-alpha.16'],
]);

test('the public version endpoint exposes the fork label and upstream compatibility version', function () {
    $version = app(BuildVersionService::class);

    $this->getJson('/api/v1/app/version')->assertOk()->assertJson([
        'version' => $version->upstream(),
        'fork_version' => $version->fork(),
        'display_version' => $version->display(),
    ]);

    $this->getJson('/api/v1/app/client-manifest')->assertOk()->assertJsonPath('version', $version->upstream());
});
