@props(['nodes', 'edges', 'emptyLabel' => "Pas encore de graphe d'application", 'height' => 200])

@php
    $layout = \App\Support\AppGraphTreeLayout::build($nodes, $edges);

    $nodeColor = fn (string $kind) => match ($kind) {
        'current' => 'var(--color-at-violet)',
        'healthy' => 'var(--color-verdict-pass)',
        'error' => 'var(--color-verdict-broken)',
        'popup' => 'var(--color-verdict-healed)',
        'modal' => 'var(--color-at-violet-2)',
        default => 'oklch(65% 0.14 200)',
    };
@endphp

@if ($nodes->isEmpty())
    <div class="flex items-center justify-center text-[12px] text-at-muted" style="height: {{ $height }}px">{{ $emptyLabel }}</div>
@else
    <div
        x-data="{
            scale: 1, panX: 0, panY: 0, dragging: false, startX: 0, startY: 0,
            graphWidth: {{ $layout['width'] }}, graphHeight: {{ $layout['height'] }},
            init() { this.$nextTick(() => this.fitToView()); },
            fitToView() {
                const w = this.$refs.viewport.clientWidth;
                const h = this.$refs.viewport.clientHeight;
                const fit = Math.min(w / this.graphWidth, h / this.graphHeight);
                this.scale = Math.min(4, Math.max(0.3, fit));
                this.panX = (w - this.graphWidth * this.scale) / 2;
                this.panY = (h - this.graphHeight * this.scale) / 2;
            },
            zoom(factor) { this.scale = Math.min(4, Math.max(0.3, this.scale * factor)); },
            onWheel(event) { event.preventDefault(); this.zoom(event.deltaY < 0 ? 1.15 : 0.87); },
            startDrag(event) {
                this.dragging = true;
                this.startX = event.clientX - this.panX;
                this.startY = event.clientY - this.panY;
            },
            onDrag(event) {
                if (! this.dragging) return;
                this.panX = event.clientX - this.startX;
                this.panY = event.clientY - this.startY;
            },
            endDrag() { this.dragging = false; },
        }"
        x-ref="viewport"
        class="relative touch-none overflow-hidden rounded-[10px] border border-at-border-2 bg-at-bg/40 select-none"
        style="height: {{ $height }}px"
    >
        <div class="absolute top-2 right-2 z-10 flex gap-1">
            <button type="button" @click="zoom(1.25)" class="flex size-6 cursor-pointer items-center justify-center rounded-md border border-at-border-2 bg-at-surface text-[13px] text-at-muted-2 hover:text-at-text" title="{{ __('Zoomer') }}">+</button>
            <button type="button" @click="zoom(0.8)" class="flex size-6 cursor-pointer items-center justify-center rounded-md border border-at-border-2 bg-at-surface text-[13px] text-at-muted-2 hover:text-at-text" title="{{ __('Dézoomer') }}">−</button>
            <button type="button" @click="fitToView()" class="flex size-6 cursor-pointer items-center justify-center rounded-md border border-at-border-2 bg-at-surface text-[11px] text-at-muted-2 hover:text-at-text" title="{{ __('Réinitialiser la vue') }}">⟲</button>
        </div>

        <svg
            width="{{ $layout['width'] }}" height="{{ $layout['height'] }}"
            viewBox="0 0 {{ $layout['width'] }} {{ $layout['height'] }}"
            class="absolute top-0 left-0"
            :class="dragging ? 'cursor-grabbing' : 'cursor-grab'"
            :style="`transform: translate(${panX}px, ${panY}px) scale(${scale}); transform-origin: 0 0;`"
            @wheel="onWheel"
            @pointerdown="startDrag"
            @pointermove.window="onDrag"
            @pointerup.window="endDrag"
            @pointercancel.window="endDrag"
            @dblclick="fitToView()"
        >
            @foreach ($layout['edges'] as $edge)
                <line
                    x1="{{ $edge['from']['x'] }}" y1="{{ $edge['from']['y'] }}"
                    x2="{{ $edge['to']['x'] }}" y2="{{ $edge['to']['y'] }}"
                    stroke="oklch(78% 0.14 200 / 0.5)" stroke-width="1.5" stroke-dasharray="4 4"
                >
                    <animate attributeName="stroke-dashoffset" from="0" to="-8" dur="1s" repeatCount="indefinite"></animate>
                </line>
            @endforeach
            @foreach ($layout['nodes'] as $item)
                @php $node = $item['node']; @endphp
                <g
                    @if ($node->screenshot_path)
                        onclick="window.open('{{ Storage::disk('public')->url($node->screenshot_path) }}', '_blank')"
                        style="cursor: pointer"
                    @endif
                >
                    <title>{{ $node->url ?? $node->label }}{{ $node->screenshot_path ? ' — '.__('cliquer pour voir la capture') : '' }}</title>
                    <circle cx="{{ $item['x'] }}" cy="{{ $item['y'] }}" r="{{ $node->kind === 'current' ? 7 : 6 }}" fill="{{ $nodeColor($node->kind) }}">
                        @if ($node->kind === 'current')
                            <animate attributeName="opacity" values="1;0.6;1" dur="2.4s" repeatCount="indefinite"></animate>
                        @endif
                    </circle>
                    <text x="{{ $item['x'] }}" y="{{ $item['y'] - 12 }}" font-size="9" fill="oklch(65% 0.02 268)" text-anchor="middle" font-family="JetBrains Mono">{{ \Illuminate\Support\Str::limit($node->label, 28) }}</text>
                </g>
            @endforeach
        </svg>
    </div>
@endif
