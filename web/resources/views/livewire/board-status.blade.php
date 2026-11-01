<div wire:poll.2s>
    @php
        $health = $device?->health() ?? 'unknown';
        [$title, $text, $style] = match ($health) {
            'live' => ['Reporting', null, 'bg-plate text-muted border-rule'],
            'stale' => ['Not reporting', 'No message for over '.config('bridge.stale_after_s').' seconds. Everything below is the last known state, not the current one.', 'bg-warn-tint text-warn border-warn'],
            'offline' => ['Disconnected', 'The board is offline. Everything below is the last known state, not the current one.', 'bg-alarm-tint text-alarm border-alarm'],
            default => ['Waiting for the first message', 'Nothing received from this board yet.', 'bg-plate text-muted border-rule'],
        };
    @endphp
    <div class="flex flex-wrap items-baseline gap-x-6 border-b px-4 py-1.5 lg:px-6 {{ $style }}">
        <p><span class="font-semibold">{{ $device?->name ?? 'No active board' }}: {{ $title }}</span></p>
        @if ($text) <p>{{ $text }}</p> @endif
        @if ($device?->last_seen_at) <p>Last message {{ $device->last_seen_at->format('H:i:s') }}</p> @endif
        @if ($device?->isSimulated())
            <p class="ml-auto font-medium text-ink">Simulated board and synthetic orders</p>
        @else
            <p class="ml-auto font-medium text-ink">Synthetic orders</p>
        @endif
    </div>
</div>
