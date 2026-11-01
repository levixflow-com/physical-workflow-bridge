<?php

namespace App\Workflow;

use App\Models\Bin;
use App\Models\Event;
use App\Models\Order;

// Every figure here is counted from recorded orders and events. Nothing is estimated.
class ShiftSummary
{
    public const MISTAKES = ['wrong_bin', 'wrong_scan'];

    public function today(): array
    {
        $shipped = Order::whereDate('shipped_at', today())->get();
        $events = Event::where('received_at', '>=', today())->whereIn('type', [...self::MISTAKES, 'stock_difference'])->get();

        return [
            'shipped' => $shipped->count(),
            'median' => $shipped->map->seconds()->filter(fn ($s) => $s !== null)->median(),
            'mistakes' => $events->whereIn('type', self::MISTAKES)->count(),
            'differences' => $events->where('type', 'stock_difference')->count(),
        ];
    }

    // Per bin, to show which article causes the trouble.
    public function byBin(): array
    {
        $mistakes = Event::whereIn('type', self::MISTAKES)->whereNotNull('bin_id')->selectRaw('bin_id, count(*) as total')->groupBy('bin_id')->pluck('total', 'bin_id');
        $differences = Event::where('type', 'stock_difference')->selectRaw('bin_id, count(*) as total')->groupBy('bin_id')->pluck('total', 'bin_id');

        return Bin::orderBy('position')->get()->map(fn (Bin $bin) => [
            'bin' => $bin,
            'reserved' => $reserved = $bin->reserved(),
            'available' => $bin->stock - $reserved,
            'mistakes' => $mistakes[$bin->id] ?? 0,
            'differences' => $differences[$bin->id] ?? 0,
        ])->all();
    }
}
