<?php

use App\Domains\Accounts\Application\CompanyAccess;
use App\Domains\Accounts\Application\CompanyService;
use App\Domains\Accounts\Application\RoleTitles;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanyInvitation;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Mail;
use Laravel\Sanctum\Sanctum;
use Silber\Bouncer\BouncerFacade;
use Silber\Bouncer\Database\Role;

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    Mail::fake();
    $this->admin = User::findOrFail(1);
    $this->company = $this->admin->companies()->firstOrFail();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->admin, ['*']);
});

test('an administrator creates an unowned company while retaining management and administration', function () {
    $response = $this->postJson('/api/v1/companies', ['name' => 'Bez vlastníka', 'currency' => 1, 'address' => ['country_id' => 1]])
        ->assertCreated()->assertJsonPath('data.owner_id', null)->assertJsonPath('data.user_role', 'Administrátor');
    $company = Company::findOrFail($response->json('data.id'));
    $this->withHeaders(['company' => $company->id]);
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_owner', false)->assertJsonPath('data.can_manage_company', true);
    $this->postJson('/api/v1/invoices/bank-accounts', ['iban' => '123457/0710'])->assertSuccessful();
    $this->getJson('/api/v1/super-admin/companies')->assertOk();
    expect(app(CompanyAccess::class)->canManage($this->admin, $company))->toBeTrue();
});

test('an administrator may explicitly own a new company', function () {
    $this->postJson('/api/v1/companies', ['name' => 'Vlastní společnost', 'currency' => 1, 'address' => ['country_id' => 1], 'owner_id' => $this->admin->id])
        ->assertCreated()->assertJsonPath('data.owner_id', $this->admin->id);
});

test('accepting an Owner invitation transfers company ownership without platform privileges', function () {
    $this->company->update(['owner_id' => null]);
    $owner = User::factory()->create(['role' => 'general']);
    $role = Role::withoutGlobalScopes()->where('scope', $this->company->id)->where('name', 'owner')->firstOrFail();
    $this->postJson('/api/v1/company-invitations', ['email' => $owner->email, 'role_id' => $role->id])->assertOk();
    $invitation = CompanyInvitation::where('email', $owner->email)->firstOrFail();
    Sanctum::actingAs($owner, ['*']);
    $this->postJson('/api/v1/invitations/'.$invitation->token.'/accept')->assertOk();
    expect($this->company->fresh()->owner_id)->toBe($owner->id)->and($owner->isSuperAdmin())->toBeFalse();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_owner', true)->assertJsonPath('data.can_manage_company', true);
    $this->postJson('/api/v1/invoices/bank-accounts', ['iban' => '123457/0710'])->assertSuccessful();
    $this->getJson('/api/v1/super-admin/companies')->assertForbidden();
    $other = app(CompanyService::class)->createFor($this->admin, ['name' => 'Jiná společnost'], 1);
    $this->withHeaders(['company' => $other->id]);
    CompanySetting::setSettings(['invoice_due_date_days' => '99'], $other->id);
    $this->getJson('/api/v1/company/settings?settings[]=invoice_due_date_days')->assertOk()->assertJsonMissing(['invoice_due_date_days' => '99']);
    expect(app(CompanyAccess::class)->canManage($owner, $other))->toBeFalse();
    Sanctum::actingAs($this->admin, ['*']);
    $this->withHeaders(['company' => $this->company->id]);
    $this->postJson('/api/v1/company/settings', ['settings' => ['invoice_due_date_days' => '21']])->assertOk();
    $this->getJson('/api/v1/super-admin/companies')->assertOk();
});

test('legacy members already assigned Owner can manage bank settings', function () {
    $owner = User::factory()->create(['role' => 'general']);
    $owner->companies()->attach($this->company->id);
    BouncerFacade::scope()->onceTo($this->company->id, fn () => BouncerFacade::assign('owner')->to($owner));
    Sanctum::actingAs($owner, ['*']);
    $this->postJson('/api/v1/invoices/bank-accounts', ['iban' => '123457/0710'])->assertSuccessful();
    $this->getJson('/api/v1/me')->assertOk()->assertJsonPath('data.is_owner', false)->assertJsonPath('data.can_manage_company', true);
    $this->putJson('/api/v1/members/'.$this->admin->id, [])->assertForbidden();
    $this->postJson('/api/v1/members/delete', ['users' => [$this->admin->id]])->assertOk();
    expect(User::find($this->admin->id))->not->toBeNull();
});

test('admin ownership edits attach the new owner and remove former ordinary owner management', function () {
    $oldOwner = User::factory()->create(['role' => 'general']);
    $newOwner = User::factory()->create(['role' => 'general']);
    $company = app(CompanyService::class)->createFor($oldOwner, ['name' => 'Převáděná společnost'], 1);
    $this->putJson('/api/v1/super-admin/companies/'.$company->id, ['name' => $company->name, 'owner_id' => $newOwner->id])->assertOk();
    expect($company->fresh()->owner_id)->toBe($newOwner->id)
        ->and($newOwner->hasCompany($company->id))->toBeTrue()
        ->and(app(CompanyAccess::class)->canManage($newOwner, $company->fresh()))->toBeTrue()
        ->and(app(CompanyAccess::class)->canManage($oldOwner, $company->fresh()))->toBeFalse();
    $this->putJson('/api/v1/super-admin/companies/'.$company->id, ['name' => $company->name, 'owner_id' => null])->assertOk();
    expect($company->fresh()->owner_id)->toBeNull();
});

test('stock role labels follow locale while custom role titles are preserved', function () {
    $titles = app(RoleTitles::class);
    expect($titles->display('owner', 'Owner', 'cs'))->toBe('Majitel')
        ->and($titles->display('preset:manager', 'Manager', 'cs'))->toBe('Správce')
        ->and($titles->display('preset:read-only', 'Read only', 'cs'))->toBe('Pouze pro čtení')
        ->and($titles->display('preset:manager', 'Správce', 'en'))->toBe('Manager')
        ->and($titles->display('preset:manager', 'Účetní', 'en'))->toBe('Účetní')
        ->and($titles->display('custom', 'Manager', 'cs'))->toBe('Manager');
});

test('new company defaults use the requested Czech document settings', function () {
    $company = app(CompanyService::class)->createFor($this->admin, ['name' => 'Výchozí nastavení'], 1);
    foreach (['invoice' => 'INV', 'estimate' => 'EST', 'payment' => 'PAY'] as $kind => $prefix) {
        expect(CompanySetting::getSetting($kind.'_number_format', $company->id))->toBe('{{SERIES:'.$prefix.'}}{{DELIMITER:-}}{{DATE_FORMAT:Y}}{{SEQUENCE:2}}');
        expect(CompanySetting::getSetting($kind.'_company_address_format', $company->id))->toBe("{COMPANY_NAME}\n{COMPANY_ADDRESS_STREET_1}, {COMPANY_ZIP_CODE}\n{COMPANY_ADDRESS_STREET_2}\n{COMPANY_STATE}\n{COMPANY_CITY}, {COMPANY_COUNTRY}\n{COMPANY_PHONE}");
    }
    expect(CompanySetting::getSetting('language', $company->id))->toBe('cs')
        ->and(CompanySetting::getSetting('invoice_due_date_days', $company->id))->toBe('14')
        ->and(CompanySetting::getSetting('document_sequence_reset_yearly', $company->id))->toBe('YES')
        ->and(CompanySetting::getSetting('invoice_mail_body', $company->id))->toBe('<p>Dobrý den,<br>zasílám Vám fakturu za dodané služby. Podrobnosti a fakturu ke stažení najdete pod tlačítkem níže.</p><p>Děkuji za spolupráci.<br>Štěpán Tomečka</p>');
});
