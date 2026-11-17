@php($signature = $invoice->document_signature)
@if ($signature)
    <div style="margin-top: 16px; padding-left: 8px; font-size: 11px; line-height: 1.6; page-break-inside: avoid;">
        <table cellpadding="0" cellspacing="0" style="font-size: 11px; line-height: 1.6;">
            @if ($signature['issuer_name'] ?? null)
                <tr><td>{{ __('pdf_issued_by') }}</td></tr>
                <tr><td><strong>{{ $signature['issuer_name'] }}</strong></td></tr>
            @endif
            @if ($invoice->sign_document && ($signature['image'] ?? null))
                <tr><td style="padding-top: 12px;">{{ __('pdf_signature') }}</td></tr>
                <tr><td style="padding-top: 6px;">
                    <img src="{{ $signature['image'] }}" alt="{{ __('pdf_signature') }}"
                         style="max-width: 125px; max-height: 65px;">
                </td></tr>
            @endif
        </table>
    </div>
@endif
