<?php

namespace App\Domains\Sales\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Translation\PotentiallyTranslatedString;

class SignatureImage implements ValidationRule
{
    /**
     * Run the validation rule.
     *
     * @param  Closure(string, ?string=): PotentiallyTranslatedString  $fail
     */
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || ! preg_match('/\Adata:image\/(png|jpeg);base64,([A-Za-z0-9+\/=]+)\z/', $value, $parts)) {
            $fail(__('signature_image_invalid'));

            return;
        }

        $bytes = base64_decode($parts[2], true);
        $size = $bytes !== false && strlen($bytes) <= 256 * 1024 ? @getimagesizefromstring($bytes) : false;
        $mime = $parts[1] === 'png' ? 'image/png' : 'image/jpeg';

        if (! $size || $size[0] > 2400 || $size[1] > 1200 || ($size['mime'] ?? null) !== $mime) {
            $fail(__('signature_image_invalid'));
        }
    }
}
