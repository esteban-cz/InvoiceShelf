@component('mail::layout')
@slot('header')
<tr>
    <td class="header" style="padding: 24px 0 16px; color: #3d4852; font-size: 20px; font-weight: 700;">
        @if($data['company']['logo'])
            <img class="header-logo" src="{{ asset($data['company']['logo']) }}" alt="{{ $data['company']['name'] }}" style="height: 48px; max-width: 200px;">
        @else
            {{ $data['company']['name'] }}
        @endif
    </td>
</tr>
@endslot

<h1 style="margin-bottom: 20px;">@lang('pdf_invoice_label') {{ $data['invoice']['invoice_number'] }}</h1>

{!! $data['body'] !!}

<table width="100%" cellpadding="0" cellspacing="0" role="presentation" style="margin: 24px 0; border-collapse: collapse;">
    <tr>
        <td style="padding: 14px 0; border-top: 1px solid #edeff2; border-bottom: 1px solid #edeff2;">
            <span style="font-size: 12px;">@lang('pdf_invoice_due_date')</span><br>
            <strong>{{ $data['invoice']['formattedDueDate'] ?? '' }}</strong>
        </td>
        <td style="padding: 14px 0; text-align: right; border-top: 1px solid #edeff2; border-bottom: 1px solid #edeff2;">
            <span style="font-size: 12px;">@lang('pdf_amount_due')</span><br>
            <strong>{{ strip_tags($data['invoice']['formattedDueAmount'] ?? '') }}</strong>
        </td>
    </tr>
</table>

@if(!$data['attach']['data'])
@component('mail::button', ['url' => $data['url']])
@lang('mail_view_invoice')
@endcomponent
@else
<p class="sub">@lang('mail_invoice_attached')</p>
@endif

@slot('footer')
@component('mail::footer')
@include('emails.partials.powered-by')
@endcomponent
@endslot
@endcomponent
