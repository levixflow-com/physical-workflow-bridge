<?php

namespace App\Livewire;

use App\Device\RelayCommands;
use App\Models\Channel;
use App\Models\Device;
use App\Models\Event;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.app')]
#[Title('Board')]
class DeviceManager extends Component
{
    public array $form = [];

    public string $topicPrefix = '';

    public function mount(): void
    {
        $this->load();
    }

    private function load(): void
    {
        $device = Device::active()->first();
        $this->topicPrefix = (string) $device?->topic_prefix;
        $this->form = $device?->channels->mapWithKeys(fn (Channel $c) => [$c->id => [
            'label' => $c->label,
            'role' => (string) $c->role,
            'active_level' => (string) $c->active_level,
            'commandable' => $c->commandable,
        ]])->all() ?? [];
    }

    public function activate(int $id): void
    {
        $device = Device::findOrFail($id);

        if ($device->isSimulated() && ! config('bridge.simulator_enabled')) {
            return;
        }

        DB::transaction(function () use ($device) {
            Device::query()->update(['is_active' => false]);
            $device->update(['is_active' => true]);
        });
        $this->load();
    }

    public function save(): void
    {
        $device = Device::active()->firstOrFail();
        $channels = $device->channels->keyBy('id');
        $roles = collect($this->form)->pluck('role')->filter();

        if ($roles->count() !== $roles->unique()->count()) {
            throw ValidationException::withMessages(['form' => 'Each role can sit on one channel only.']);
        }

        foreach ($this->form as $id => $values) {
            $kind = $channels[$id]->kind;
            $allowed = array_keys($kind === 'input' ? Channel::INPUT_ROLES : Channel::RELAY_ROLES);

            if ($values['role'] !== '' && ! in_array($values['role'], $allowed, true)) {
                throw ValidationException::withMessages(['form' => 'Unknown role.']);
            }
            if (trim($values['label']) === '') {
                throw ValidationException::withMessages(['form' => 'Every channel needs a name.']);
            }
            // A relay with a job must be commandable: the role implies the permission.
            $commandable = $kind === 'relay' && ($values['commandable'] || $values['role'] !== '');

            $channels[$id]->update([
                'label' => trim($values['label']),
                'role' => $values['role'] ?: null,
                'active_level' => $kind === 'input' && $values['active_level'] === 'high' ? 'high' : ($kind === 'input' ? 'low' : null),
                'commandable' => $commandable,
            ]);
        }

        if (! $device->isSimulated()) {
            $device->update(['topic_prefix' => rtrim(trim($this->topicPrefix), '/')]);
        }

        $this->load();
        session()->flash('saved', 'Mapping saved.');
    }

    public function pulse(int $id, RelayCommands $commands): void
    {
        $relay = Device::active()->firstOrFail()->channels()->where('kind', 'relay')->findOrFail($id);
        $commands->request($relay, 'pulse', 'operator', 5);
    }

    public function render()
    {
        $device = Device::active()->first();

        return view('livewire.device-manager', [
            'devices' => Device::orderBy('id')->get(),
            'device' => $device,
            'inputs' => $device?->channels()->where('kind', 'input')->get() ?? collect(),
            'relays' => $device?->channels()->with('latestCommand')->where('kind', 'relay')->get() ?? collect(),
            'lastRejection' => $device ? Event::where('device_id', $device->id)->where('type', 'command_rejected')->where('received_at', '>', now()->subSeconds(5))->latest('id')->first() : null,
        ]);
    }
}
