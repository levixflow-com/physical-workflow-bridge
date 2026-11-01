<?php

namespace App\Workflow;

use App\Models\Bin;
use App\Models\Order;
use Illuminate\Support\Facades\DB;

// Orders enter here, from the webhook or as synthetic demo orders.
class OrderBook
{
    public function create(string $number, string $customer, array $lines): Order
    {
        return DB::transaction(function () use ($number, $customer, $lines) {
            $order = Order::create(compact('number', 'customer'));

            foreach ($lines as $binId => $qty) {
                $order->lines()->create(['bin_id' => $binId, 'qty' => $qty]);
            }

            return $order;
        });
    }

    public function demo(): Order
    {
        $next = 1001 + Order::count();

        // The webhook takes any number, so the next one may already be used.
        while (Order::where('number', "SO-$next")->exists()) {
            $next++;
        }

        $bins = Bin::inRandomOrder()->limit(mt_rand(1, 3))->pluck('id');

        return $this->create("SO-$next", "Demo customer $next", $bins->mapWithKeys(fn ($id) => [$id => mt_rand(1, 3)])->all());
    }
}
