<?php

use App\Models\Bin;
use App\Workflow\OrderBook;
use App\Workflow\ShiftSummary;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\Rule;

// Same field names as a WooCommerce order webhook, so a real shop could point here.
Route::post('/orders', function (Request $request, OrderBook $orders) {
    $data = $request->validate([
        'number' => ['required', 'string', 'max:40', 'unique:orders,number'],
        'billing.first_name' => ['nullable', 'string', 'max:80'],
        'line_items' => ['required', 'array', 'min:1'],
        'line_items.*.sku' => ['required', Rule::exists('bins', 'sku')],
        'line_items.*.quantity' => ['required', 'integer', 'min:1', 'max:99'],
    ]);

    $bins = Bin::pluck('id', 'sku');
    $lines = collect($data['line_items'])->groupBy(fn ($item) => $bins[$item['sku']])->map->sum('quantity')->all();
    $order = $orders->create($data['number'], $data['billing']['first_name'] ?? 'Shop customer', $lines);

    return response()->json(['id' => $order->id, 'status' => $order->status], 201);
});

// What a shop may still sell: stock in the bins minus what open orders hold back.
Route::get('/stock', fn (ShiftSummary $summary) => collect($summary->byBin())->map(fn ($row) => [
    'sku' => $row['bin']->sku,
    'name' => $row['bin']->name,
    'on_hand' => $row['bin']->stock,
    'reserved' => $row['reserved'],
    'available' => max(0, $row['available']),
]));
