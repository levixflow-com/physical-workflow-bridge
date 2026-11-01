<?php

namespace App\Livewire;

use App\Models\Event;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Layout('layouts.app')]
#[Title('Event log')]
class EventLog extends Component
{
    use WithPagination;

    #[Url]
    public string $group = '';

    #[Url]
    public string $source = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.event-log', [
            'events' => Event::with(['channel', 'order'])
                ->when($this->group, fn ($q) => $q->whereIn('type', Event::GROUPS[$this->group] ?? []))
                ->when($this->source, fn ($q) => $q->where('source', $this->source))
                ->latest('id')
                ->paginate(40),
        ]);
    }
}
