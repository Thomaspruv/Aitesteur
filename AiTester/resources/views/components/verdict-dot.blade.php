@props(['verdict'])

<div {{ $attributes->merge(['class' => 'size-[7px] rounded-full shrink-0 bg-verdict-'.$verdict->slug()]) }}
    style="box-shadow: 0 0 6px var(--color-verdict-{{ $verdict->slug() }})"
></div>
