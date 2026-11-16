@if ($paymentQrImage ?? null)
    <div style="clear: both; margin: 14px 30px 0; page-break-inside: avoid;">
        <table cellpadding="0" cellspacing="0">
            <tr>
                <td style="vertical-align: middle;">
                    <img src="{{ $paymentQrImage }}" alt="{{ __('pdf_qr_payment') }}"
                         style="display: block; width: 34mm; height: 34mm;">
                </td>
                <td style="vertical-align: middle; padding-left: 10px; font-size: 11px; line-height: 1.6;">
                    <strong>{{ __('pdf_qr_payment') }}</strong><br>
                    IBAN {{ $invoice->payment_qr['iban'] }}<br>
                    @if ($invoice->payment_qr['variable_symbol'] !== null)
                        {{ __('pdf_qr_variable_symbol') }} {{ $invoice->payment_qr['variable_symbol'] }}
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endif
