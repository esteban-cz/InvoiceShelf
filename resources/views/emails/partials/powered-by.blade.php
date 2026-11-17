@if ($poweredBy = \App\Support\PoweredBy::clientState())
@lang('powered_by') <a class="footer-link" href="{{ $poweredBy['url'] }}" target="_blank">{{ $poweredBy['name'] }}</a>
@endif
