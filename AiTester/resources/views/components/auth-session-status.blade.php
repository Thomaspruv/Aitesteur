@props([
    'status',
])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-verdict-pass']) }}>
        {{ $status }}
    </div>
@endif
