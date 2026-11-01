<div class="space-y-4">
    <section class="plate">
        <h1 class="plate-title">Board</h1>
        <div class="px-4 py-3">
            <p class="text-muted">The bench talks to one board at a time.</p>
            <div class="mt-2 flex flex-wrap gap-2">
                @foreach ($devices as $item)
                    @php($off = $item->isSimulated() && ! config('bridge.simulator_enabled'))
                    <button wire:click="activate({{ $item->id }})" @disabled($off) @class(['btn', 'btn-act' => $item->is_active && ! $off])>
                        {{ $item->name }}{{ $off ? ' (turned off)' : '' }}
                    </button>
                @endforeach
            </div>
            @if ($device)
                <dl class="mt-3 grid max-w-2xl gap-x-8 sm:grid-cols-2">
                    <div class="flex justify-between gap-4"><dt class="text-muted">Serial</dt><dd>{{ $device->serial ?? 'Not reported' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Address</dt><dd>{{ $device->host ?? 'Not reported' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Hardware</dt><dd>{{ $device->hw_version ?? 'Not reported' }}</dd></div>
                    <div class="flex justify-between gap-4"><dt class="text-muted">Firmware</dt><dd>{{ $device->sw_version ?? 'Not reported' }}</dd></div>
                </dl>
            @endif
        </div>
    </section>

    @if ($device)
        <form wire:submit="save" class="space-y-4">
            @unless ($device->isSimulated())
                <section class="plate px-4 py-3">
                    <label class="block font-semibold" for="prefix">MQTT topic prefix</label>
                    <input id="prefix" wire:model="topicPrefix" class="field mt-1 w-full max-w-xl">
                </section>
            @endunless

            <div class="grid gap-4 2xl:grid-cols-2">
                <section class="plate">
                    <h2 class="plate-title">Inputs</h2>
                    <div class="overflow-x-auto">
                        <table class="data w-full">
                            <thead><tr><th></th><th>Name</th><th>Job on the bench</th><th>Active level</th><th>State</th></tr></thead>
                            <tbody>
                                @foreach ($inputs as $channel)
                                    <tr wire:key="c{{ $channel->id }}">
                                        <td class="font-medium">I{{ $channel->number }}</td>
                                        <td><input wire:model="form.{{ $channel->id }}.label" aria-label="Name of I{{ $channel->number }}" class="field w-full min-w-40"></td>
                                        <td>
                                            <select wire:model="form.{{ $channel->id }}.role" aria-label="Job of I{{ $channel->number }}" class="field">
                                                <option value="">None</option>
                                                @foreach (\App\Models\Channel::INPUT_ROLES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                            </select>
                                        </td>
                                        <td>
                                            <select wire:model="form.{{ $channel->id }}.active_level" aria-label="Active level of I{{ $channel->number }}" class="field">
                                                <option value="low">Low</option><option value="high">High</option>
                                            </select>
                                        </td>
                                        <td wire:poll.2s>{{ $channel->fresh()->state ?? 'Unknown' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                <section class="plate">
                    <h2 class="plate-title">Relays</h2>
                    <p class="border-b border-rule px-4 py-2 text-muted">Low voltage only. Test sends a 500 ms pulse, and the board returns the relay to rest by itself.</p>
                    @if ($lastRejection)
                        <p class="border-b border-rule bg-alarm-tint px-4 py-2 text-alarm">Command rejected: {{ $lastRejection->detail }}</p>
                    @endif
                    <div class="overflow-x-auto">
                        <table class="data w-full">
                            <thead><tr><th></th><th>Name</th><th>Job on the bench</th><th>Commands</th><th>State</th><th></th></tr></thead>
                            <tbody>
                                @foreach ($relays as $channel)
                                    <tr wire:key="c{{ $channel->id }}">
                                        <td class="font-medium">R{{ $channel->number }}</td>
                                        <td><input wire:model="form.{{ $channel->id }}.label" aria-label="Name of R{{ $channel->number }}" class="field w-full min-w-40"></td>
                                        <td>
                                            <select wire:model="form.{{ $channel->id }}.role" aria-label="Job of R{{ $channel->number }}" class="field">
                                                <option value="">None</option>
                                                @foreach (\App\Models\Channel::RELAY_ROLES as $value => $label)<option value="{{ $value }}">{{ $label }}</option>@endforeach
                                            </select>
                                        </td>
                                        <td><input type="checkbox" wire:model="form.{{ $channel->id }}.commandable" aria-label="R{{ $channel->number }} accepts commands"></td>
                                        <td wire:poll.2s class="whitespace-nowrap">
                                            @php($fresh = $channel->fresh('latestCommand'))
                                            {{ $fresh->state ?? 'Unknown' }}
                                            @if ($fresh->latestCommand?->isOpen())
                                                <span class="block text-act">{{ \App\Models\RelayCommand::STATUS_LABELS[$fresh->latestCommand->status] }}</span>
                                            @elseif (in_array($fresh->latestCommand?->status, ['failed', 'timeout']))
                                                <span class="block text-alarm">{{ \App\Models\RelayCommand::STATUS_LABELS[$fresh->latestCommand->status] }}</span>
                                            @endif
                                        </td>
                                        <td><button type="button" wire:click="pulse({{ $channel->id }})" @disabled(! $channel->commandable || $device->health() !== 'live') class="btn">Test</button></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>
            </div>

            <div class="flex items-center gap-3">
                <button class="btn btn-act">Save mapping</button>
                @if (session('saved')) <p class="text-ok">{{ session('saved') }}</p> @endif
                @error('form') <p class="text-alarm">{{ $message }}</p> @enderror
            </div>
        </form>
    @endif
</div>
