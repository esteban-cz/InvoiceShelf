<?php

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\User;
use App\Domains\Contacts\Models\Customer;
use App\Domains\Money\Models\Currency;
use App\Domains\Receivables\Application\FioMatchingService;
use App\Domains\Receivables\Application\FioStatementParser;
use App\Domains\Receivables\Application\FioSyncService;
use App\Domains\Receivables\Application\PaymentAllocationService;
use App\Domains\Receivables\Events\PaymentRecorded;
use App\Domains\Receivables\Models\FioConnection;
use App\Domains\Receivables\Models\FioTransaction;
use App\Domains\Receivables\Models\Payment;
use App\Domains\Sales\Models\Invoice;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\HttpKernel\Exception\HttpException;

beforeEach(function () {
    config(['invoiceshelf.fio_enabled' => true]);
    $this->seed([DatabaseSeeder::class, DemoSeeder::class]);
    $this->user = User::findOrFail(1);
    $this->company = $this->user->companies()->first();
    $this->currency = Currency::where('code', 'CZK')->firstOrFail();
    CompanySetting::setSettings(['currency' => $this->currency->id], $this->company->id);
    $this->withHeaders(['company' => $this->company->id]);
    Sanctum::actingAs($this->user);
    Queue::fake();
    Event::fake([PaymentRecorded::class]);
    Cache::flush();
    Http::preventStrayRequests();
});

function fioXml(array $rows = [], string $iban = 'CZ8120100000002102589740'): string
{
    $xml = '<AccountStatement><Info><iban>'.$iban.'</iban><currency>CZK</currency></Info><TransactionList>';
    foreach ($rows as $row) {
        $row += [22 => '9007199254740993', 0 => today()->toDateString().'+01:00', 1 => '5000.00', 14 => 'CZK', 5 => '000202601', 2 => '123456789', 3 => '0800', 10 => 'Customer', 16 => 'Invoice payment'];
        $xml .= '<Transaction>';
        foreach ($row as $column => $value) {
            $xml .= '<column_'.$column.'>'.htmlspecialchars($value, ENT_XML1).'</column_'.$column.'>';
        }
        $xml .= '</Transaction>';
    }

    return $xml.'</TransactionList></AccountStatement>';
}

function fioConnection($test, array $attributes = []): FioConnection
{
    return FioConnection::create($attributes + ['company_id' => $test->company->id, 'creator_id' => $test->user->id,
        'name' => 'Fio CZK', 'token' => str_repeat('a', 64), 'iban' => 'CZ8120100000002102589740', 'currency_code' => 'CZK',
        'enabled' => true, 'auto_match' => true, 'sync_from' => today()->toDateString()]);
}

function fioInvoice($test, array $attributes = []): Invoice
{
    $customer = Customer::factory()->create(['company_id' => $test->company->id, 'currency_id' => $test->currency->id]);

    return Invoice::factory()->create($attributes + ['company_id' => $test->company->id, 'customer_id' => $customer->id,
        'currency_id' => $test->currency->id, 'type' => Invoice::TYPE_INVOICE, 'status' => Invoice::STATUS_SENT, 'sent' => true,
        'sub_total' => 500000, 'total' => 500000, 'due_amount' => 500000, 'base_sub_total' => 500000, 'base_total' => 500000, 'base_due_amount' => 500000,
        'exchange_rate' => 1, 'payment_qr' => ['iban' => 'CZ8120100000002102589740', 'variable_symbol' => '202601']]);
}

function fioMovement($test, FioConnection $connection, array $attributes = []): FioTransaction
{
    return FioTransaction::create($attributes + ['company_id' => $test->company->id, 'fio_connection_id' => $connection->id,
        'account_iban' => $connection->iban, 'external_id' => '123456', 'transaction_date' => today()->toDateString(),
        'amount' => 500000, 'currency_code' => 'CZK', 'variable_symbol' => '000202601', 'status' => 'pending']);
}

test('Fio setup encrypts token and never returns it', function () {
    Http::fake(['fioapi.fio.cz/*' => Http::response(fioXml(), 200, ['Content-Type' => 'application/xml'])]);
    $secret = str_repeat('a', 64);
    $this->postJson('/api/v1/fio/connections', ['name' => 'Fio', 'token' => $secret, 'sync_from' => today()->toDateString(),
        'enabled' => true, 'auto_match' => true])->assertSuccessful()->assertJsonPath('data.iban', 'CZ8120100000002102589740')->assertDontSee($secret)->assertJsonMissingPath('data.token');
    expect(DB::table('fio_connections')->value('token'))->not->toBe($secret)
        ->and(FioConnection::first()->token)->toBe($secret)
        ->and(FioConnection::first()->toJson())->not->toContain($secret);
});

test('Fio exact matches use the payment service and repeated syncs never book twice', function () {
    $invoice = fioInvoice($this);
    $connection = fioConnection($this);
    Http::fake(['fioapi.fio.cz/*' => Http::response(fioXml([[]]))]);
    $this->postJson('/api/v1/fio/connections/'.$connection->id.'/sync')->assertSuccessful();
    $payment = Payment::latest('id')->firstOrFail();
    expect($payment->amount)->toBe(500000)->and($payment->allocations()->first()->invoice_id)->toBe($invoice->id)
        ->and($invoice->fresh()->due_amount)->toBe(0)->and(FioTransaction::first()->status)->toBe('matched');
    Cache::flush();
    app(FioSyncService::class)->sync($connection);
    expect(Payment::count())->toBe(1)->and(FioTransaction::count())->toBe(1);
});

test('Fio partial, excessive, ambiguous, draft and wrong-account movements await review', function (string $case) {
    $attributes = match ($case) {
        'partial' => ['due_amount' => 600000], 'overpay' => ['due_amount' => 400000],
        'draft' => ['status' => Invoice::STATUS_DRAFT],
        'wrong_account' => ['payment_qr' => ['iban' => 'CZ6508000000192000145399', 'variable_symbol' => '202601']],
        'wrong_currency' => ['currency_id' => Currency::where('code', 'EUR')->value('id')],
        default => [],
    };
    fioInvoice($this, $attributes);
    if ($case === 'ambiguous') {
        fioInvoice($this);
    }
    $movement = fioMovement($this, fioConnection($this));
    app(FioMatchingService::class)->autoMatch($movement);
    expect($movement->fresh()->status)->toBe('pending')->and(Payment::count())->toBe(0);
})->with(['partial', 'overpay', 'ambiguous', 'draft', 'wrong_account', 'wrong_currency']);

test('Fio does not automatically duplicate a payment already recorded manually', function () {
    $invoice = fioInvoice($this);
    Payment::factory()->create(['company_id' => $this->company->id, 'customer_id' => $invoice->customer_id,
        'currency_id' => $this->currency->id, 'amount' => 500000, 'payment_date' => today()->toDateString()]);
    $movement = fioMovement($this, fioConnection($this));
    app(FioMatchingService::class)->autoMatch($movement);
    expect($movement->fresh()->reason)->toBe('possible_duplicate')->and(Payment::count())->toBe(1);
});

test('Fio manual review handles partial payments and leaves excess as customer credit', function (int $amount, int $due, int $credit) {
    $invoice = fioInvoice($this);
    $movement = fioMovement($this, fioConnection($this), ['amount' => $amount]);
    $this->patchJson('/api/v1/fio/transactions/'.$movement->id, ['action' => 'match', 'invoice_id' => $invoice->id])
        ->assertOk()->assertJsonPath('data.status', 'matched');
    expect($invoice->fresh()->due_amount)->toBe($due);
    $payment = $movement->fresh()->payment;
    expect((int) $payment->amount - (int) $payment->allocations()->sum('amount'))->toBe($credit);
    $this->patchJson('/api/v1/fio/transactions/'.$movement->id, ['action' => 'match', 'invoice_id' => $invoice->id])->assertUnprocessable();
    expect(Payment::count())->toBe(1);
})->with([[200000, 300000, 0], [600000, 0, 100000]]);

test('Fio can link an existing payment without creating one and prohibits reusing it', function () {
    $invoice = fioInvoice($this);
    $payment = Payment::factory()->create(['company_id' => $this->company->id, 'customer_id' => $invoice->customer_id,
        'currency_id' => $this->currency->id, 'amount' => 500000, 'payment_date' => today()->toDateString()]);
    $connection = fioConnection($this);
    $first = fioMovement($this, $connection);
    $this->patchJson('/api/v1/fio/transactions/'.$first->id, ['action' => 'link', 'payment_id' => $payment->id])->assertOk();
    $second = fioMovement($this, $connection, ['external_id' => '654321']);
    $this->patchJson('/api/v1/fio/transactions/'.$second->id, ['action' => 'link', 'payment_id' => $payment->id])->assertUnprocessable();
    expect(Payment::count())->toBe(1)->and($invoice->fresh()->due_amount)->toBe(500000);
});

test('ignored Fio transactions can be restored without importing them again', function () {
    $movement = fioMovement($this, fioConnection($this));
    $this->patchJson('/api/v1/fio/transactions/'.$movement->id, ['action' => 'ignore'])->assertOk()->assertJsonPath('data.status', 'ignored');
    $this->patchJson('/api/v1/fio/transactions/'.$movement->id, ['action' => 'restore'])->assertOk()->assertJsonPath('data.status', 'pending');
});

test('disconnect removes credentials and preserves deduplication history on reconnect', function () {
    $connection = fioConnection($this);
    $movement = fioMovement($this, $connection);
    $this->deleteJson('/api/v1/fio/connections/'.$connection->id)->assertNoContent();
    expect(FioConnection::count())->toBe(0)->and($movement->fresh()->fio_connection_id)->toBeNull();
    Http::fake(['fioapi.fio.cz/*' => Http::response(fioXml([[22 => '123456']]))]);
    $this->postJson('/api/v1/fio/connections', ['name' => 'New token', 'token' => str_repeat('b', 64), 'sync_from' => today()->toDateString(), 'enabled' => true, 'auto_match' => true])->assertSuccessful();
    expect(FioTransaction::count())->toBe(1)->and(Payment::count())->toBe(0);
});

test('Fio token errors are sanitized and requests are rate limited', function () {
    $connection = fioConnection($this);
    Http::fake(['fioapi.fio.cz/*' => Http::response('secret bank diagnostic', 500)]);
    $this->postJson('/api/v1/fio/connections/'.$connection->id.'/sync')->assertUnprocessable()->assertDontSee($connection->token)->assertDontSee('secret bank diagnostic');
    $this->postJson('/api/v1/fio/connections/'.$connection->id.'/sync')->assertStatus(429);
    Http::assertSentCount(1);
    expect($connection->fresh()->imported_through)->toBeNull();
});

test('Fio parser preserves exact decimals and IDs and ignores outgoing movements', function () {
    $parsed = app(FioStatementParser::class)->parse(fioXml([[1 => '12345678901234.56']]));
    expect($parsed['transactions'][0]['external_id'])->toBe('9007199254740993')
        ->and($parsed['transactions'][0]['amount'])->toBe(1234567890123456);
    $connection = fioConnection($this);
    Http::fake(['fioapi.fio.cz/*' => Http::response(fioXml([[1 => '-15.01']]))]);
    app(FioSyncService::class)->sync($connection);
    expect(FioTransaction::first()->amount)->toBe(-1501)->and(FioTransaction::first()->status)->toBe('ignored')->and(Payment::count())->toBe(0);
});

test('Fio rejects XML entities and malformed money without advancing the cursor', function (string $body) {
    $connection = fioConnection($this);
    Http::fake(['fioapi.fio.cz/*' => Http::response($body)]);
    $this->postJson('/api/v1/fio/connections/'.$connection->id.'/sync')->assertStatus(502);
    expect(FioTransaction::count())->toBe(0)->and($connection->fresh()->imported_through)->toBeNull();
})->with([fn () => '<!DOCTYPE test [<!ENTITY xxe SYSTEM "file:///etc/passwd">]><AccountStatement>&xxe;</AccountStatement>', fn () => fioXml([[1 => '1.001']])]);

test('Fio data is owner-only and company scoped', function () {
    $connection = fioConnection($this);
    $movement = fioMovement($this, $connection);
    $stranger = User::factory()->create();
    $this->company->users()->attach($stranger);
    Sanctum::actingAs($stranger);
    $this->getJson('/api/v1/fio/connections')->assertForbidden();
    $this->getJson('/api/v1/fio/transactions')->assertForbidden();
    $this->patchJson('/api/v1/fio/transactions/'.$movement->id, ['action' => 'ignore'])->assertForbidden();
    Sanctum::actingAs($this->user);
    $foreign = Company::factory()->create();
    $connection->update(['company_id' => $foreign->id]);
    $movement->update(['company_id' => $foreign->id]);
    $this->postJson('/api/v1/fio/connections/'.$connection->id.'/sync')->assertNotFound();
    $this->getJson('/api/v1/fio/transactions/'.$movement->id.'/candidates')->assertNotFound();
    Http::assertNothingSent();
});

test('Fio retains paid invoices and never automatically records another receipt against them', function () {
    fioInvoice($this, ['due_amount' => 0, 'paid_status' => Invoice::STATUS_PAID]);
    $row = fioMovement($this, fioConnection($this));
    app(FioMatchingService::class)->autoMatch($row);
    expect($row->fresh()->status)->toBe('pending')->and(Payment::count())->toBe(0);
});

test('Fio recognizes older manual partial payments even outside the date suggestion window', function () {
    $invoice = fioInvoice($this);
    $payment = Payment::factory()->create(['company_id' => $this->company->id, 'customer_id' => $invoice->customer_id,
        'currency_id' => $this->currency->id, 'amount' => 250000, 'base_amount' => 250000, 'exchange_rate' => 1,
        'payment_date' => today()->copy()->subDays(14)->toDateString()]);
    app(PaymentAllocationService::class)->replace($payment, [['invoice_id' => $invoice->id, 'amount' => 250000]]);
    $row = fioMovement($this, fioConnection($this), ['amount' => 250000]);
    app(FioMatchingService::class)->autoMatch($row);
    expect($row->fresh()->reason)->toBe('possible_duplicate')->and(Payment::count())->toBe(1);
    $this->getJson('/api/v1/fio/transactions/'.$row->id.'/candidates')->assertOk()->assertJsonPath('data.payments.0.id', $payment->id);
});

test('disabling automatic matching leaves exact matches for review', function () {
    fioInvoice($this);
    $connection = fioConnection($this, ['auto_match' => false]);
    Http::fake(['fioapi.fio.cz/*' => Http::response(fioXml([[]]))]);
    app(FioSyncService::class)->sync($connection);
    expect(FioTransaction::first()->status)->toBe('pending')->and(Payment::count())->toBe(0);
});

test('foreign currency payments require a reviewed exchange rate', function () {
    $euro = Currency::where('code', 'EUR')->firstOrFail();
    $invoice = fioInvoice($this);
    $invoice->customer->update(['currency_id' => $euro->id]);
    $invoice->update(['currency_id' => $euro->id, 'exchange_rate' => 25, 'base_total' => 12500000, 'base_due_amount' => 12500000]);
    $row = fioMovement($this, fioConnection($this), ['currency_code' => 'EUR']);
    app(FioMatchingService::class)->autoMatch($row);
    expect($row->fresh()->reason)->toBe('exchange_rate_required');
    $this->patchJson('/api/v1/fio/transactions/'.$row->id, ['action' => 'match', 'invoice_id' => $invoice->id])
        ->assertUnprocessable()->assertJsonValidationErrors('exchange_rate');
    $this->patchJson('/api/v1/fio/transactions/'.$row->id, ['action' => 'match', 'invoice_id' => $invoice->id, 'exchange_rate' => 25])->assertOk();
    expect((int) $row->fresh()->payment->base_amount)->toBe(12500000)->and($invoice->fresh()->due_amount)->toBe(0);
});

test('token rotation keeps the account and omitting a token keeps the existing secret', function () {
    $connection = fioConnection($this);
    $payload = ['name' => 'Renamed Fio', 'enabled' => true, 'auto_match' => false];
    $this->patchJson('/api/v1/fio/connections/'.$connection->id, $payload)->assertOk();
    expect($connection->fresh()->token)->toBe(str_repeat('a', 64));
    Http::fake(fn ($request) => Http::response(str_contains($request->url(), str_repeat('c', 64)) ? fioXml([], 'CZ6508000000192000145399') : fioXml()));
    $this->patchJson('/api/v1/fio/connections/'.$connection->id, $payload + ['token' => str_repeat('b', 64)])
        ->assertOk()->assertDontSee(str_repeat('b', 64));
    expect($connection->fresh()->token)->toBe(str_repeat('b', 64));
    $this->patchJson('/api/v1/fio/connections/'.$connection->id, $payload + ['token' => str_repeat('c', 64)])->assertUnprocessable();
    expect($connection->fresh()->token)->toBe(str_repeat('b', 64));
});

test('Fio errors never expose a token even when transport exceptions contain its URL', function () {
    $connection = fioConnection($this);
    Http::fake(fn () => throw new ConnectionException('Failure: https://fioapi.fio.cz/'.$connection->token));
    $this->postJson('/api/v1/fio/connections/'.$connection->id.'/sync')->assertStatus(503)->assertDontSee($connection->token);
});

test('Fio scheduler continues importing other connections after one fails', function () {
    $first = fioConnection($this);
    $second = fioConnection($this, ['token' => str_repeat('b', 64), 'iban' => 'CZ6508000000192000145399']);
    Http::fake([
        'fioapi.fio.cz/v1/rest/periods/'.str_repeat('a', 64).'/*' => Http::response('', 500),
        'fioapi.fio.cz/v1/rest/periods/'.str_repeat('b', 64).'/*' => Http::response(fioXml([], $second->iban)),
    ]);
    $this->artisan('fio:sync')->assertFailed();
    expect($first->fresh()->last_error)->not->toBeNull()->and($second->fresh()->last_synced_at)->not->toBeNull();
});

test('bank movements and invoices belonging to other companies cannot be paired', function () {
    $other = Company::factory()->create();
    $invoice = fioInvoice($this, ['company_id' => $other->id]);
    $row = fioMovement($this, fioConnection($this));
    app(FioMatchingService::class)->autoMatch($row);
    expect(Payment::count())->toBe(0);
    $this->patchJson('/api/v1/fio/transactions/'.$row->id, ['action' => 'match', 'invoice_id' => $invoice->id])->assertUnprocessable();
});

test('an imported movement cannot reuse a payment from another company or with the wrong amount', function () {
    $invoice = fioInvoice($this);
    $row = fioMovement($this, fioConnection($this));
    $payment = Payment::factory()->create(['company_id' => $this->company->id, 'customer_id' => $invoice->customer_id,
        'currency_id' => $this->currency->id, 'amount' => 1000]);
    $this->patchJson('/api/v1/fio/transactions/'.$row->id, ['action' => 'link', 'payment_id' => $payment->id])->assertUnprocessable();
    $payment->update(['company_id' => Company::factory()->create()->id, 'amount' => 500000]);
    $this->patchJson('/api/v1/fio/transactions/'.$row->id, ['action' => 'link', 'payment_id' => $payment->id])->assertUnprocessable();
    expect($row->fresh()->status)->toBe('pending');
});

test('Fio history requests use fixed periods and overlap subsequent imports for late postings', function () {
    $connection = fioConnection($this, ['sync_from' => today()->copy()->subDays(60)->toDateString()]);
    Http::fake(['fioapi.fio.cz/*' => Http::response(fioXml())]);
    app(FioSyncService::class)->sync($connection);
    expect($connection->fresh()->imported_through->toDateString())->toBe(today()->copy()->subDays(30)->toDateString());
    Http::assertSent(fn ($request) => str_contains($request->url(), '/periods/') && ! str_contains($request->url(), '/last/'));
    Cache::flush();
    app(FioSyncService::class)->sync($connection);
    expect($connection->fresh()->imported_through->toDateString())->toBe(today()->copy()->subDays(7)->toDateString());
});

test('Fio uses the Czech bank date when the UTC calendar date is still yesterday', function () {
    $this->travelTo(Carbon\Carbon::parse('2026-11-17 23:30:00', 'UTC'));
    Http::fake(['fioapi.fio.cz/*' => Http::response(fioXml())]);
    $this->postJson('/api/v1/fio/connections', ['name' => 'Fio', 'token' => str_repeat('a', 64), 'sync_from' => '2026-11-18',
        'enabled' => true, 'auto_match' => true])->assertSuccessful()->assertJsonPath('data.imported_through', '2026-11-18');
    $this->travelBack();
});

test('Fio refuses invoices referencing a customer from another company', function () {
    $invoice = fioInvoice($this);
    $invoice->customer->update(['company_id' => Company::factory()->create()->id]);
    $row = fioMovement($this, fioConnection($this));
    app(FioMatchingService::class)->autoMatch($row);
    expect(Payment::count())->toBe(0);
    $this->patchJson('/api/v1/fio/transactions/'.$row->id, ['action' => 'match', 'invoice_id' => $invoice->id])->assertUnprocessable();
});

test('Fio resource IDs are constrained to numbers', function () {
    $this->postJson('/api/v1/fio/connections/not-a-number/sync')->assertNotFound();
    $this->getJson('/api/v1/fio/transactions/not-a-number/candidates')->assertNotFound();
});

test('disabling Fio hides it in bootstrap and blocks every bank API without changing data', function () {
    config(['invoiceshelf.fio_enabled' => false]);
    $connection = fioConnection($this);
    $row = fioMovement($this, $connection);
    $this->getJson('/api/v1/bootstrap')->assertOk()->assertJsonPath('config.fio_enabled', false);
    $this->getJson('/api/v1/fio/connections')->assertNotFound();
    $this->getJson('/api/v1/fio/transactions')->assertNotFound();
    $this->getJson('/api/v1/fio/transactions/'.$row->id.'/candidates')->assertNotFound();
    $this->postJson('/api/v1/fio/connections', [])->assertNotFound();
    $this->patchJson('/api/v1/fio/connections/'.$connection->id, [])->assertNotFound();
    $this->deleteJson('/api/v1/fio/connections/'.$connection->id)->assertNotFound();
    $this->postJson('/api/v1/fio/connections/'.$connection->id.'/sync')->assertNotFound();
    $this->patchJson('/api/v1/fio/transactions/'.$row->id, ['action' => 'ignore'])->assertNotFound();
    expect($connection->fresh()->enabled)->toBeTrue()->and($row->fresh()->status)->toBe('pending')->and(Payment::count())->toBe(0);
    Http::assertNothingSent();
});

test('disabled Fio skips manual and scheduled synchronization without contacting the bank', function () {
    config(['invoiceshelf.fio_enabled' => false]);
    $connection = fioConnection($this);
    $this->artisan('fio:sync')->expectsOutput('Fio integration is disabled.')->assertSuccessful();
    expect(fn () => app(FioSyncService::class)->sync($connection))->toThrow(HttpException::class);
    expect($connection->fresh()->last_attempt_at)->toBeNull()->and(Payment::count())->toBe(0);
    Http::assertNothingSent();
    $event = collect(app(Schedule::class)->events())->first(fn ($event) => str_contains($event->command ?? '', 'fio:sync'));
    expect($event)->not->toBeNull()->and($event->filtersPass(app()))->toBeFalse();
});
