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

test('company settings can save a Czech account for the invoice picker and default', function () {
    $iban = 'CZ4907100000000000123457';
    $path = '/api/v1/invoices/bank-accounts';

    postJson($path, ['iban' => '123457/0710', 'company_id' => Company::factory()->create()->id])
        ->assertOk()->assertExactJson(['data' => $iban]);
    postJson($path, ['iban' => 'cz49 0710 0000 0000 0012 3457'])
        ->assertOk()->assertExactJson(['data' => $iban]);
    postJson($path, ['iban' => '123456/0710'])
        ->assertUnprocessable()->assertJsonValidationErrors('iban');

    getJson($path)->assertOk()->assertExactJson(['data' => [$iban]]);
    expect(InvoiceBankAccount::query()->sole()->company_id)->toBe($this->company->id);

    postJson('/api/v1/company/settings', ['settings' => ['invoice_default_receiving_iban' => $iban]])
        ->assertOk()->assertJsonPath('success', true);
});

test('a company member cannot add a receiving account through settings', function () {
    $member = User::factory()->create(['role' => 'user']);
    $member->companies()->attach($this->company->id);
    Sanctum::actingAs($member, ['*']);

    postJson('/api/v1/invoices/bank-accounts', ['iban' => '123457/0710'])->assertForbidden();
    expect(InvoiceBankAccount::query()->exists())->toBeFalse();
});

test('the receiving account default can be set or cleared without changing accounts on existing invoices', function () {
    $iban = 'CZ4907100000000000123457';
    $override = 'CZ9106000000000000000123';
    InvoiceBankAccount::create(['company_id' => $this->company->id, 'iban' => $iban]);

    postJson('/api/v1/company/settings', ['settings' => ['invoice_default_receiving_iban' => $iban]])
        ->assertOk()->assertJsonPath('success', true);
    getJson('/api/v1/company/settings?settings[]=invoice_default_receiving_iban')
        ->assertOk()->assertJsonPath('invoice_default_receiving_iban', $iban);

    $invoice = postJson('/api/v1/invoices', qrInvoiceInput($this->customer, [
        'payment_qr' => ['iban' => $override],
    ]))->assertOk()->assertJsonPath('data.payment_qr.iban', $override)->json('data.id');

    postJson('/api/v1/company/settings', ['settings' => ['invoice_default_receiving_iban' => 'none']])
        ->assertOk()->assertJsonPath('success', true);
    getJson('/api/v1/company/settings?settings[]=invoice_default_receiving_iban')
        ->assertOk()->assertJsonPath('invoice_default_receiving_iban', 'none');
    getJson("/api/v1/invoices/{$invoice}")->assertOk()->assertJsonPath('data.payment_qr.iban', $override);
});

test('the default receiving account must be saved by the active company', function () {
    $otherIban = 'CZ9106000000000000000123';
    InvoiceBankAccount::create(['company_id' => Company::factory()->create()->id, 'iban' => $otherIban]);

    foreach ([$otherIban, 'CZ4907100000000000123457'] as $iban) {
        postJson('/api/v1/company/settings', ['settings' => ['invoice_default_receiving_iban' => $iban]])
            ->assertUnprocessable()->assertJsonValidationErrors('settings.invoice_default_receiving_iban');
    }
});

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
    expect($qr->payload($invoice))->toBe('SPD*1.0*ACC:CZ4907100000000000123457*AM:34990.00*CC:CZK*X-VS:0202501');
    get("/invoices/pdf/{$invoice->unique_hash}?preview=true")->assertOk()
        ->assertSee('data:image/svg+xml;base64,', false)->assertSee(__('pdf_invoice_paid'));
})->with(['invoice1', 'invoice2', 'invoice3']);

test('Czech account numbers can include a prefix and preserve leading zeroes', function () {
    $qr = app(InvoicePaymentQr::class);
    expect($qr->normalizeAccount('19-123457/0710'))->toBe('CZ3507100000190000123457')
        ->and($qr->normalizeAccount('000000/0710'))->toBeNull()
        ->and($qr->normalizeAccount('1-123457/0710'))->toBeNull();
});

test('a fully credited invoice does not retain a QR requesting its original amount', function () {
    $invoice = Invoice::factory()->hasItems(1)->create([
        'customer_id' => $this->customer->id, 'currency_id' => $this->currency->id,
        'total' => 10000, 'due_amount' => 0,
        'payment_qr' => ['iban' => 'CZ4907100000000000123457', 'variable_symbol' => null],
    ]);
    Invoice::factory()->create([
        'customer_id' => $this->customer->id, 'currency_id' => $this->currency->id,
        'type' => Invoice::TYPE_CREDIT_NOTE, 'related_invoice_id' => $invoice->id,
        'total' => -10000, 'due_amount' => 0,
    ]);
    expect(app(InvoicePaymentQr::class)->payload($invoice))->toBeNull();
    get('/invoices/pdf/'.$invoice->unique_hash.'?preview')->assertOk()
        ->assertDontSee('data:image/svg+xml;base64,', false)->assertDontSee(__('pdf_invoice_paid'));
});

test('the Czech invoice PDF uses Czech payment labels', function () {
    CompanySetting::setSettings(['language' => 'cs'], $this->company->id);
    $invoice = Invoice::factory()->hasItems(1)->create([
        'customer_id' => $this->customer->id,
        'currency_id' => $this->currency->id,
        'payment_qr' => ['iban' => 'CZ4907100000000000123457', 'variable_symbol' => '202501'],
    ]);

    get("/invoices/pdf/{$invoice->unique_hash}?preview=true")->assertOk()
        ->assertSee('Datum vystavení')
        ->assertSee('Variabilní symbol')->assertSee('202501');
});

test('the default variable symbol follows the saved invoice number and preserves leading zeroes', function () {
    postJson('/api/v1/company/settings', ['settings' => ['invoice_variable_symbol_format' => 'invoice_number']])
        ->assertOk()->assertJsonPath('success', true);

    postJson('/api/v1/invoices', qrInvoiceInput($this->customer, [
        'invoice_number' => 'INV-002026001',
        'payment_qr' => ['iban' => '123457/0710'],
    ]))->assertOk()->assertJsonPath('data.payment_qr.variable_symbol', '002026001');
});

test('a sequence preset uses the assigned ordinary invoice sequence and issue year', function (string $format) {
    postJson('/api/v1/company/settings', ['settings' => ['invoice_variable_symbol_format' => $format]])
        ->assertOk();
    $sequence = getJson('/api/v1/next-number?key=invoice&include_sequence=1')
        ->assertOk()->json('nextSequenceNumber');

    $input = qrInvoiceInput($this->customer, [
        'invoice_number' => 'INV-01',
        'invoice_date' => '2025-09-15',
        'payment_qr' => ['iban' => '123457/0710'],
    ]);
    unset($input['sequence_number'], $input['customer_sequence_number']);

    $expected = $format === 'year_sequence'
        ? '2025'.str_pad((string) $sequence, 3, '0', STR_PAD_LEFT)
        : str_pad((string) $sequence, 6, '0', STR_PAD_LEFT);

    postJson('/api/v1/invoices', $input)->assertOk()
        ->assertJsonPath('data.sequence_number', $sequence)
        ->assertJsonPath('data.payment_qr.variable_symbol', $expected);
})->with(['year_sequence', 'sequence']);

test('explicit payment symbols override the default and remain as entered when the preset changes', function (?string $symbol) {
    CompanySetting::setSettings(['invoice_variable_symbol_format' => 'invoice_number'], $this->company->id);
    $input = qrInvoiceInput($this->customer, [
        'invoice_number' => 'INV-2026001',
        'payment_qr' => ['iban' => '123457/0710', 'variable_symbol' => $symbol],
    ]);
    $id = postJson('/api/v1/invoices', $input)
        ->assertOk()->assertJsonPath('data.payment_qr.variable_symbol', $symbol)->json('data.id');

    CompanySetting::setSettings(['invoice_variable_symbol_format' => 'year_sequence'], $this->company->id);
    unset($input['payment_qr']);
    putJson("/api/v1/invoices/{$id}", $input)
        ->assertOk()->assertJsonPath('data.payment_qr.variable_symbol', $symbol);
})->with([['202501'], [null]]);

test('unknown formats and oversized generated symbols are rejected', function () {
    postJson('/api/v1/company/settings', ['settings' => ['invoice_variable_symbol_format' => 'arbitrary']])
        ->assertUnprocessable()->assertJsonValidationErrors('settings.invoice_variable_symbol_format');

    CompanySetting::setSettings(['invoice_variable_symbol_format' => 'invoice_number'], $this->company->id);
    postJson('/api/v1/invoices', qrInvoiceInput($this->customer, [
        'invoice_number' => 'INV-12345678901',
        'payment_qr' => ['iban' => '123457/0710', 'save_account' => true],
    ]))->assertUnprocessable()->assertJsonValidationErrors('payment_qr.variable_symbol');

    $this->assertDatabaseMissing('invoices', ['invoice_number' => 'INV-12345678901']);
    $this->assertDatabaseMissing('invoice_bank_accounts', ['company_id' => $this->company->id]);
});
