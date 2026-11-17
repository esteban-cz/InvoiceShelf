@php($signature = $invoice->document_signature)
@if ($invoice->payment_qr || $signature)
    <div style="margin: 20px 30px 0; page-break-inside: avoid;">
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
            <tr>
                <td style="width: 70%; vertical-align: top;">
                    @include('app.pdf.invoice.partials.payment-qr')
                </td>
                <td style="width: 30%; vertical-align: top; padding-left: 20px; font-size: 11px; line-height: 1.6;">
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
                </td>
            </tr>
        </table>
    </div>
@endif
