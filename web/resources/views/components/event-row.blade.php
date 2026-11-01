@props(['event'])

<tr>
    <td class="whitespace-nowrap">{{ $event->received_at->format('H:i:s.v') }}</td>
    <td @class(['whitespace-nowrap', 'font-medium text-alarm' => $event->isException()])>{{ $event->label() }}</td>
    <td class="whitespace-nowrap">
        @if ($event->order)
            <a href="{{ route('orders.show', $event->order) }}" wire:navigate class="underline decoration-rule">{{ $event->order->number }}</a>
        @endif
    </td>
    <td class="whitespace-nowrap">{{ $event->channel?->name() }}</td>
    <td>{{ $event->value }}</td>
    <td class="text-muted">{{ $event->detail }}</td>
    <td class="whitespace-nowrap">{{ \App\Models\Event::SOURCES[$event->source] ?? $event->source }}</td>
</tr>
