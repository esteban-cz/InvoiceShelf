<?php

use App\Domains\Accounts\Application\CompanyService;
use App\Domains\Accounts\Application\UserLocale;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Validator;
use Laravel\Sanctum\Sanctum;

test('public defaults match in the shell and client manifest', function () {
    expect(config('app.locale'))->toBe('cs');
    $this->getJson('/api/v1/app/client-manifest')->assertOk()->assertJsonPath('locale', 'cs');
    $this->view('app')->assertSee('window.app_locale = "cs"', false);
});

test('new companies default to Czech and explicit user languages still take precedence', function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $user = User::findOrFail(1);
    $company = app(CompanyService::class)->createFor($user, ['name' => 'Nová společnost'], 1);
    expect(CompanySetting::getSetting('language', $company->id))->toBe('cs')
        ->and(UserLocale::for($user, $company->id))->toBe('cs');
    $user->setSettings(['language' => 'en']);
    expect(UserLocale::for($user, $company->id))->toBe('en');
});

test('Czech validation and password messages retain useful details', function () {
    app()->setLocale('cs');
    $validator = Validator::make(['email' => 'invalid', 'password' => 'short'], ['email' => 'email', 'password' => 'min:8']);
    expect($validator->errors()->first('email'))->toBe('Pole e-mail musí obsahovat platnou e-mailovou adresu.')
        ->and($validator->errors()->first('password'))->toContain('8', 'heslo')
        ->and(__('passwords.sent'))->toBe('Odkaz pro obnovení hesla jsme vám poslali e-mailem.');
});

test('API validation follows the user or company language', function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $user = User::findOrFail(1);
    $company = $user->companies()->firstOrFail();
    CompanySetting::setSettings(['language' => 'cs'], $company->id);
    $this->withHeaders(['company' => $company->id]);
    Sanctum::actingAs($user);
    $this->postJson('/api/v1/customers', ['name' => 'Ukázka', 'email' => 'invalid', 'enable_portal' => false])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'Pole e-mail musí obsahovat platnou e-mailovou adresu.');
    $user->setSettings(['language' => 'en']);
    $this->postJson('/api/v1/customers', ['name' => 'Example', 'email' => 'invalid', 'enable_portal' => false])->assertUnprocessable()
        ->assertJsonPath('errors.email.0', 'The email must be a valid email address.');
});
