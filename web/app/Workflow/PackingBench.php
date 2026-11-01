<?php

namespace App\Workflow;

use App\Device\RelayCommands;
use App\Models\Bin;
use App\Models\Channel;
use App\Models\Device;
use App\Models\Event;
use App\Models\Order;
use App\Models\OrderLine;
use Illuminate\Support\Facades\DB;

// The business rules of the bench. Everything physical arrives here as a role,
// never as a channel number: the mapping lives on the Board page.
class PackingBench
{
    public function __construct(private RelayCommands $commands) {}

    public function press(Channel $input, Event $press): void
    {
        $device = $input->device;

        match (true) {
            preg_match('/^bin_(\d)$/', (string) $input->role, $m) === 1 => $this->binPressed($device, (int) $m[1], $press),
            $input->role === 'report_empty' => $this->reportEmpty($device, 'device'),
            $input->role === 'parcel_ready' => $this->closeParcel($device, 'device'),
            default => null,
        };
    }

    // The button confirms a human action on the whole line, like a real pick-to-light key.
    private function binPressed(Device $device, int $position, Event $press): void
    {
        $order = Order::where('status', 'picking')->first();
        $line = $order?->currentLine();
        $pressed = Bin::where('position', $position)->first();

        if ($line === null) {
            return;
        }

        $press->update(['order_id' => $order->id]);

        if ($pressed?->id !== $line->bin_id) {
            $this->refuse($device, 'wrong_bin', 'device', $order, $pressed, "Pressed {$pressed?->label()}, expected {$line->bin->label()}");

            return;
        }

        $this->pick($device, $order, $line, $line->remaining(), 'button', 'device');
    }

    // The scan checks the code on the item, one unit at a time. It does not see what is in the parcel.
    public function scan(Device $device, string $code, string $source): ?string
    {
        $order = Order::where('status', 'picking')->first();
        $line = $order?->currentLine();

        if ($device->health() !== 'live') {
            return 'The board is not reporting, so the lamps cannot be trusted. Scan again when it is back.';
        }
        if ($line === null) {
            return 'Nothing to pick right now.';
        }

        $scanned = Bin::where('sku', $code)->first();

        if ($scanned?->id !== $line->bin_id) {
            $this->refuse($device, 'wrong_scan', $source, $order, $line->bin, "Scanned {$code}, expected {$line->bin->sku}");

            return "Wrong item: {$code}. Take {$line->bin->name} from {$line->bin->label()}.";
        }

        $this->pick($device, $order, $line, 1, 'scan', $source);

        return null;
    }

    private function pick(Device $device, Order $order, OrderLine $line, int $units, string $method, string $source): void
    {
        $picked = DB::transaction(function () use ($order, $line, $units, $method) {
            // Button and scanner arrive through different processes: only the first one counts.
            $won = OrderLine::whereKey($line->id)
                ->where('picked_qty', $line->picked_qty)
                ->update(['picked_qty' => $line->picked_qty + $units]);

            if ($won) {
                $line->bin->move(-$units, "pick_$method", $order->id);
            }

            return $won === 1;
        });

        if (! $picked) {
            return;
        }

        Event::record($device, $source, 'pick', [
            'order_id' => $order->id,
            'bin_id' => $line->bin_id,
            'value' => $method,
            'detail' => "{$units} × {$line->bin->name}",
        ]);

        if ($order->currentLine() === null) {
            $order->update(['status' => 'packing', 'picked_at' => now()]);
            Event::record($device, 'system', 'order_picked', ['order_id' => $order->id, 'detail' => $order->number]);
        }

        $this->syncLamps($device);
    }

    // The system said there were units, the bin says otherwise: this is the data worth keeping.
    public function reportEmpty(Device $device, string $source): void
    {
        $order = Order::where('status', 'picking')->first();
        $line = $order?->currentLine();

        if ($line === null) {
            return;
        }

        $bin = $line->bin;
        $expected = $bin->stock;

        if ($expected !== 0) {
            $bin->move(-$expected, 'count', $order->id);
        }

        Event::record($device, $source, 'stock_difference', [
            'order_id' => $order->id,
            'bin_id' => $bin->id,
            'value' => (string) -$expected,
            'detail' => "Bin {$bin->code} reported empty, system showed {$expected}",
        ]);
        $this->hold($device, $order, "Bin {$bin->code} is empty");
        $this->buzz($device);
    }

    public function closeParcel(Device $device, string $source): void
    {
        $order = Order::where('status', 'packing')->first();

        if ($order === null) {
            return;
        }

        $order->update(['status' => 'shipped', 'shipped_at' => now()]);
        Event::record($device, $source, 'order_shipped', ['order_id' => $order->id, 'detail' => $order->number]);
        $this->advance($device);
    }

    public function resume(Device $device, Order $order): ?string
    {
        $short = $this->shortLine($order);

        if ($short !== null) {
            return "Restock {$short->bin->label()} first: {$short->remaining()} needed, {$short->bin->stock} in stock.";
        }

        $order->update(['status' => 'queued', 'hold_reason' => null]);
        Event::record($device, 'operator', 'order_resumed', ['order_id' => $order->id, 'detail' => $order->number]);
        $this->advance($device);

        return null;
    }

    public function restock(Device $device, Bin $bin, int $units, string $source): void
    {
        $bin->move($units, 'restock');
        Event::record($device, $source, 'restock', ['bin_id' => $bin->id, 'value' => "+$units", 'detail' => "Bin {$bin->code} restocked"]);
    }

    public function restockShort(Device $device, Order $order, string $source): void
    {
        foreach ($order->lines()->with('bin')->get() as $line) {
            if ($line->bin->stock < $line->remaining()) {
                $this->restock($device, $line->bin, 30, $source);
            }
        }
    }

    // Called on every bridge loop.
    public function startNext(Device $device): void
    {
        $order = $device->health() === 'live' && Order::active() === null ? Order::nextQueued() : null;

        if ($order === null) {
            return;
        }

        // Do not send the operator to a bin the system already knows cannot cover the order.
        if ($short = $this->shortLine($order)) {
            $this->hold($device, $order, "Bin {$short->bin->code} is short: {$short->remaining()} needed, {$short->bin->stock} in stock");

            return;
        }

        $order->update([
            'status' => $order->currentLine() ? 'picking' : 'packing',
            'started_at' => $order->started_at ?? now(),
        ]);
        Event::record($device, 'system', 'order_started', ['order_id' => $order->id, 'detail' => $order->number]);
        $this->syncLamps($device);
    }

    private function shortLine(Order $order): ?OrderLine
    {
        return $order->lines()->with('bin')->get()->first(fn (OrderLine $l) => $l->bin->stock < $l->remaining());
    }

    private function advance(Device $device): void
    {
        $this->startNext($device);
        $this->syncLamps($device);
    }

    private function hold(Device $device, Order $order, string $reason): void
    {
        $order->update(['status' => 'on_hold', 'hold_reason' => $reason]);
        Event::record($device, 'system', 'order_held', ['order_id' => $order->id, 'detail' => "{$order->number}: $reason"]);
        $this->advance($device);
    }

    private function refuse(Device $device, string $type, string $source, ?Order $order, ?Bin $bin, string $detail): void
    {
        Event::record($device, $source, $type, ['order_id' => $order?->id, 'bin_id' => $bin?->id, 'detail' => $detail]);
        $this->buzz($device);
    }

    private function buzz(Device $device): void
    {
        $buzzer = $device->channels()->where('role', 'buzzer')->first();

        if ($buzzer !== null && ! $buzzer->latestCommand?->isOpen() && $buzzer->state !== 'ON') {
            $this->commands->request($buzzer, 'pulse', 'workflow', config('bridge.buzzer_pulse_tenths'));
        }
    }

    // Desired lamps come from the order, not from the last thing that happened,
    // so a board that comes back after a loss of power is put right by the same code.
    public function syncLamps(Device $device): void
    {
        if ($device->health() !== 'live') {
            return;
        }

        $order = Order::active();
        $wanted = match ($order?->status) {
            'picking' => 'lamp_'.$order->currentLine()?->bin->position,
            'packing' => 'lamp_ok',
            default => null,
        };

        $lamps = $device->channels()->with('latestCommand')->where('kind', 'relay')
            ->where(fn ($q) => $q->where('role', 'like', 'lamp_%'))->get();

        foreach ($lamps as $lamp) {
            // With a command open we wait: its confirmation calls this method again.
            // A pulse ends by itself on the board, and its end calls this method too.
            if (! $lamp->latestCommand?->isOpen() && ! $lamp->latestCommand?->isPulsing()) {
                $this->commands->request($lamp, $lamp->role === $wanted ? 'on' : 'off', 'workflow');
            }
        }
    }
}
