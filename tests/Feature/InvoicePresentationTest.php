<?php

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Sales\Application\InvoicePaymentQr;
use App\Domains\Sales\Application\InvoiceService;
use App\Domains\Sales\Jobs\GenerateInvoicePdfJob;
use App\Domains\Sales\Mail\SendInvoiceMail;
use App\Domains\Sales\Models\Invoice;
use App\Domains\Sales\Models\InvoiceItem;
use App\Platform\Mail\Models\EmailLog;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $user = User::query()->findOrFail(1);
    $this->company = $user->companies()->firstOrFail();
    $this->withHeaders(['company' => $this->company->id, 'Accept' => 'application/json']);
    Sanctum::actingAs($user, ['*']);
    Bus::fake([GenerateInvoicePdfJob::class]);
    Storage::fake('local');
    config(['media-library.disk_name' => 'local']);
    $this->currency = Currency::query()->where('code', 'CZK')->firstOrFail();
    $this->customer = Customer::factory()->create(['currency_id' => $this->currency->id]);
    CompanySetting::setSettings(['language' => 'cs', 'currency' => $this->currency->id, 'invoice_email_attachment' => 'NO'], $this->company->id);
});

function presentationInvoiceInput(Customer $customer, array $overrides = []): array
{
    $input = Invoice::factory()->raw([
        'customer_id' => $customer->id, 'currency_id' => $customer->currency_id,
        'discount' => 0, 'discount_val' => 0, 'exchange_rate' => 1, 'taxes' => [],
        'items' => [InvoiceItem::factory()->raw(['quantity' => 1, 'price' => 3499000, 'discount' => 0, 'tax' => 0])],
        ...$overrides,
    ]);
    unset($input['unique_hash']);

    return $input;
}

test('a Czech invoice email and its preview use the company language independently of PDF attachments', function (bool $attach) {
    app()->setLocale('en');
    CompanySetting::setSettings(['invoice_email_attachment' => $attach ? 'YES' : 'NO', 'carbon_date_format' => 'j F Y'], $this->company->id);
    $invoice = Invoice::factory()->hasItems(1)->create(['company_id' => $this->company->id, 'customer_id' => $this->customer->id, 'currency_id' => $this->currency->id, 'due_date' => '2025-09-29']);
    $input = ['from' => 'billing@example.com', 'to' => 'client@example.com', 'subject' => 'Nová faktura', 'body' => '<p>Dobrý den, zasílám vám fakturu.</p>'];
    $data = app(InvoiceService::class)->sendInvoiceData($invoice, $input);
    expect(app()->getLocale())->toBe('en');

    $html = (new SendInvoiceMail($data))->render();
    $action = $attach ? 'Fakturu najdete v příloze' : 'Zobrazit fakturu';
    expect($html)->toContain($action, 'Datum splatnosti', 'tomecka.com', '29 září 2025', $invoice->invoice_number)
        ->not->toContain('View Invoice', '&lt;span', 'class="subcopy"');
    expect(app()->getLocale())->toBe('en');

    $this->get('/api/v1/invoices/'.$invoice->id.'/send/preview?'.http_build_query($input))
        ->assertOk()->assertSee($action);

    $log = EmailLog::query()->where('mailable_id', $invoice->id)->latest('id')->firstOrFail();
    $this->getJson('/customer/invoices/'.$log->token)->assertOk()->assertJsonPath('meta.language', 'cs');
})->with(['link only' => [false], 'PDF attached' => [true]]);

test('the company signature is snapshotted and can be disabled on an invoice', function () {
    $this->post('/api/v1/company/signature', [
        'issuer_name' => 'Štěpán Tomečka', 'signature' => UploadedFile::fake()->image('signature.png', 400, 120),
    ])->assertOk()->assertJsonPath('data.issuer_name', 'Štěpán Tomečka');

    $input = presentationInvoiceInput($this->customer, ['document_signature' => ['image' => 'untrusted'], 'issuer_name' => 'Untrusted issuer']);
    $id = $this->postJson('/api/v1/invoices', $input)->assertOk()->assertJsonPath('data.sign_document', true)->json('data.id');
    $invoice = Invoice::query()->findOrFail($id);
    $snapshot = $invoice->document_signature;
    expect($snapshot['image'])->toStartWith('data:image/png;base64,');
    expect($invoice->toArray())->not->toHaveKey('document_signature');

    $this->post('/api/v1/company/signature', ['issuer_name' => 'New issuer', 'remove_signature' => '1'])->assertOk();
    expect($invoice->fresh()->document_signature)->toBe($snapshot);

    $this->get('/invoices/pdf/'.$invoice->unique_hash.'?preview')->assertOk()
        ->assertSee('Vystavil:')->assertSee('Štěpán Tomečka')->assertSee($snapshot['image'], false);

    $this->putJson('/api/v1/invoices/'.$id, [...$input, 'invoice_number' => $invoice->invoice_number, 'sign_document' => false])
        ->assertOk()->assertJsonPath('data.sign_document', false);
    $this->get('/invoices/pdf/'.$invoice->unique_hash.'?preview')->assertOk()
        ->assertSee('Štěpán Tomečka')->assertDontSee($snapshot['image'], false);
});

test('signature settings refuse ordinary company members and invalid images', function () {
    $this->post('/api/v1/company/signature', [
        'issuer_name' => 'Issuer', 'signature' => UploadedFile::fake()->create('signature.svg', 5, 'image/svg+xml'),
    ])->assertUnprocessable()->assertInvalid('signature');

    $member = User::factory()->create(['role' => 'user']);
    $member->companies()->attach($this->company->id);
    Sanctum::actingAs($member, ['*']);
    $this->getJson('/api/v1/company/signature')->assertForbidden();
    $this->postJson('/api/v1/company/signature', ['issuer_name' => 'Imposter'])->assertForbidden();
});

test('saved bank details preserve Czech account numbers and snapshot the bank name', function () {
    $iban = 'CZ4907100000000000123457';
    $this->postJson('/api/v1/invoices/bank-accounts', ['iban' => '123457/0710', 'bank_name' => 'Testovní banka'])->assertOk();
    $this->getJson('/api/v1/invoices/bank-accounts?details=1')->assertOk()->assertExactJson(['data' => [[
        'iban' => $iban, 'account_number' => '123457/0710', 'bank_name' => 'Testovní banka',
    ]]]);
    $this->getJson('/api/v1/invoices/bank-accounts')->assertOk()->assertExactJson(['data' => [$iban]]);

    $id = $this->postJson('/api/v1/invoices', presentationInvoiceInput($this->customer, [
        'payment_qr' => ['iban' => $iban, 'variable_symbol' => '00202501'],
        'notes' => '<p>Platba za služby.</p>',
    ]))->assertOk()->assertJsonPath('data.payment_qr.bank_name', 'Testovní banka')->json('data.id');
    $this->postJson('/api/v1/invoices/bank-accounts', ['iban' => $iban, 'bank_name' => 'Different bank'])->assertOk();

    $invoice = Invoice::query()->findOrFail($id);
    $this->get('/invoices/pdf/'.$invoice->unique_hash.'?preview')->assertOk()
        ->assertSee('Testovní banka')->assertSee('123457/0710')->assertSee($iban)->assertSee('00202501');
    expect(app(InvoicePaymentQr::class)->domesticAccount('19-123457/0710'))->toBe('19-123457/0710');
});

test('an unsigned invoice can pick up a newly uploaded signature and invalidates its archive', function () {
    $input = presentationInvoiceInput($this->customer, ['sign_document' => false]);
    $id = $this->postJson('/api/v1/invoices', $input)->assertOk()->json('data.id');
    $invoice = Invoice::query()->findOrFail($id);
    $properties = (new ReflectionMethod($invoice, 'archivedPdfProperties'))->invoke($invoice);
    $current = new ReflectionMethod($invoice, 'archivedPdfIsCurrent');
    expect($current->invoke($invoice, $properties))->toBeTrue();

    $this->post('/api/v1/company/signature', [
        'issuer_name' => 'Vystavitel', 'signature' => UploadedFile::fake()->image('signature.png', 400, 120),
    ])->assertOk();
    $this->putJson('/api/v1/invoices/'.$id, [...$input, 'sign_document' => true])->assertOk();

    $invoice->refresh();
    expect($invoice->document_signature['image'])->toStartWith('data:image/png;base64,')
        ->and($current->invoke($invoice, $properties))->toBeFalse();
});
