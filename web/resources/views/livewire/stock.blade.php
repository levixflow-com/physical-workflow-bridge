<div wire:poll.2s class="space-y-4">
    <section class="plate">
        <div class="flex flex-wrap items-center justify-between gap-3 border-b border-rule px-4 py-2.5">
            <div>
                <h1 class="text-xl font-semibold">Stock</h1>
                <p class="text-muted">Available is what the shop may still sell. It reads the same figures at <code>/api/stock</code>.</p>
            </div>
            <a href="{{ route('labels') }}" class="btn">Print barcode labels</a>
        </div>
        <div class="overflow-x-auto">
            <table class="data w-full">
                <thead>
                    <tr><th>Bin</th><th>Item</th><th>SKU</th><th class="!text-right">On hand</th><th class="!text-right">Reserved</th><th class="!text-right">Available</th><th class="!text-right">Mistakes stopped</th><th class="!text-right">Stock differences</th><th></th></tr>
                </thead>
                <tbody>
                    @foreach ($rows as $row)
                        <tr wire:key="b{{ $row['bin']->id }}">
                            <td class="font-semibold">{{ $row['bin']->code }}</td>
                            <td class="whitespace-nowrap">{{ $row['bin']->name }}</td>
                            <td>{{ $row['bin']->sku }}</td>
                            <td class="text-right">{{ $row['bin']->stock }}</td>
                            <td class="text-right">{{ $row['reserved'] }}</td>
                            <td @class(['text-right font-semibold', 'text-alarm' => $row['available'] <= 0])>{{ $row['available'] }}</td>
                            <td class="text-right">{{ $row['mistakes'] }}</td>
                            <td @class(['text-right', 'font-medium text-warn' => $row['differences'] > 0])>{{ $row['differences'] }}</td>
                            <td class="text-right"><button wire:click="restock({{ $row['bin']->id }})" class="btn">Add 10</button></td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <section class="plate">
        <h2 class="plate-title">Latest movements</h2>
        <div class="overflow-x-auto">
            <table class="data w-full">
                <thead><tr><th>Time</th><th>Bin</th><th>Reason</th><th class="!text-right">Change</th><th class="!text-right">Stock after</th></tr></thead>
                <tbody>
                    @forelse ($movements as $movement)
                        <tr wire:key="m{{ $movement->id }}">
                            <td class="whitespace-nowrap">{{ $movement->created_at->format('H:i:s') }}</td>
                            <td>{{ $bins[$movement->bin_id] }}</td>
                            <td class="whitespace-nowrap">{{ \App\Livewire\Stock::REASONS[$movement->reason] ?? $movement->reason }}</td>
                            <td class="text-right">{{ $movement->delta > 0 ? '+' : '' }}{{ $movement->delta }}</td>
                            <td class="text-right">{{ $movement->stock_after }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="text-muted">No movements yet. They appear as soon as an order is picked.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
