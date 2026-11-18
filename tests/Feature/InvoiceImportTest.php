<?php

use App\Domains\Accounts\Application\CompanyService;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Sales\Application\Import\InvoicePdfExtractor;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Taxation\Models\TaxType;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Silber\Bouncer\BouncerFacade;

beforeEach(function () {
    Cache::flush();
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $this->user = User::findOrFail(1);
    $this->company_id = $this->user->companies()->first()->id;
    $this->withHeaders(['company' => $this->company_id]);
    Sanctum::actingAs($this->user);
    $this->mock(InvoicePdfExtractor::class)->shouldReceive('extract')->zeroOrMoreTimes()->andReturn([
        'text' => file_get_contents(base_path('tests/Fixtures/invoice-import/legacy.txt')),
        'pages' => 1, 'ocr' => false, 'warnings' => [],
    ]);
});

function importPdfFile(): UploadedFile
{
    return UploadedFile::fake()->createWithContent('old-invoice.pdf', "%PDF-1.4\n% Test upload; extraction is mocked.\n%%EOF");
}

test('opening the app does not consume the PDF import allowance', function () {
    foreach (range(1, 12) as $_) {
        $this->getJson('/api/v1/invoices')->assertOk();
    }
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk();
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk();
});

test('only PDF previews count toward the import limit and it recovers after a minute', function () {
    $this->user->setSettings(['language' => 'cs']);
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk();
    }
    $response = $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertStatus(429);
    expect((int) $response->headers->get('Retry-After'))->toBeGreaterThan(0);
    $response->assertJsonPath('message', __('invoice_import_rate_limited', ['seconds' => (int) $response->headers->get('Retry-After')], 'cs'));
    $this->getJson('/api/v1/invoices')->assertOk();
    $this->travel(61)->seconds();
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk();
});

test('PDF preview allowances belong to each user and company', function () {
    foreach (range(1, 6) as $_) {
        $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk();
    }
    $company = $this->user->companies()->create(['name' => 'Another company', 'owner_id' => $this->user->id, 'slug' => 'another-company']);
    app(CompanyService::class)->setupDefaults($company);
    BouncerFacade::scope()->onceTo($company->id, fn () => $this->user->assign('owner'));
    BouncerFacade::refresh();
    $this->withHeaders(['company' => $company->id]);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk();
    $this->withHeaders(['company' => $this->company_id]);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertStatus(429);
    $another_user = User::factory()->create(['role' => 'super admin']);
    $another_user->companies()->attach($this->company_id);
    BouncerFacade::scope()->onceTo($this->company_id, fn () => $another_user->assign('owner'));
    BouncerFacade::refresh();
    Sanctum::actingAs($another_user);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk();
});

test('upload previews an old invoice without writing invoices payments customers or settings', function () {
    $before = [Invoice::count(), Payment::count(), Customer::count(), CompanySetting::count()];
    $response = $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk()
        ->assertJsonPath('meta.preview_only', true)->assertJsonPath('data.invoice_number', '202601')
        ->assertJsonPath('data.invoice_date', '2026-01-06')->assertJsonPath('data.due_date', '2026-01-20')
        ->assertJsonPath('data.currency.code', 'CZK')->assertJsonPath('data.total', 700000)
        ->assertJsonPath('data.payment_qr.iban', 'CZ8120100000002102589740')
        ->assertJsonPath('data.payment_qr.save_account', false)->assertJsonPath('data.matched_customer_id', null);
    expect($response->headers->get('Cache-Control'))->toContain('no-store');
    expect([Invoice::count(), Payment::count(), Customer::count(), CompanySetting::count()])->toBe($before);
});

test('customer matching stays in the company and duplicate numbers remain editable suggestions', function () {
    $currency = Currency::query()->where('code', 'CZK')->value('id');
    Customer::factory()->create(['company_id' => Company::factory()->create()->id, 'registration_number' => '87654321', 'currency_id' => $currency]);
    $customer = Customer::factory()->create(['registration_number' => '87654321', 'currency_id' => $currency]);
    Invoice::factory()->create(['invoice_number' => '202601', 'customer_id' => $customer->id]);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk()
        ->assertJsonPath('data.matched_customer_id', $customer->id)->assertJsonPath('data.duplicate_number', true)
        ->assertJsonPath('data.invoice_number', '202601')->assertJsonFragment(['duplicate_number']);
});

test('ambiguous and incompatible customer matches are not selected', function () {
    Customer::factory()->create(['registration_number' => '87654321', 'currency_id' => 1]);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk()
        ->assertJsonPath('data.matched_customer_id', null)->assertJsonFragment(['customer_currency_mismatch']);
    Customer::factory()->create(['registration_number' => '87654321', 'currency_id' => 1]);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk()
        ->assertJsonPath('data.matched_customer_id', null)->assertJsonFragment(['customer_ambiguous']);
});

test('preview validates PDF uploads and size before extraction', function () {
    $this->postJson('/api/v1/invoices/import/preview', [])->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->postJson('/api/v1/invoices/import/preview', ['file' => UploadedFile::fake()->create('bad.txt', 1, 'text/plain')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
    $this->postJson('/api/v1/invoices/import/preview', ['file' => UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf')])
        ->assertUnprocessable()->assertJsonValidationErrors('file');
});

test('preview requires invoice creation permission', function () {
    $member = User::factory()->create();
    $this->user->companies()->first()->users()->attach($member);
    Sanctum::actingAs($member);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertForbidden();
});

test('VAT suggestions only use a unique existing sales tax from the active company', function () {
    CompanySetting::setSettings(['tax_per_item' => 'YES'], $this->company_id);
    $text = "Invoice No: OLD-001\nInvoice date: 2025-09-15\nDue date: 2025-09-29\nDescription       Qty       Unit price       Total       VAT\nWork                 2           50.00       100.00       21%\nGrand total: 121.00 EUR";
    $extractor = Mockery::mock(InvoicePdfExtractor::class);
    $extractor->shouldReceive('extract')->andReturn(['text' => $text, 'pages' => 1, 'ocr' => false, 'warnings' => []]);
    $this->instance(InvoicePdfExtractor::class, $extractor);
    $tax = TaxType::factory()->create(['company_id' => $this->company_id, 'name' => 'VAT 21', 'percent' => 21, 'calculation_type' => 'percentage', 'transaction_type' => 'sales', 'compound_tax' => false, 'type' => 'GENERAL']);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk()
        ->assertJsonPath('data.items.0.taxes.0.tax_type_id', $tax->id)->assertJsonPath('data.tax_mode', 'YES');
    $tax->update(['company_id' => Company::factory()->create()->id]);
    $this->postJson('/api/v1/invoices/import/preview', ['file' => importPdfFile()])->assertOk()
        ->assertJsonPath('data.items.0.taxes', [])->assertJsonFragment(['taxes_need_review']);
});
