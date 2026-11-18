<?php

namespace App\Domains\Accounts\Application;

use App\Domains\Accounts\Contracts\CompanyDataPurger;
use App\Domains\Accounts\Contracts\CompanyDefaultsProvisioner;
use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Domains\Accounts\Models\RolePreset;
use App\Domains\Accounts\Models\User;
use App\Facades\Hashids;
use App\Support\Hashids\HashidConnection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Silber\Bouncer\BouncerFacade;
use Silber\Bouncer\Database\Role;

/**
 * Everything that happens to a company either side of its working life: the
 * furniture a fresh one is handed, and the demolition of an old one.
 *
 * The reference data (payment methods, units) is provisioned through the
 * adapter; what stays here is the authorization scaffolding and the sheet of
 * default preferences a company starts out with.
 */
class CompanyService
{
    /** The role every company's creator is given. */
    private const OWNER_ROLE = RolePreset::OWNER;

    /** Address blocks printed on the documents; placeholders filled at render. */
    private const BILLING_ADDRESS_FORMAT = "{BILLING_ADDRESS_NAME}\n{BILLING_ADDRESS_STREET_1}, {BILLING_ZIP_CODE}\n{BILLING_ADDRESS_STREET_2}\n{BILLING_STATE}\n{BILLING_CITY}, {BILLING_COUNTRY}\n{BILLING_PHONE}";

    private const SHIPPING_ADDRESS_FORMAT = "{SHIPPING_ADDRESS_NAME}\n{SHIPPING_ADDRESS_STREET_1}, {SHIPPING_ZIP_CODE}\n{SHIPPING_ADDRESS_STREET_2}\n{SHIPPING_STATE}\n{SHIPPING_CITY}, {SHIPPING_COUNTRY}\n{SHIPPING_PHONE}";

    private const COMPANY_ADDRESS_FORMAT = "{COMPANY_NAME}\n{COMPANY_ADDRESS_STREET_1}, {COMPANY_ZIP_CODE}\n{COMPANY_ADDRESS_STREET_2}\n{COMPANY_STATE}\n{COMPANY_CITY}, {COMPANY_COUNTRY}\n{COMPANY_PHONE}";

    /** Receipts share the same billing address format. */
    private const PAYMENT_CUSTOMER_ADDRESS_FORMAT = self::BILLING_ADDRESS_FORMAT;

    public function __construct(
        private readonly CompanyDefaultsProvisioner $companyDefaultsProvisioner,
        private readonly CompanyDataPurger $companyDataPurger,
        private readonly RolePresetService $rolePresets,
        private readonly AccessRevoker $accessRevoker,
        private readonly CompanyOwnership $ownership,
    ) {}

    /**
     * Furnish a newly created company.
     *
     * Order is fixed: the owner role first, so the creator has something to be
     * assigned; then the reference data; then the preference sheet, which is
     * where the chosen currency lands.
     */
    /**
     * A new company with everything a company starts with: its public hash,
     * roles, default records and settings, and the given user as its owner.
     *
     * @param  array<string, mixed>  $attributes  the company columns; owner_id and slug default from the owner and the name
     */
    public function createFor(User $owner, array $attributes, int $currencyId): Company
    {
        $company = Company::query()->create($attributes + [
            'owner_id' => $owner->id,
            'slug' => Str::slug((string) ($attributes['name'] ?? '')),
        ]);

        $company->unique_hash = Hashids::connection(HashidConnection::Company->value)->encode($company->id);
        $company->save();

        $this->setupDefaults($company, $currencyId);
        $owner->companies()->attach($company->id);

        BouncerFacade::scope()->to($company->id);
        $owner->assign(self::OWNER_ROLE);

        if ($company->owner_id !== null && (int) $company->owner_id !== (int) $owner->id) {
            $this->ownership->assign($company, User::query()->findOrFail($company->owner_id));
        }

        return $company;
    }

    public function updateCompany(Company $company, array $attributes): void
    {
        DB::transaction(function () use ($company, $attributes): void {
            $company->update(Arr::except($attributes, 'owner_id'));
            if (array_key_exists('owner_id', $attributes) && $attributes['owner_id'] != $company->owner_id) {
                $this->ownership->assign($company, $attributes['owner_id'] ? User::query()->findOrFail($attributes['owner_id']) : null);
            }
        });
    }

    public function setupDefaults(Company $company, int $currencyId = 13): bool
    {
        $this->setupRoles($company);

        $this->companyDefaultsProvisioner->provision($company);

        $this->setupDefaultSettings($company, $currencyId);

        return true;
    }

    /**
     * Give the company its copy of every role preset: the `owner` role, which
     * holds the whole ability catalogue (a module enabled at the time
     * included), and the others the super administrator defines.
     *
     * Roles live inside a company's scope, so the scope is moved onto this
     * company first and left there for whatever the caller does next.
     */
    public function setupRoles(Company $company): void
    {
        BouncerFacade::scope()->to($company->id);

        $this->rolePresets->syncCompany($company->id);
    }

    /**
     * Wind a company up.
     *
     * The purger clears everything filed against the company first; what is
     * left here is the company's own furniture — its scoped roles, the
     * memberships pointing at it, its preferences, and the row itself. The
     * member accounts survive: only the link between them and the company is
     * cut.
     */
    public function delete(Company $company): bool
    {
        $this->companyDataPurger->purge($company);

        Role::query()
            ->when($company->id, function ($query) use ($company) {
                $query->where('scope', $company->id);
            })
            ->get()
            ->each(function ($role) {
                $role->delete();
            });

        foreach ($company->users()->pluck('users.id') as $userId) {
            $this->accessRevoker->revokeCompany((int) $userId, $company->id);
        }

        $company->users()->detach();

        $company->settings()->delete();

        $company->delete();

        return true;
    }

    /**
     * The preference sheet a company starts out with.
     *
     * Two of these are historical rather than sensible and are kept on
     * purpose: the time zone defaults to `Asia/Kolkata`, and outgoing mail is
     * addressed from the project's own no-reply address until an owner changes
     * it. `bulk_exchange_rate_configured` starts out done, which keeps fresh
     * companies out of the exchange-rate backfill.
     */
    private function setupDefaultSettings(Company $company, int $currencyId): void
    {
        CompanySetting::setSettings([
            ...CompanySetting::DOCUMENT_MAIL_BODIES,
            'invoice_mail_body' => '<p>Dobrý den,<br>zasílám Vám fakturu za dodané služby. Podrobnosti a fakturu ke stažení najdete pod tlačítkem níže.</p><p>Děkuji za spolupráci.<br>Štěpán Tomečka</p>',
            'invoice_company_address_format' => self::COMPANY_ADDRESS_FORMAT,
            'invoice_shipping_address_format' => self::SHIPPING_ADDRESS_FORMAT,
            'invoice_billing_address_format' => self::BILLING_ADDRESS_FORMAT,
            'estimate_company_address_format' => self::COMPANY_ADDRESS_FORMAT,
            'estimate_shipping_address_format' => self::SHIPPING_ADDRESS_FORMAT,
            'estimate_billing_address_format' => self::BILLING_ADDRESS_FORMAT,
            'payment_company_address_format' => self::COMPANY_ADDRESS_FORMAT,
            'payment_from_customer_address_format' => self::PAYMENT_CUSTOMER_ADDRESS_FORMAT,
            'currency' => $currencyId,
            'time_zone' => 'Asia/Kolkata',
            'language' => config('app.locale', 'cs'),
            'fiscal_year' => '1-12',
            'carbon_date_format' => 'Y/m/d',
            'moment_date_format' => 'YYYY/MM/DD',
            'carbon_time_format' => 'H:i',
            'moment_time_format' => 'HH:mm',
            'invoice_use_time' => 'NO',
            'notification_email' => 'noreply@invoiceshelf.com',
            'notify_invoice_viewed' => 'NO',
            'notify_estimate_viewed' => 'NO',
            'tax_per_item' => 'NO',
            'discount_per_item' => 'NO',
            'invoice_email_attachment' => 'NO',
            'estimate_email_attachment' => 'NO',
            'payment_email_attachment' => 'NO',
            'retrospective_edits' => 'allow',
            'invoice_number_format' => '{{SERIES:INV}}{{DELIMITER:-}}{{DATE_FORMAT:Y}}{{SEQUENCE:2}}',
            'credit_note_number_format' => '{{SERIES:CN}}{{DELIMITER:-}}{{SEQUENCE:6}}',
            'estimate_number_format' => '{{SERIES:EST}}{{DELIMITER:-}}{{DATE_FORMAT:Y}}{{SEQUENCE:2}}',
            'payment_number_format' => '{{SERIES:PAY}}{{DELIMITER:-}}{{DATE_FORMAT:Y}}{{SEQUENCE:2}}',
            'estimate_set_expiry_date_automatically' => 'YES',
            'estimate_expiry_date_days' => 7,
            'invoice_set_due_date_automatically' => 'YES',
            'invoice_due_date_days' => 14,
            'document_sequence_reset_yearly' => 'YES',
            'bulk_exchange_rate_configured' => 'YES',
            'estimate_convert_action' => 'no_action',
            'automatically_expire_public_links' => 'YES',
            'link_expiry_days' => 7,
        ], $company->id);
    }
}
