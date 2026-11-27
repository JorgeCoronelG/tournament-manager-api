<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<title>{{ config('app.name') }}</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0" />
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
@media only screen and (max-width: 600px) {
.inner-body {
width: 100% !important;
}

.footer {
width: 100% !important;
}
}

@media only screen and (max-width: 500px) {
.button {
width: 100% !important;
}
}

/* Modo oscuro. Va aquí y no en el tema porque al incrustar el CSS del tema se descartan los @media. */
@media (prefers-color-scheme: dark) {
body,
.wrapper,
.content,
.body {
background-color: #18181b !important;
}

.body {
border-color: #18181b !important;
}

.inner-body {
background-color: #27272a !important;
border-color: #3f3f46 !important;
}

body,
p,
h1,
h2,
h3,
li,
.header a,
.table td,
.table th {
color: #e4e4e7 !important;
}

.inner-body a {
color: #599B6A !important;
}

.footer p,
.footer a {
color: #a1a1aa !important;
}

.subcopy,
.table th {
border-color: #3f3f46 !important;
}

.panel {
border-left-color: #599B6A !important;
}

.panel-content {
background-color: #3f3f46 !important;
}

.inner-body .button {
-webkit-text-fill-color: #ffffff !important;
color: #ffffff !important;
}

.inner-body .button-primary,
.inner-body .button-blue {
-webkit-text-fill-color: #000000 !important;
background-color: #599B6A !important;
border-color: #599B6A !important;
color: #000000 !important;
}
}
</style>
{!! $head ?? '' !!}
</head>
<body>

<table class="wrapper" width="100%" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td align="center">
<table class="content" width="100%" cellpadding="0" cellspacing="0" role="presentation">
{!! $header ?? '' !!}

<!-- Email Body -->
<tr>
<td class="body" width="100%" cellpadding="0" cellspacing="0" style="border: hidden !important;">
<table class="inner-body" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<!-- Body content -->
<tr>
<td class="content-cell">
{!! Illuminate\Mail\Markdown::parse($slot) !!}

{!! $subcopy ?? '' !!}
</td>
</tr>
</table>
</td>
</tr>

{!! $footer ?? '' !!}
</table>
</td>
</tr>
</table>
</body>
</html>
