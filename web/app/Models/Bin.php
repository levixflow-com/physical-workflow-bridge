<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Bin extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    // Units promised to open orders but still in the bin.
    public function reserved(): int
    {
        return (int) OrderLine::where('bin_id', $this->id)
            ->whereHas('order', fn ($q) => $q->whereIn('status', Order::OPEN))
            ->selectRaw('coalesce(sum(qty - picked_qty), 0) as units')
            ->value('units');
    }

    public function move(int $delta, string $reason, ?int $orderId = null): void
    {
        $this->increment('stock', $delta);

        StockMovement::create([
            'bin_id' => $this->id,
            'order_id' => $orderId,
            'delta' => $delta,
            'stock_after' => $this->stock,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }

    public function label(): string
    {
        return "bin {$this->code}";
    }
}
