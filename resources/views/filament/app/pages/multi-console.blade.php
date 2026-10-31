<x-filament-panels::page>
    @php
        $consoleServers = $this->getConsoleServers();
        $gridRows = $this->getGridRows($consoleServers->count());
    @endphp

    @if ($consoleServers->isEmpty())
        <x-filament::empty-state
            :icon="\App\Enums\TablerIcon::LayoutGrid"
            :heading="trans('server/dashboard.multi_console.empty')"
            :description="trans('server/dashboard.multi_console.empty_description')"
        >
            <x-slot name="footer">
                {{ $this->chooseServersAction }}
            </x-slot>
        </x-filament::empty-state>
    @else
        {{-- wire:ignore.self keeps the dragged track sizes when the page re-renders. --}}
        <div
            class="multi-console-grid"
            style="--multi-console-columns: {{ $this->columns }}; --multi-console-rows: {{ $gridRows }};"
            data-columns="{{ $this->columns }}"
            data-rows="{{ $gridRows }}"
            wire:ignore.self
        >
            @foreach ($consoleServers as $server)
                <div class="multi-console-cell" data-uuid="{{ $server->uuid }}" wire:key="multi-console-cell-{{ $server->uuid }}">
                    @livewire(\App\Filament\Server\Widgets\ServerConsole::class, ['server' => $server, 'user' => user(), 'compact' => true], key('multi-console-' . $server->uuid))
                </div>
            @endforeach

            <div class="multi-console-gutters" wire:ignore></div>
        </div>
    @endif

    <x-filament-actions::modals />

    @script
    <script>
        const grid = $wire.$el.querySelector('.multi-console-grid');

        if (grid) {
            const columns = Number(grid.dataset.columns);
            const rows = Number(grid.dataset.rows);
            const gutters = grid.querySelector('.multi-console-gutters');
            const storageKey = `pelican:multi-console:sizes:${columns}x${rows}`;
            const MIN_TRACK = 160;

            // Track sizes are kept as fr weights, so the layout still fills the
            // screen after the window is resized.
            const sizes = { columns: Array(columns).fill(1), rows: Array(rows).fill(1) };

            try {
                const stored = JSON.parse(localStorage.getItem(storageKey) ?? 'null');

                for (const axis of ['columns', 'rows']) {
                    if (Array.isArray(stored?.[axis]) && stored[axis].length === sizes[axis].length && stored[axis].every((size) => Number.isFinite(size) && size > 0)) {
                        sizes[axis] = stored[axis];
                    }
                }
            } catch {}

            const saveSizes = () => {
                try {
                    localStorage.setItem(storageKey, JSON.stringify(sizes));
                } catch {}
            };

            const applySizes = () => {
                grid.style.setProperty('--multi-console-template-columns', sizes.columns.map((size) => `minmax(0, ${size}fr)`).join(' '));
                grid.style.setProperty('--multi-console-template-rows', sizes.rows.map((size) => `minmax(0, ${size}fr)`).join(' '));
            };

            // The resolved track sizes in px, read back from the browser.
            const trackSizes = (axis) => getComputedStyle(grid)[axis === 'columns' ? 'gridTemplateColumns' : 'gridTemplateRows']
                .split(' ')
                .map(parseFloat)
                .filter(Number.isFinite);

            const stacked = () => trackSizes('columns').length < columns;

            // One handle in the gap after every track but the last, along both axes.
            // Existing handles are moved, not rebuilt: replacing the one being
            // dragged would drop its pointer capture mid-drag.
            const placeGutters = () => {
                const wanted = new Set();

                if (!stacked()) {
                    const gap = parseFloat(getComputedStyle(grid).columnGap) || 0;

                    for (const axis of ['columns', 'rows']) {
                        let offset = 0;

                        trackSizes(axis).slice(0, -1).forEach((size, index) => {
                            offset += size;

                            const id = `${axis}-${index}`;
                            let gutter = gutters.querySelector(`[data-id="${id}"]`);

                            if (!gutter) {
                                gutter = document.createElement('div');
                                gutter.className = `multi-console-gutter multi-console-gutter--${axis}`;
                                gutter.dataset.id = id;
                                gutter.dataset.axis = axis;
                                gutter.dataset.index = index;
                                gutters.append(gutter);
                            }

                            gutter.style[axis === 'columns' ? 'left' : 'top'] = `${offset}px`;
                            gutter.style[axis === 'columns' ? 'width' : 'height'] = `${gap}px`;
                            wanted.add(id);

                            offset += gap;
                        });
                    }
                }

                gutters.querySelectorAll('.multi-console-gutter').forEach((gutter) => wanted.has(gutter.dataset.id) || gutter.remove());
            };

            gutters.addEventListener('pointerdown', (event) => {
                const gutter = event.target.closest('.multi-console-gutter');

                if (!gutter) {
                    return;
                }

                event.preventDefault();
                gutter.setPointerCapture(event.pointerId);
                gutter.classList.add('is-dragging');

                const axis = gutter.dataset.axis;
                const index = Number(gutter.dataset.index);
                const start = axis === 'columns' ? event.clientX : event.clientY;
                const pixels = trackSizes(axis);
                const pair = pixels[index] + pixels[index + 1];

                const move = (moveEvent) => {
                    const delta = (axis === 'columns' ? moveEvent.clientX : moveEvent.clientY) - start;
                    const before = Math.min(Math.max(pixels[index] + delta, MIN_TRACK), pair - MIN_TRACK);

                    // Only the two tracks beside the gutter change; px work as fr weights.
                    sizes[axis] = pixels.map((size, i) => i === index ? before : i === index + 1 ? pair - before : size);
                    applySizes();
                    placeGutters();
                };

                const end = () => {
                    gutter.removeEventListener('pointermove', move);
                    saveSizes();
                    placeGutters();
                };

                gutter.addEventListener('pointermove', move);
                gutter.addEventListener('pointerup', end, { once: true });
                gutter.addEventListener('pointercancel', end, { once: true });
            });

            // Double-clicking a gutter evens out all the panes again.
            gutters.addEventListener('dblclick', (event) => {
                if (!event.target.closest('.multi-console-gutter')) {
                    return;
                }

                sizes.columns = Array(columns).fill(1);
                sizes.rows = Array(rows).fill(1);
                applySizes();
                saveSizes();
                placeGutters();
            });

            // Drag a pane by its header and drop it on another to swap the two.
            let dragged = null;

            const cellFor = (event) => event.target.closest?.('.multi-console-cell');

            grid.addEventListener('dragstart', (event) => {
                if (!event.target.closest?.('.console-pane-header')) {
                    return;
                }

                dragged = cellFor(event);
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', dragged.dataset.uuid);
                dragged.classList.add('is-dragged');
            });

            grid.addEventListener('dragover', (event) => {
                const cell = cellFor(event);

                if (!dragged || !cell) {
                    return;
                }

                event.preventDefault();
                event.dataTransfer.dropEffect = 'move';

                grid.querySelectorAll('.multi-console-cell.is-drop-target').forEach((other) => other !== cell && other.classList.remove('is-drop-target'));
                cell.classList.toggle('is-drop-target', cell !== dragged);
            });

            grid.addEventListener('drop', (event) => {
                const cell = cellFor(event);

                if (!dragged || !cell || cell === dragged) {
                    return;
                }

                event.preventDefault();

                // Swap through a placeholder; moving the nodes keeps every console's socket open.
                const placeholder = document.createElement('div');
                cell.replaceWith(placeholder);
                dragged.replaceWith(cell);
                placeholder.replaceWith(dragged);

                $wire.reorder([...grid.querySelectorAll('.multi-console-cell')].map((other) => other.dataset.uuid));
            });

            grid.addEventListener('dragend', () => {
                dragged?.classList.remove('is-dragged');
                grid.querySelectorAll('.is-drop-target').forEach((cell) => cell.classList.remove('is-drop-target'));
                dragged = null;
            });

            applySizes();
            placeGutters();

            new ResizeObserver(placeGutters).observe(grid);
        }
    </script>
    @endscript
</x-filament-panels::page>
