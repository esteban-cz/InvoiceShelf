<?php

use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $this->user = User::findOrFail(1);
    $this->withHeaders(['company' => $this->user->companies()->first()->id]);
    Sanctum::actingAs($this->user);
    Cache::flush();
    Http::preventStrayRequests();
});

function aresFixture(): array
{
    return ['ico' => '00006947', 'obchodniJmeno' => 'Ministerstvo financí', 'dic' => 'CZ00006947',
        'sidlo' => ['nazevUlice' => 'Letenská', 'cisloDomovni' => 525, 'cisloOrientacni' => 15,
            'nazevObce' => 'Praha', 'psc' => 11800, 'kodStatu' => 'CZ']];
}

test('ARES returns a preview with a Czech address and caches the official response', function () {
    Http::fake(['ares.gov.cz/*' => Http::response(aresFixture())]);
    $count = Customer::count();
    foreach (range(1, 2) as $_) {
        $this->getJson('/api/v1/customers/ares/00006947')->assertOk()
            ->assertJsonPath('data.registration_number', '00006947')->assertJsonPath('data.name', 'Ministerstvo financí')
            ->assertJsonPath('data.billing.address_street_1', 'Letenská 525/15')
            ->assertJsonPath('data.billing.zip', '118 00')
            ->assertJsonPath('data.billing.country_id', Country::where('code', 'CZ')->value('id'));
    }
    Http::assertSentCount(1);
    expect(Customer::count())->toBe($count);
});

test('ARES does not invent a tax ID and supports villages without streets', function () {
    $data = aresFixture();
    unset($data['dic'], $data['sidlo']['nazevUlice'], $data['sidlo']['cisloOrientacni']);
    $data['sidlo']['nazevCastiObce'] = 'Lhota';
    $data['sidlo']['typCisloDomovni'] = 2;
    Http::fake(['ares.gov.cz/*' => Http::response($data)]);
    $this->getJson('/api/v1/customers/ares/00006947')->assertOk()->assertJsonPath('data.tax_id', null)
        ->assertJsonPath('data.billing.address_street_1', 'Lhota č. ev. 525');
});

test('ARES validates IČO before making any request', function () {
    $this->getJson('/api/v1/customers/ares/123')->assertUnprocessable()->assertJsonValidationErrors('ico');
    Http::assertNothingSent();
});

test('ARES handles missing and unavailable records without exposing an upstream response', function (int $status, int $expected) {
    Http::fake(['ares.gov.cz/*' => Http::response(['private_detail' => 'upstream failure'], $status)]);
    $this->getJson('/api/v1/customers/ares/00006947')->assertStatus($expected)->assertDontSee('private_detail');
})->with([[404, 422], [429, 503], [500, 503]]);

test('ARES rejects malformed and mismatched responses', function () {
    Http::fake(['ares.gov.cz/*' => Http::response(['ico' => '12345678', 'obchodniJmeno' => 'Wrong company'])]);
    $this->getJson('/api/v1/customers/ares/00006947')->assertStatus(503);
});

test('customer registration number persists separately from tax ID', function () {
    $this->postJson('/api/v1/customers', ['name' => 'Czech customer', 'registration_number' => '00006947',
        'tax_id' => 'CZ00006947', 'currency_id' => 1, 'enable_portal' => false])->assertSuccessful()
        ->assertJsonPath('data.registration_number', '00006947')->assertJsonPath('data.tax_id', 'CZ00006947');
    $this->assertDatabaseHas('customers', ['registration_number' => '00006947', 'tax_id' => 'CZ00006947']);
});

test('ARES lookup requires access to customers', function () {
    $member = User::factory()->create();
    $this->user->companies()->first()->users()->attach($member);
    Sanctum::actingAs($member);
    $this->getJson('/api/v1/customers/ares/00006947')->assertForbidden();
    Http::assertNothingSent();
});

test('ARES handles transport failures without an internal error', function () {
    Http::fake(fn () => throw new ConnectionException('upstream connection failure'));
    $this->getJson('/api/v1/customers/ares/00006947')->assertStatus(503);
});
