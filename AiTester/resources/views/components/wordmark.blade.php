{{--
    The airtight wordmark: the word in the surrounding text color, the
    trailing dot always in accent cyan — never an icon or shape beside it
    (per the brand design system, §2.1/2.4). Font is Inter Bold specifically
    for the logo mark, independent of the app's own display font.
--}}
@props(['class' => 'text-[15px]'])

<span {{ $attributes->class(['font-["Inter"] font-bold tracking-[-0.02em]', $class]) }}>airtight<span class="text-[#38bdf8]">.</span></span>
