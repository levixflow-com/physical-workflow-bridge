<?php

namespace App\Device;

use App\Models\Bin;
use App\Models\Device;
use App\Models\Order;
use App\Workflow\OrderBook;
use App\Workflow\PackingBench;

// A pretend operator for the simulated shift: goes through the bench the way a person
// does, slips up now and then, and uses the same inputs and scan a person would.
class SimulatedOperator
{
    private int $nextAt = 0;

    public function __construct(private PackingBench $bench, private OrderBook $orders) {}

    public function act(Device $device, SimulatedAdapter $board): void
    {
        if ($this->nowMs() < $this->nextAt || ! $device->fresh()->auto_run || $device->health() !== 'live') {
            return;
        }

        $this->nextAt = $this->nowMs() + mt_rand(1800, 3600);

        if (Order::where('status', 'queued')->count() < 2) {
            $this->orders->demo();
        }

        // A held order waits a little, then somebody goes to deal with it.
        if (mt_rand(1, 3) === 1 && $this->clearHold($device)) {
            return;
        }

        $order = Order::active();

        match ($order?->status) {
            'picking' => $this->pick($device, $board, $order),
            'packing' => $board->pressRole('parcel_ready'),
            default => null,
        };
    }

    private function pick(Device $device, SimulatedAdapter $board, Order $order): void
    {
        $line = $order->currentLine();

        if ($line === null) {
            return;
        }

        $roll = mt_rand(1, 100);

        match (true) {
            $roll <= 6 => $board->pressRole('bin_'.Bin::where('id', '!=', $line->bin_id)->inRandomOrder()->value('position')),
            $roll <= 12 => $this->bench->scan($device, Bin::where('id', '!=', $line->bin_id)->inRandomOrder()->value('sku'), 'simulator'),
            $roll <= 15 => $board->pressRole('report_empty'),
            $roll <= 60 => $this->bench->scan($device, $line->bin->sku, 'simulator'),
            default => $board->pressRole('bin_'.$line->bin->position),
        };
    }

    private function clearHold(Device $device): bool
    {
        $order = Order::where('status', 'on_hold')->orderBy('id')->first();

        if ($order === null) {
            return false;
        }

        $this->bench->restockShort($device, $order, 'simulator');
        $this->bench->resume($device, $order);

        return true;
    }

    private function nowMs(): int
    {
        return now()->getTimestampMs();
    }
}
