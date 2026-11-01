<?php

namespace App\Livewire;

use App\Models\Event;
use App\Models\Order;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
class OrderDetail extends Component
{
    public Order $order;

    public function render()
    {
        return view('livewire.order-detail', [
            'timeline' => Event::where('order_id', $this->order->id)->orderBy('id')->get(),
        ])->title($this->order->number);
    }
}
