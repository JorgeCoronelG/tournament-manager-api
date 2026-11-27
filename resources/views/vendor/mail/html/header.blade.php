@props(['url' => null])
<tr>
<td class="header">
@if ($url)
<a href="{{ $url }}" style="display: inline-block;">
@endif
@if (trim($slot) === config('app.name'))
{{-- Se arma con APP_URL y no con asset(): dentro de una petición HTTP, asset() usa el host de la petición. --}}
@php($logoBaseUrl = rtrim(config()->string('app.url'), '/').'/img/mail')
<img src="{{ $logoBaseUrl }}/logo-light.png" class="logo logo-light" alt="{{ config('app.name') }}" width="160">
<img src="{{ $logoBaseUrl }}/logo-dark.png" class="logo logo-dark" alt="{{ config('app.name') }}" width="160" style="display:none; max-height:0; overflow:hidden; mso-hide:all;">
@else
{!! $slot !!}
@endif
@if ($url)
</a>
@endif
</td>
</tr>
