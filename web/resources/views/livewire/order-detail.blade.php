<div wire:poll.2s class="space-y-4">
    <div>
        <a href="{{ route('orders') }}" wire:navigate class="text-muted underline">All orders</a>
        <h1 class="text-3xl font-semibold">{{ $order->number }}</h1>
        <p class="text-muted">
            {{ $order->customer }}, {{ strtolower(\App\Models\Order::STATUS_LABELS[$order->status]) }}@if ($order->seconds() !== null), {{ \App\Models\Order::clock($order->seconds()) }} on the bench @endif
        </p>
        @if ($order->hold_reason) <p class="font-medium text-warn">{{ $order->hold_reason }}</p> @endif
    </div>

    <div class="grid gap-4 xl:grid-cols-3">
        <div class="space-y-4">
            <section class="plate">
                <h2 class="plate-title">Items</h2>
                <ul>
                    @foreach ($order->lines()->with('bin')->get() as $line)
                        <li class="flex justify-between gap-4 border-b border-ground px-4 py-1.5 last:border-0">
                            <span>{{ $line->bin->name }}, bin {{ $line->bin->code }}</span>
                            <span>{{ $line->picked_qty }} of {{ $line->qty }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section class="plate">
                <h2 class="plate-title">Parcel photo</h2>
                @if ($order->photo_path)
                    <img src="{{ route('orders.photo', $order) }}" alt="Parcel {{ $order->number }}" class="w-full">
                @else
                    <p class="px-4 py-2 text-muted">No photo: the camera was off when this parcel left.</p>
                @endif
            </section>
        </div>

        <section class="plate xl:col-span-2">
            <h2 class="plate-title">What happened</h2>
            <ul>
                @forelse ($timeline as $event)
                    <li class="grid grid-cols-[6.5rem_minmax(0,1fr)] gap-x-3 border-b border-ground px-4 py-1.5 last:border-0 sm:grid-cols-[6.5rem_11rem_minmax(0,1fr)_6rem]">
                        <span class="text-muted">{{ $event->received_at->format('H:i:s.v') }}</span>
                        <span @class(['font-medium', 'text-alarm' => $event->isException()])>{{ $event->label() }}</span>
                        <span class="col-start-2 text-muted sm:col-start-3">{{ $event->detail }}</span>
                        <span class="col-start-2 text-muted sm:col-start-4">{{ \App\Models\Event::SOURCES[$event->source] ?? $event->source }}</span>
                    </li>
                @empty
                    <li class="px-4 py-2 text-muted">Nothing has happened to this order yet.</li>
                @endforelse
            </ul>
        </section>
    </div>
</div>
