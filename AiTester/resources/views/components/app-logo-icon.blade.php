{{--
    The airtight monogram ("a."): reserved for favicon/app-icon/avatar-sized
    slots (brand design system §2.2) — never used beside the full wordmark.
    Colors are fixed per spec, not currentColor-driven; only sizing classes
    passed via $attributes have any effect.
--}}
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 32 32" {{ $attributes }}>
    <rect width="32" height="32" fill="#0a0c10" />
    <text x="16" y="22" font-family="Inter, system-ui, sans-serif" font-weight="700" font-size="18" letter-spacing="-0.36" text-anchor="middle" fill="#e8eaf0">a<tspan fill="#38bdf8">.</tspan></text>
</svg>
