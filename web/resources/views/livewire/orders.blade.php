<div wire:poll.2s class="plate">
    <div class="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-4 py-2.5">
        <h1 class="text-xl font-semibold">Orders</h1>
        <div class="flex flex-wrap gap-2">
            <select wire:model.live="status" aria-label="Status" class="field">
                <option value="">All statuses</option>
                @foreach (\App\Models\Order::STATUS_LABELS as $value => $label)
                    <option value="{{ $value }}">{{ $label }}</option>
                @endforeach
            </select>
            <button wire:click="newOrder" class="btn">Add a test order</button>
        </div>
    </div>
    <div class="overflow-x-auto">
        <table class="data w-full">
            <thead>
                <tr><th>Order</th><th>Customer</th><th>Items</th><th>Status</th><th>Shipped</th><th>Time</th><th>Photo</th></tr>
            </thead>
            <tbody>
                @forelse ($orders as $order)
                    <tr wire:key="o{{ $order->id }}">
                        <td class="whitespace-nowrap"><a href="{{ route('orders.show', $order) }}" wire:navigate class="font-medium underline decoration-rule">{{ $order->number }}</a></td>
                        <td class="whitespace-nowrap">{{ $order->customer }}</td>
                        <td>{{ $order->lines->map(fn ($l) => $l->qty.' × '.$l->bin->name)->join(', ') }}</td>
                        <td class="whitespace-nowrap">
                            <span @class(['font-medium text-warn' => $order->status === 'on_hold'])>{{ \App\Models\Order::STATUS_LABELS[$order->status] }}</span>
                            @if ($order->hold_reason) <span class="block text-warn">{{ $order->hold_reason }}</span> @endif
                        </td>
                        <td class="whitespace-nowrap">{{ $order->shipped_at?->format('H:i:s') }}</td>
                        <td class="whitespace-nowrap">{{ $order->seconds() !== null ? \App\Models\Order::clock($order->seconds()) : '' }}</td>
                        <td>{{ $order->photo_path ? 'Yes' : '' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="7" class="text-muted">No orders with this status.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    @if ($orders->hasPages())
        <div class="border-t border-rule px-4 py-3">{{ $orders->links() }}</div>
    @endif
</div>
