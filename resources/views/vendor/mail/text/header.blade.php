@props(['url' => null])
{{ $slot }}@if ($url): {{ $url }}@endif

