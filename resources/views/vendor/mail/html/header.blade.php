@props(['url' => null])
<tr>
<td class="header">
@if ($url)
<a href="{{ $url }}" style="display: inline-block;">
@endif
@if (trim($slot) === config('app.name'))
{{-- Se arma con APP_URL y no con asset(): dentro de una petición HTTP, asset() usa el host de la petición. --}}
<img src="{{ rtrim(config()->string('app.url'), '/') }}/img/mail/logo-isotipo.png" class="logo" alt="{{ config('app.name') }}" width="160">
@else
{!! $slot !!}
@endif
@if ($url)
</a>
@endif
</td>
</tr>
