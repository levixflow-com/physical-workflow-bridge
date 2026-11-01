<?php

namespace App\Livewire;

use App\Models\Order;
use App\Workflow\OrderBook;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Orders')]
class Orders extends Component
{
    use WithPagination;

    #[Url]
    public string $status = '';

    public function updatedStatus(): void
    {
        $this->validateOnly('status', ['status' => ['nullable', Rule::in(array_keys(Order::STATUS_LABELS))]]);
        $this->resetPage();
    }

    public function newOrder(OrderBook $orders): void
    {
        $orders->demo();
    }

    public function render()
    {
        return view('livewire.orders', [
            'orders' => Order::with('lines.bin')
                ->when($this->status, fn ($q) => $q->where('status', $this->status))
                ->latest('id')
                ->paginate(25),
        ]);
    }
}
