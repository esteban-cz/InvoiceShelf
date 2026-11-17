@if ($invoice->payment_qr && ! $invoice->isCreditNote())
    <div class="pdf-card pdf-card--payment">
        <table width="100%" cellpadding="0" cellspacing="0" style="border-collapse: collapse;">
            <tr>
                @if ($paymentQrImage ?? null)
                    <td style="width: 34mm; vertical-align: middle; padding-right: 12px;">
                        <img src="{{ $paymentQrImage }}" alt="{{ __('pdf_qr_payment') }}"
                             style="display: block; width: 34mm; height: 34mm;">
                    </td>
                @endif
                <td style="vertical-align: middle; font-size: 10px; line-height: 1.65;">
                    <strong style="font-size: 12px;">{{ __(($paymentQrImage ?? null) ? 'pdf_qr_payment' : 'pdf_payment_details') }}</strong><br>
                    @if (($paymentQrImage ?? null) && (int) $invoice->due_amount === 0)
                        <strong>{{ __('pdf_invoice_paid') }}</strong><br>
                    @endif
                    @if ($invoice->payment_qr['bank_name'] ?? null)
                        {{ $invoice->payment_qr['bank_name'] }}<br>
                    @endif
                    @if ($paymentAccount ?? null)
                        {{ __('pdf_bank_account') }} <strong>{{ $paymentAccount }}</strong><br>
                    @endif
                    <span style="font-size: 9px;">IBAN {{ $invoice->payment_qr['iban'] }}</span><br>
                    @if (isset($invoice->payment_qr['variable_symbol']))
                        {{ __('pdf_qr_variable_symbol') }} <strong>{{ $invoice->payment_qr['variable_symbol'] }}</strong>
                    @endif
                </td>
            </tr>
        </table>
    </div>
@endif
