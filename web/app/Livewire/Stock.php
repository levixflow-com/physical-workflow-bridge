<?php

namespace App\Livewire;

use App\Models\Bin;
use App\Models\Device;
use App\Models\StockMovement;
use App\Workflow\PackingBench;
use App\Workflow\ShiftSummary;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Stock')]
class Stock extends Component
{
    public const REASONS = ['pick_button' => 'Picked, button', 'pick_scan' => 'Picked, scan', 'count' => 'Bin reported empty', 'restock' => 'Restocked'];

    public function restock(int $id, PackingBench $bench): void
    {
        $bench->restock(Device::active()->firstOrFail(), Bin::findOrFail($id), 10, 'operator');
    }

    public function render(ShiftSummary $summary)
    {
        return view('livewire.stock', [
            'rows' => $summary->byBin(),
            'movements' => StockMovement::latest('id')->limit(12)->get(),
            'bins' => Bin::pluck('code', 'id'),
        ]);
    }
}
