<?php

use Illuminate\Filesystem\FilesystemAdapter;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    $this->database_connection = config('database.default');
    config(['media-library.disk_name' => 'health_uploads']);
    Storage::fake('health_uploads');
});

afterEach(function () {
    config(['database.default' => $this->database_connection]);
});

test('health is public and checks the database and configured upload disk without caching', function () {
    config(['filesystems.default' => 'unused_disk']);
    Storage::disk('health_uploads')->put('existing-upload.txt', 'keep this file');

    $this->get('/api/health')
        ->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertExactJson([
            'status' => 'healthy',
            'database' => 'connected',
            'uploads' => 'writable',
        ]);

    expect($this->getJson('/api/health')->assertOk()->headers->get('Cache-Control'))->toContain('no-store');
    expect(Storage::disk('health_uploads')->allFiles())->toBe(['existing-upload.txt']);
    Storage::disk('health_uploads')->assertExists('existing-upload.txt');
});

test('an unavailable database returns 503 while still checking uploads', function (bool $configured) {
    config(['database.default' => 'unavailable_health_database']);

    if ($configured) {
        config(['database.connections.unavailable_health_database' => [
            'driver' => 'sqlite',
            'database' => storage_path('framework/testing/health-missing-'.Str::uuid().'.sqlite'),
        ]]);
    }

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'unhealthy',
            'database' => 'unavailable',
            'uploads' => 'writable',
        ]);

    expect(Storage::disk('health_uploads')->allFiles())->toBe([]);
})->with([
    'missing connection configuration' => [false],
    'database connection fails' => [true],
]);

test('failed upload writes or cleanup return 503 without exposing exception details', function (string $operation, bool $throws) {
    $disk = Mockery::mock(FilesystemAdapter::class);
    $write = $disk->shouldReceive('put')->once()->with(Mockery::type('string'), 'health-check');
    $delete = $disk->shouldReceive('delete')->once()->with(Mockery::type('string'));

    $write->andReturn(true);
    $delete->andReturn(true);
    $failure = $operation === 'put' ? $write : $delete;

    if ($throws) {
        $failure->andThrow(new RuntimeException('Private storage credentials and paths'));
    } else {
        $failure->andReturn(false);
    }

    Storage::set('health_uploads', $disk);

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'unhealthy',
            'database' => 'connected',
            'uploads' => 'not writable',
        ]);
})->with([
    'write returns false' => ['put', false],
    'write throws and still cleans up' => ['put', true],
    'cleanup returns false' => ['delete', false],
    'cleanup throws' => ['delete', true],
]);

test('an unconfigured upload disk returns 503', function () {
    config(['media-library.disk_name' => 'unavailable_health_uploads']);

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'unhealthy',
            'database' => 'connected',
            'uploads' => 'not writable',
        ]);
});

test('health reports both failures when the database and upload disk are unavailable', function () {
    config([
        'database.default' => 'unavailable_health_database',
        'media-library.disk_name' => 'unavailable_health_uploads',
    ]);

    $this->getJson('/api/health')
        ->assertStatus(503)
        ->assertExactJson([
            'status' => 'unhealthy',
            'database' => 'unavailable',
            'uploads' => 'not writable',
        ]);
});
