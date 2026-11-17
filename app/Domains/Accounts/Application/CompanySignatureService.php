<?php

namespace App\Domains\Accounts\Application;

use App\Domains\Accounts\Models\Company;
use App\Domains\Accounts\Models\CompanySetting;
use App\Support\Media\SafeFileName;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class CompanySignatureService
{
    /** @return array{issuer_name: string, image: string|null} */
    public function settings(Company $company): array
    {
        $media = $company->getFirstMedia('signature');
        $bytes = $media ? Storage::disk($media->disk)->get($media->getPathRelativeToRoot()) : null;

        return [
            'issuer_name' => (string) (CompanySetting::getSetting('invoice_issuer_name', $company->id) ?: $company->owner?->name),
            'image' => $media && $bytes !== null ? 'data:'.$media->mime_type.';base64,'.base64_encode($bytes) : null,
        ];
    }

    public function save(Company $company, string $issuer_name, ?UploadedFile $signature, bool $remove): array
    {
        if ($signature) {
            $company->addMedia($signature)
                ->usingFileName(SafeFileName::from($signature->getClientOriginalName()))
                ->toMediaCollection('signature', config('media-library.disk_name'));
        } elseif ($remove) {
            $company->clearMediaCollection('signature');
        }

        CompanySetting::setSettings(['invoice_issuer_name' => $issuer_name], $company->id);

        return $this->settings($company->fresh());
    }
}
