<?php

use App\Domains\Accounts\Application\CompanyService;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Sales\Application\SerialNumberService;
use App\Domains\Sales\Models\Estimate;
use App\Domains\Sales\Models\Invoice;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $this->admin = User::findOrFail(1);
    $this->company = app(CompanyService::class)->createFor($this->admin, ['name' => 'Roční číslování'], 1);
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->admin, ['*']);
});

test('invoice estimate and payment sequences restart each year using their issue dates', function (string $kind, string $model, string $prefix) {
    $model::factory()->create(['company_id' => $this->company->id, $kind.'_date' => '2026-12-31', $kind.'_number' => $prefix.'-202605', 'sequence_number' => 5, 'customer_sequence_number' => 5]);
    $this->getJson('/api/v1/next-number?key='.$kind.'&date=2026-12-31')->assertOk()->assertJsonPath('nextNumber', $prefix.'-202606');
    $this->getJson('/api/v1/next-number?key='.$kind.'&date=2027-01-01')->assertOk()->assertJsonPath('nextNumber', $prefix.'-202701');
    $model::factory()->create(['company_id' => $this->company->id, $kind.'_date' => '2027-01-01', $kind.'_number' => $prefix.'-202701', 'sequence_number' => 1, 'customer_sequence_number' => 1]);
    $this->getJson('/api/v1/next-number?key='.$kind.'&date=2027-01-02')->assertOk()->assertJsonPath('nextNumber', $prefix.'-202702');
    $serial = (new SerialNumberService)->setModel(new $model([$kind.'_date' => '2026-11-01']))->setCompany($this->company->id)->setNextNumbers();
    expect((int) $serial->nextSequenceNumber)->toBe(6);
})->with([['invoice', Invoice::class, 'INV'], ['estimate', Estimate::class, 'EST'], ['payment', Payment::class, 'PAY']]);

test('existing company counters and yearless formats keep a continuous sequence', function () {
    Invoice::factory()->create(['company_id' => $this->company->id, 'invoice_date' => '2026-12-31', 'sequence_number' => 9]);
    CompanySetting::where('company_id', $this->company->id)->where('option', 'document_sequence_reset_yearly')->delete();
    $this->getJson('/api/v1/next-number?key=invoice&date=2027-01-01')->assertOk()->assertJsonPath('nextNumber', 'INV-202710');
    CompanySetting::setSettings(['document_sequence_reset_yearly' => 'YES', 'invoice_number_format' => '{{SERIES:INV}}{{DELIMITER:-}}{{SEQUENCE:2}}'], $this->company->id);
    $this->getJson('/api/v1/next-number?key=invoice&date=2027-01-01')->assertOk()->assertJsonPath('nextNumber', 'INV-10');
});

test('a sequence ignores other companies and credit notes and preserves existing model numbers', function () {
    $other = $this->admin->companies()->where('companies.id', '!=', $this->company->id)->firstOrFail();
    Invoice::factory()->create(['company_id' => $other->id, 'invoice_date' => '2026-01-01', 'sequence_number' => 200]);
    Invoice::factory()->create(['company_id' => $this->company->id, 'type' => Invoice::TYPE_CREDIT_NOTE, 'invoice_date' => '2026-01-01', 'sequence_number' => 30]);
    $invoice = Invoice::factory()->create(['company_id' => $this->company->id, 'invoice_date' => '2026-01-01', 'sequence_number' => 2]);
    $this->getJson('/api/v1/next-number?key=invoice&date=2026-01-01')->assertOk()->assertJsonPath('nextNumber', 'INV-202603');
    $this->getJson('/api/v1/next-number?key=invoice&date=2027-01-01&model_id='.$invoice->id)->assertOk()->assertJsonPath('nextNumber', 'INV-202602');
    $this->getJson('/api/v1/next-number?key=invoice&date=invalid')->assertUnprocessable()->assertJsonValidationErrors('date');
});
