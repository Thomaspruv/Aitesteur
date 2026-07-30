@props(['verdict', 'suffix' => null])

<span {{ $attributes->merge(['class' => 'font-data font-semibold text-verdict-'.$verdict->slug()]) }}>
    {{ $verdict->label() }}{{ $suffix ? ' — '.$suffix : '' }}
</span>
