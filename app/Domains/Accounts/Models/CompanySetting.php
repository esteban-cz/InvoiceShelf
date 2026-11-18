<?php

namespace App\Domains\Accounts\Models;

use App\Support\SpaTranslations;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * One preference belonging to a company.
 *
 * The store is a plain key/value table addressed by the `option` column. There
 * is no global layer underneath it: a read either finds a row for the company
 * asked about or comes back empty, and a write upserts on the option/company
 * pair.
 */
class CompanySetting extends Model
{
    use HasFactory;

    /** The original stock text is stored on existing companies. */
    public const DOCUMENT_MAIL_BODIES = [
        'invoice_mail_body' => 'You have received a new invoice from <b>{COMPANY_NAME}</b>.</br> Please download using the button below:',
        'estimate_mail_body' => 'You have received a new estimate from <b>{COMPANY_NAME}</b>.</br> Please download using the button below:',
        'payment_mail_body' => 'Thank you for the payment.</b></br> Please download your payment receipt using the button below:',
    ];

    protected $table = 'company_settings';

    protected $fillable = ['company_id', 'option', 'value'];

    /**
     * Company this preference belongs to.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Narrow a query to one company's rows.
     */
    public function scopeWhereCompany($query, $company_id)
    {
        $query->where('company_id', $company_id);
    }

    /**
     * Write a batch of preferences for one company, replacing the value of any
     * option already on file and inserting the rest.
     */
    public static function setSettings(array $settings, mixed $company_id): void
    {
        foreach ($settings as $option => $value) {
            self::updateOrCreate(
                ['option' => $option, 'company_id' => $company_id],
                ['option' => $option, 'company_id' => $company_id, 'value' => $value]
            );
        }
    }

    /**
     * Every preference on file for a company, keyed by option name.
     */
    public static function getAllSettings(mixed $company_id): Collection
    {
        return self::localizeMailBodies(self::flatten(
            static::whereCompany($company_id)->get()
        ), $company_id);
    }

    /**
     * The named preferences only; options with no row on file are left out.
     */
    public static function getSettings(array $settings, mixed $company_id): Collection
    {
        return self::localizeMailBodies(self::flatten(
            static::whereIn('option', $settings)->whereCompany($company_id)->get()
        ), $company_id);
    }

    /**
     * One preference value, or null when the company has no row for it.
     */
    public static function getSetting(string $key, mixed $company_id): mixed
    {
        if (isset(self::DOCUMENT_MAIL_BODIES[$key])) {
            return self::getSettings([$key], $company_id)->get($key);
        }

        $setting = static::query()
            ->where('option', $key)
            ->whereCompany($company_id)
            ->first();

        if ($setting) {
            return $setting->value;
        } else {
            return null;
        }
    }

    /**
     * The time zone a company keeps its books in.
     *
     * Falls back to the application's own zone when the company has never
     * been given one. Kept here rather than at each call site so "what is
     * today for this company" has a single answer.
     */
    public static function timeZone(mixed $company_id): string
    {
        return static::getSetting('time_zone', $company_id) ?: config('app.timezone');
    }

    /**
     * Reduce preference rows to an option => value collection.
     */
    private static function flatten(Collection $rows): Collection
    {
        return $rows->mapWithKeys(function ($row) {
            return [$row['option'] => $row['value']];
        });
    }

    private static function localizeMailBodies(Collection $settings, mixed $companyId): Collection
    {
        $stock = $settings->intersectAssoc(self::DOCUMENT_MAIL_BODIES);

        if ($stock->isEmpty()) {
            return $settings;
        }

        $locale = $settings->get('language') ?: self::getSetting('language', $companyId) ?: config('app.locale', 'cs');

        return $settings->replace($stock->map(
            fn (string $value, string $key): string => SpaTranslations::get($locale, 'document_mail_defaults.'.$key)
        ));
    }
}
