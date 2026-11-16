<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\InvoicePaymentQr;
use App\Domains\Sales\Jobs\GenerateInvoicePdfJob;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceBankAccount;
use App\Domains\Sales\Models\InvoiceItem;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Bus;
use Laravel\Sanctum\Sanctum;

use function Pest\Laravel\get;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;
use function Pest\Laravel\putJson;

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $user = User::findOrFail(1);
    $this->company = $user->companies()->firstOrFail();
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($user, ['*']);
    Bus::fake([GenerateInvoicePdfJob::class]);
    $this->currency = Currency::query()->where('code', 'CZK')->firstOrFail();
    $this->customer = Customer::factory()->create(['currency_id' => $this->currency->id]);
    CompanySetting::setSettings(['currency' => $this->currency->id, 'language' => 'en'], $this->company->id);
});

function qrInvoiceInput(Customer $customer, array $overrides = []): array
{
    return Invoice::factory()->raw([
        'customer_id' => $customer->id,
        'currency_id' => $customer->currency_id,
        'discount' => 0,
        'discount_val' => 0,
        'exchange_rate' => 1,
        'taxes' => [],
        'items' => [InvoiceItem::factory()->raw(['quantity' => 1, 'price' => 3499000, 'discount' => 0, 'tax' => 0])],
        ...$overrides,
    ]);
}

test('an invoice can save and reuse a Czech bank account scoped to its company', function () {
    $payment = ['iban' => '123457/0710', 'variable_symbol' => '0202501', 'save_account' => true];
    $snapshot = ['iban' => 'CZ4907100000000000123457', 'variable_symbol' => '0202501'];

    $invoice = postJson('/api/v1/invoices', qrInvoiceInput($this->customer, ['payment_qr' => $payment]))
        ->assertOk()
        ->assertJsonPath('data.payment_qr', $snapshot)
        ->json('data');

    postJson('/api/v1/invoices', qrInvoiceInput($this->customer, ['payment_qr' => [
        ...$payment, 'iban' => 'cz49 0710 0000 0000 0012 3457',
    ]]))->assertOk();

    InvoiceBankAccount::create(['company_id' => Company::factory()->create()->id, 'iban' => 'CZ9106000000000000000123']);
    getJson('/api/v1/invoices/bank-accounts')->assertExactJson(['data' => [$snapshot['iban']]]);
    expect(InvoiceBankAccount::query()->where('company_id', $this->company->id)->count())->toBe(1);

    $existing = qrInvoiceInput($this->customer, ['invoice_number' => $invoice['invoice_number']]);
    putJson("/api/v1/invoices/{$invoice['id']}", $existing)
        ->assertOk()->assertJsonPath('data.payment_qr', $snapshot);

    putJson("/api/v1/invoices/{$invoice['id']}", [...$existing, 'payment_qr' => null])
        ->assertOk()->assertJsonPath('data.payment_qr', null);
});

test('invalid payment details fail validation', function (array $payment, string $field) {
    postJson('/api/v1/invoices', qrInvoiceInput($this->customer, ['payment_qr' => $payment]))
        ->assertUnprocessable()->assertJsonValidationErrors($field);
})->with([
    'IBAN checksum' => [['iban' => 'CZ4807100000000000123457'], 'payment_qr.iban'],
    'domestic checksum' => [['iban' => '123456/0710'], 'payment_qr.iban'],
    'non-numeric symbol' => [['iban' => '123457/0710', 'variable_symbol' => 'INV-1'], 'payment_qr.variable_symbol'],
    'numeric JSON drops leading zeroes' => [['iban' => '123457/0710', 'variable_symbol' => 123], 'payment_qr.variable_symbol'],
]);

test('QR is limited to Czech koruna invoices', function () {
    $customer = Customer::factory()->create(['currency_id' => Currency::where('code', 'EUR')->value('id')]);
    postJson('/api/v1/invoices', qrInvoiceInput($customer, ['payment_qr' => ['iban' => '123457/0710']]))
        ->assertUnprocessable()->assertJsonValidationErrors('payment_qr.iban');
});

test('every stock PDF includes the QR for the current outstanding balance', function (string $template) {
    $invoice = Invoice::factory()->hasItems(1)->create([
        'customer_id' => $this->customer->id,
        'currency_id' => $this->currency->id,
        'template_name' => $template,
        'total' => 3499000,
        'due_amount' => 2000050,
        'payment_qr' => ['iban' => 'CZ4907100000000000123457', 'variable_symbol' => '0202501'],
    ]);
    $qr = app(InvoicePaymentQr::class);
    expect($qr->payload($invoice))->toBe('SPD*1.0*ACC:CZ4907100000000000123457*AM:20000.50*CC:CZK*X-VS:0202501');

    get("/invoices/pdf/{$invoice->unique_hash}?preview=true")->assertOk()
        ->assertSee('data:image/svg+xml;base64,', false)
        ->assertSee('0202501');
    $pdf = get("/invoices/pdf/{$invoice->unique_hash}")->assertOk();
    expect($pdf->getContent())->toStartWith('%PDF-');

    $invoice->update(['due_amount' => 0]);
    expect($qr->payload($invoice))->toBeNull();
    get("/invoices/pdf/{$invoice->unique_hash}?preview=true")->assertOk()
        ->assertDontSee('data:image/svg+xml;base64,', false);
})->with(['invoice1', 'invoice2', 'invoice3']);

test('Czech account numbers can include a prefix and preserve leading zeroes', function () {
    $qr = app(InvoicePaymentQr::class);
    expect($qr->normalizeAccount('19-123457/0710'))->toBe('CZ3507100000190000123457')
        ->and($qr->normalizeAccount('000000/0710'))->toBeNull()
        ->and($qr->normalizeAccount('1-123457/0710'))->toBeNull();
});
