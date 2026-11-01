<div wire:poll.2s class="plate">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-4 py-2.5">
        <div>
            <h1 class="text-xl font-semibold">Event log</h1>
            <p class="text-muted">Time of arrival at the bridge: the board sends neither a clock nor a sequence number.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <select wire:model.live="group" aria-label="Type" class="field">
                <option value="">All types</option>
                @foreach (array_keys(\App\Models\Event::GROUPS) as $name)
                    <option>{{ $name }}</option>
                @endforeach
            </select>
            <select wire:model.live="source" aria-label="Source" class="field">
                <option value="">All sources</option>
                @foreach (\App\Models\Event::SOURCES as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="overflow-x-auto">
        <table class="data w-full">
            <thead>
                <tr><th>Time</th><th>Event</th><th>Order</th><th>Channel</th><th>Value</th><th>Detail</th><th>Source</th></tr>
            </thead>
            <tbody>
                @forelse ($events as $event)
                    <x-event-row :event="$event" wire:key="event-{{ $event->id }}" />
                @empty
                    <tr><td colspan="7" class="text-muted">No events with these filters.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($events->hasPages())
        <div class="border-t border-rule px-4 py-3">{{ $events->links() }}</div>
    @endif
</div>
