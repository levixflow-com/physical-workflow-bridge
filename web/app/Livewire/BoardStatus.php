<?php

namespace App\Livewire;

use App\Models\Device;
use Livewire\Component;

// The strip under the navigation: on every page, so stale data can never pass for live data.
class BoardStatus extends Component
{
    public function render()
    {
        return view('livewire.board-status', ['device' => Device::active()->first()]);
    }
}
