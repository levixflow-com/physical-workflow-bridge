<?php

namespace App\Livewire;

use App\Models\Bin;
use App\Models\Device;
use App\Models\Event;
use App\Models\Order;
use App\Models\SimulatorAction;
use App\Workflow\OrderBook;
use App\Workflow\PackingBench;
use App\Workflow\ParcelPhotos;
use App\Workflow\ShiftSummary;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Bench')]
class Bench extends Component
{
    public string $notice = '';

    // The parcel that just left and still has no picture: the browser reads it and takes one.
    public ?int $photoFor = null;

    public function scan(string $code, PackingBench $bench): void
    {
        $code = strtoupper(trim($code));

        if ($code !== '' && $device = Device::active()->first()) {
            $this->notice = (string) $bench->scan($device, $code, 'operator');
        }
    }

    public function closeParcel(PackingBench $bench): void
    {
        $this->notice = '';
        $bench->closeParcel(Device::active()->firstOrFail(), 'operator');
    }

    public function reportEmpty(PackingBench $bench): void
    {
        $this->notice = '';
        $bench->reportEmpty(Device::active()->firstOrFail(), 'operator');
    }

    public function resume(int $id, PackingBench $bench): void
    {
        $this->notice = (string) $bench->resume(Device::active()->firstOrFail(), Order::where('status', 'on_hold')->findOrFail($id));
    }

    public function restockFor(int $id, PackingBench $bench): void
    {
        $bench->restockShort(Device::active()->firstOrFail(), Order::findOrFail($id), 'operator');
    }

    public function newOrder(OrderBook $orders): void
    {
        $orders->demo();
    }

    public function savePhoto(int $id, string $dataUrl, ParcelPhotos $photos): void
    {
        $photos->store(Order::where('status', 'shipped')->findOrFail($id), $dataUrl);
        $this->photoFor = null;
    }

    public function simulate(string $action, ?int $number = null): void
    {
        $device = Device::active()->first();
        // The bridge replays these rows: one it cannot run would stop it at every restart.
        $valid = in_array($action, ['disconnect', 'reconnect'], true)
            || (in_array($action, ['press', 'bounce'], true) && $number !== null);

        if ($valid && $device?->isSimulated()) {
            SimulatorAction::create(['device_id' => $device->id, 'action' => $action, 'number' => $number]);
        }
    }

    public function toggleShift(): void
    {
        $device = Device::active()->first();

        if ($device?->isSimulated()) {
            $device->update(['auto_run' => ! $device->auto_run]);
        }
    }

    public function render(ShiftSummary $summary)
    {
        $device = Device::active()->first();
        $channels = $device?->channels()->with('latestCommand')->get()->keyBy(fn ($c) => $c->role ?? "free{$c->id}") ?? collect();
        $order = Order::active()?->load('lines.bin');

        $this->photoFor = Order::where('status', 'shipped')->whereNull('photo_path')->where('shipped_at', '>', now()->subSeconds(15))->latest('shipped_at')->value('id');

        return view('livewire.bench', [
            'device' => $device,
            'known' => $device?->health() === 'live',
            'order' => $order,
            'line' => $order?->status === 'picking' ? $order->currentLine() : null,
            'bins' => Bin::orderBy('position')->get(),
            'channels' => $channels,
            'queued' => Order::where('status', 'queued')->count(),
            'held' => Order::with('lines.bin')->where('status', 'on_hold')->orderBy('id')->get(),
            'lastShipped' => Order::where('status', 'shipped')->latest('shipped_at')->first(),
            'today' => $summary->today(),
            'feed' => Event::where('device_id', $device?->id)
                ->whereIn('type', Event::BENCH_FEED)
                ->latest('id')
                ->limit(9)
                ->get(),
        ]);
    }
}
