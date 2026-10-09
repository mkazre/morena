@php($brand = \App\Models\Setting::current())
@isset($heading){{ $heading }}

@endisset
{!! $body ?? '' !!}
@isset($actionUrl)

{{ $actionText ?? 'Open' }}: {!! $actionUrl !!}
@endisset
@isset($note)

{!! $note !!}
@endisset

—
{{ $brand->company_name }} · People · Property · Passion
