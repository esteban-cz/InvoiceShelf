<?php

use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Country;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Sales\Models\Estimate;
use App\Domains\Sales\Models\Invoice;
use App\Platform\Operations\Models\Setting;
use App\Platform\Storage\Models\FileDisk;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;

beforeEach(function () {
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    Queue::fake();
    Storage::fake('local');
    $user = User::findOrFail(1);
    $this->company = $user->companies()->firstOrFail();
    $this->company->update(['name' => 'Tomecka.']);
    $country = Country::where('code', 'CZ')->value('id');
    $this->company->address()->updateOrCreate([], ['address_street_1' => 'Žitná 610/23', 'zip' => '110 00', 'city' => 'Praha 1', 'country_id' => $country, 'phone' => '+420 777 554 001']);
    $this->customer = Customer::factory()->create(['company_id' => $this->company->id, 'currency_id' => Currency::where('code', 'CZK')->value('id')]);
    $this->customer->billingAddress()->create(['type' => 'billing', 'name' => 'Odběratel & synové', 'address_street_1' => 'Ukázková 123', 'address_street_2' => 'Kancelář 5', 'state' => 'Jihomoravský kraj', 'zip' => '602 00', 'city' => 'Brno', 'country_id' => $country, 'phone' => '+420 123 456 789']);
    $this->customer->shippingAddress()->create(['type' => 'shipping', 'name' => 'Místo dodání', 'address_street_1' => 'Doručovací 10', 'zip' => '301 00', 'city' => 'Plzeň', 'country_id' => $country]);
    CompanySetting::setSettings([
        'language' => 'cs', 'currency' => $this->customer->currency_id,
        'invoice_company_address_format' => "{COMPANY_NAME}\n{COMPANY_ADDRESS_STREET_1}, {COMPANY_ZIP_CODE}\n{COMPANY_CITY}, {COMPANY_COUNTRY}\n{COMPANY_PHONE}",
        'invoice_billing_address_format' => "<h3>{BILLING_ADDRESS_NAME}</h3>\n{BILLING_ADDRESS_STREET_1}, {BILLING_ZIP_CODE}\n{BILLING_ADDRESS_STREET_2}\n{BILLING_STATE}\n{BILLING_CITY}, {BILLING_COUNTRY}\n{BILLING_PHONE}",
        'invoice_shipping_address_format' => "<h3>{SHIPPING_ADDRESS_NAME}</h3>\n{SHIPPING_ADDRESS_STREET_1}, {SHIPPING_ZIP_CODE}\n{SHIPPING_CITY}, {SHIPPING_COUNTRY}",
        'estimate_company_address_format' => 'OLD ESTIMATE COMPANY', 'estimate_billing_address_format' => 'OLD ESTIMATE BILLING', 'estimate_shipping_address_format' => 'OLD ESTIMATE SHIPPING',
        'payment_company_address_format' => 'OLD PAYMENT COMPANY', 'payment_from_customer_address_format' => 'OLD PAYMENT BILLING',
    ], $this->company->id);
    app()->setLocale('cs');
    Sanctum::actingAs($user);
    $this->withHeaders(['company' => $this->company->id]);
});

function sharedAddressDocument(object $test, string $type, ?string $template = null): Invoice|Estimate|Payment
{
    $model = match ($type) {
        'invoice' => Invoice::class, 'estimate' => Estimate::class, 'payment' => Payment::class
    };
    $attributes = ['company_id' => $test->company->id, 'customer_id' => $test->customer->id, 'currency_id' => $test->customer->currency_id, 'exchange_rate' => 1];
    if ($template !== null) {
        $attributes['template_name'] = $template;
    }

    return $model::factory()->create($attributes)->fresh();
}

test('every stock document template uses the invoice company and customer address formats', function (string $type, string $template) {
    $document = sharedAddressDocument($this, $type, $type === 'payment' ? null : $template);
    $company = $document->getCompanyAddress();
    $billing = $document->getCustomerBillingAddress();
    expect(preg_replace('/<br\s*\/?>\s*/u', "\n", $company))->toContain("Žitná 610/23, 110 00\nPraha 1, Česko", '+420 777 554 001');
    expect(preg_replace('/<br\s*\/?>\s*/u', "\n", $billing))->toContain('<h3>Odběratel &amp; synové</h3>', "Ukázková 123, 602 00\nKancelář 5\nJihomoravský kraj\nBrno, Česko", '+420 123 456 789');
    $response = $this->get('/'.$type.'s/pdf/'.$document->unique_hash.'?preview');
    $response->assertOk()->assertSee($company, false)->assertSee($billing, false)
        ->assertDontSee('OLD ESTIMATE')->assertDontSee('OLD PAYMENT');
    if ($type !== 'payment') {
        $response->assertSee($document->getCustomerShippingAddress(), false);
    }
})->with([
    ['invoice', 'invoice1'], ['invoice', 'invoice2'], ['invoice', 'invoice3'],
    ['estimate', 'estimate1'], ['estimate', 'estimate2'], ['estimate', 'estimate3'], ['payment', 'payment'],
]);

test('payment and estimate address accessors resolve exactly the same markup as invoices', function () {
    $invoice = sharedAddressDocument($this, 'invoice');
    foreach (['payment', 'estimate'] as $type) {
        $document = sharedAddressDocument($this, $type);
        expect($document->getCompanyAddress())->toBe($invoice->getCompanyAddress())
            ->and($document->getCustomerBillingAddress())->toBe($invoice->getCustomerBillingAddress());
        if ($type === 'estimate') {
            expect($document->getCustomerShippingAddress())->toBe($invoice->getCustomerShippingAddress());
        }
    }
});

test('intentionally blank shared formats do not fall back to legacy document settings', function (string $type) {
    CompanySetting::setSettings(['invoice_company_address_format' => '', 'invoice_billing_address_format' => ''], $this->company->id);
    $document = sharedAddressDocument($this, $type);
    expect($document->getCompanyAddress())->toBe('')->and($document->getCustomerBillingAddress())->toBe('');
})->with(['invoice', 'estimate', 'payment']);

test('archives regenerate after shared address settings change and older archives are rejected', function (string $type) {
    FileDisk::query()->update(['set_as_default' => false]);
    FileDisk::factory()->create(['set_as_default' => true, 'credentials' => ['driver' => 'local', 'root' => Storage::disk('local')->path('address-archives')]]);
    Setting::setSetting('save_pdf_to_disk', 'YES');
    $document = sharedAddressDocument($this, $type);
    $number = $document->{$type.'_number'};
    $generated = $document->generatePDF($type, $number);
    expect($generated)->toBeTrue()
        ->and($document->fresh()->getGeneratedPDF($type))->not->toBeFalse();
    CompanySetting::setSettings(['invoice_company_address_format' => '<h3>{COMPANY_NAME}</h3><p>{COMPANY_CITY}</p>'], $this->company->id);
    expect($document->fresh()->getGeneratedPDF($type))->toBeFalse();
    expect($document->fresh()->generatePDF($type, $number, true))->toBeTrue()
        ->and($document->fresh()->getGeneratedPDF($type))->not->toBeFalse();
    $archive = $document->fresh()->getMedia($type)->last();
    $archive->forgetCustomProperty('document_address_format_fingerprint')->save();
    expect($document->fresh()->getGeneratedPDF($type))->toBeFalse();
})->with(['invoice', 'estimate', 'payment']);
