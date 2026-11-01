<div wire:poll.1s class="space-y-4">
    @php
        $relay = fn (string $role) => $channels[$role] ?? null;
        $lamp = function (?\App\Models\Channel $channel) use ($known) {
            if ($channel === null || ! $known || $channel->state === null) {
                return 'lamp-unknown';
            }
            if ($channel->latestCommand?->isOpen() && $channel->latestCommand->expectsOn() && $channel->state !== 'ON') {
                return 'lamp-pending';
            }

            return $channel->state === 'ON' ? 'lamp-on' : '';
        };
        $failed = collect($channels)->first(fn ($c) => $c->kind === 'relay' && in_array($c->latestCommand?->status, ['failed', 'timeout']) && $c->latestCommand->requested_at->gt(now()->subSeconds(20)));
    @endphp

    {{-- The one sentence the operator needs. --}}
    <section @class([
        'plate flex flex-wrap items-center justify-between gap-x-8 gap-y-2 border-l-8 px-5 py-4',
        'border-l-act' => $order?->status === 'picking',
        'border-l-ok' => $order?->status === 'packing',
        'border-l-idle' => ! in_array($order?->status, ['picking', 'packing']),
    ])>
        <div>
            @if ($line)
                <h1 class="text-3xl font-semibold">Take {{ $line->remaining() }} × {{ $line->bin->name }} from bin {{ $line->bin->code }}</h1>
                <p class="text-muted">{{ $line->remaining() === 1 ? 'Scan the item, or press the lit button to confirm it.' : 'Scan each item, or press the lit button to confirm all '.$line->remaining().'.' }}</p>
            @elseif ($order?->status === 'packing')
                <h1 class="text-3xl font-semibold">Close the parcel</h1>
                <p class="text-muted">All items are picked. Press Parcel ready to ship the order.</p>
            @elseif ($held->isNotEmpty())
                <h1 class="text-3xl font-semibold">Nothing to pick: {{ $held->count() === 1 ? 'one order is' : $held->count().' orders are' }} on hold</h1>
                <p class="text-muted">See Needs attention below.</p>
            @else
                <h1 class="text-3xl font-semibold">Bench is free</h1>
                <p class="text-muted">The next order starts here as soon as it arrives.</p>
            @endif
        </div>
        @if ($order)
            <div class="text-right">
                <p class="text-3xl font-semibold">{{ \App\Models\Order::clock((int) $order->started_at?->diffInSeconds(now(), true)) }}</p>
                <p class="text-muted">on {{ $order->number }}</p>
            </div>
        @endif
    </section>

    @if ($notice)
        <p class="plate border-alarm bg-alarm-tint px-4 py-2 font-medium text-alarm" role="alert">{{ $notice }}</p>
    @endif
    @if ($failed)
        <p class="plate border-alarm bg-alarm-tint px-4 py-2 font-medium text-alarm" role="alert">{{ $failed->label }}: {{ \App\Models\RelayCommand::STATUS_LABELS[$failed->latestCommand->status] }}. The lamp may not match the screen.</p>
    @endif

    <div class="grid gap-4 xl:grid-cols-3">
        {{-- The bench as it stands. Lamps show what the board reports, not what was asked. --}}
        <section class="plate xl:col-span-2">
            <h2 class="plate-title">Bench</h2>
            <div class="grid gap-x-8 gap-y-6 p-5 md:grid-cols-[minmax(0,3fr)_minmax(0,2fr)]">
                <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                    @foreach ($bins as $bin)
                        @php($target = $line?->bin_id === $bin->id)
                        <div @class(['bin flex flex-col items-center gap-2 px-2 py-3 text-center', 'bin-target' => $target])>
                            <span class="lamp {{ $lamp($relay('lamp_'.$bin->position)) }}" role="img" aria-label="Bin {{ $bin->code }} lamp"></span>
                            <p class="text-2xl font-semibold">{{ $bin->code }}</p>
                            <p class="leading-tight">{{ $bin->name }}</p>
                            <p class="text-muted">{{ $bin->stock }} in stock</p>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-col justify-between gap-5">
                    <div class="flex items-center gap-3">
                        <span class="lamp lamp-ok {{ $lamp($relay('lamp_ok')) }}" role="img" aria-label="Green lamp"></span>
                        <p>{{ ! $known ? 'Green lamp unknown' : ($relay('lamp_ok')?->state === 'ON' ? 'Good to send' : 'Green lamp off') }}</p>
                    </div>
                    <div class="min-h-[1.5rem] text-muted">
                        @if ($lastShipped)
                            Last parcel {{ $lastShipped->number }}, {{ $lastShipped->shipped_at->format('H:i:s') }}
                            @if ($lastShipped->photo_path)
                                <a href="{{ route('orders.show', $lastShipped) }}" wire:navigate class="underline">with photo</a>
                            @endif
                        @endif
                    </div>
                </div>
            </div>
            <p class="border-t border-rule px-5 py-2 text-muted">
                @if ($known)
                    Solid lamps are confirmed by the board. A dashed ring is a command sent and not confirmed yet.
                @else
                    Lamps are hatched because the board is not reporting: their state is unknown.
                @endif
            </p>
        </section>

        <section class="plate flex flex-col">
            <h2 class="plate-title">
                @if ($order)
                    <a href="{{ route('orders.show', $order) }}" wire:navigate class="underline decoration-rule">{{ $order->number }}</a>, {{ $order->customer }}
                @else
                    No order on the bench
                @endif
            </h2>
            <div class="flex-1 px-4 py-2">
                @if ($order)
                    <ul>
                        @foreach ($order->lines as $item)
                            @php($done = $item->remaining() === 0)
                            <li @class(['flex justify-between gap-4 border-b border-ground py-1.5 last:border-0', 'text-muted' => $done, 'font-semibold text-act' => $item->id === $line?->id])>
                                <span>{{ $item->bin->name }}, bin {{ $item->bin->code }}</span>
                                <span>{{ $item->picked_qty }} of {{ $item->qty }}</span>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="py-1.5 text-muted">{{ $queued ? $queued.' queued' : 'Queue is empty.' }}</p>
                @endif
            </div>

            <form wire:ignore class="border-t border-rule px-4 py-3" x-data="{ code: '' }"
                  x-on:keydown.window="if (document.activeElement === document.body) $refs.code.focus()"
                  x-on:submit.prevent="$wire.scan(code); code = ''">
                <label for="scan" class="font-medium">Barcode</label>
                <input id="scan" x-ref="code" x-model="code" x-init="$el.focus()" autocomplete="off" class="field mt-1 w-full text-lg" placeholder="Scan an item">
            </form>

            <div class="flex flex-wrap gap-2 border-t border-rule px-4 py-3">
                <button wire:click="closeParcel"@disabled($order?->status !== 'packing' || ! $known) class="btn btn-act">Parcel ready</button>
                <button wire:click="reportEmpty" @disabled(! $line || ! $known) class="btn">Bin is empty</button>
            </div>
        </section>
    </div>

    <section class="plate">
        <h2 class="plate-title">Today</h2>
        <dl class="grid grid-cols-2 md:grid-cols-4">
            @foreach ([
                ['Parcels shipped', $today['shipped']],
                ['Median time per order', $today['median'] === null ? 'No data yet' : \App\Models\Order::clock((int) $today['median'])],
                ['Mistakes stopped', $today['mistakes']],
                ['Stock differences', $today['differences']],
            ] as [$label, $value])
                <div class="border-rule px-4 py-2 md:border-l md:first:border-l-0">
                    <dt class="text-muted">{{ $label }}</dt>
                    <dd class="text-2xl font-semibold">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <div class="grid gap-4 xl:grid-cols-3">
        <section @class(['plate', 'border-warn' => $held->isNotEmpty()])>
            <h2 class="plate-title">Needs attention</h2>
            @forelse ($held as $item)
                <div class="flex flex-wrap items-center justify-between gap-2 border-b border-ground px-4 py-2 last:border-0" wire:key="held-{{ $item->id }}">
                    <div>
                        <a href="{{ route('orders.show', $item) }}" wire:navigate class="font-semibold underline decoration-rule">{{ $item->number }}</a>
                        <p class="text-warn">{{ $item->hold_reason }}</p>
                    </div>
                    <div class="flex gap-2">
                        <button wire:click="restockFor({{ $item->id }})" class="btn">Restock</button>
                        <button wire:click="resume({{ $item->id }})" class="btn">Resume</button>
                    </div>
                </div>
            @empty
                <p class="px-4 py-2 text-muted">Nothing on hold.</p>
            @endforelse
        </section>

        <section class="plate xl:col-span-2">
            <div class="plate-title flex items-baseline justify-between">
                <h2>Activity</h2>
                <a href="{{ route('events') }}" wire:navigate class="font-normal text-muted underline">Full event log</a>
            </div>
            <ul>
                @forelse ($feed as $event)
                    <li class="grid grid-cols-[5.5rem_minmax(0,1fr)] gap-x-3 border-b border-ground px-4 py-1 last:border-0 sm:grid-cols-[5.5rem_11rem_minmax(0,1fr)]" wire:key="ev-{{ $event->id }}">
                        <span class="text-muted">{{ $event->received_at->format('H:i:s') }}</span>
                        <span @class(['font-medium', 'text-alarm' => $event->isException()])>{{ $event->label() }}</span>
                        <span class="col-start-2 text-muted sm:col-start-3">{{ $event->detail }}</span>
                    </li>
                @empty
                    <li class="px-4 py-2 text-muted">Nothing recorded yet.</li>
                @endforelse
            </ul>
        </section>
    </div>

    <section class="plate">
        <h2 class="plate-title">Camera and test orders</h2>
        <div class="flex flex-wrap items-center gap-2 px-4 py-3"
             x-data="{
                stream: null, taken: null, error: '',
                async start() {
                    try { this.stream = await navigator.mediaDevices.getUserMedia({ video: { width: 960 } }); this.$refs.video.srcObject = this.stream; }
                    catch (e) { this.error = 'No camera available. Parcels are recorded without a photo.'; }
                },
                capture(id) {
                    if (! this.stream || this.taken === id) return;
                    this.taken = id;
                    const canvas = document.createElement('canvas');
                    canvas.width = this.$refs.video.videoWidth;
                    canvas.height = this.$refs.video.videoHeight;
                    canvas.getContext('2d').drawImage(this.$refs.video, 0, 0);
                    $wire.savePhoto(id, canvas.toDataURL('image/jpeg', 0.7));
                },
             }"
             x-effect="if ($wire.photoFor) capture($wire.photoFor)">
            <button wire:click="newOrder" class="btn">Add a test order</button>
            <span wire:ignore class="flex items-center gap-2">
                <button type="button" class="btn" x-on:click="start" x-show="! stream">Turn on camera</button>
                <video x-ref="video" x-show="stream" autoplay muted playsinline class="h-16 rounded-[3px] border border-rule"></video>
                <span class="text-warn" x-show="error" x-text="error"></span>
            </span>
            @if ($queued) <p class="ml-auto text-muted">{{ $queued }} queued</p> @endif
        </div>

        @if ($device?->isSimulated())
            <div class="flex flex-wrap items-center gap-2 border-t border-dashed border-idle px-4 py-3">
                <button wire:click="toggleShift" class="btn">{{ $device->auto_run ? 'Pause simulated shift' : 'Start simulated shift' }}</button>
                @foreach ($bins as $bin)
                    <button wire:click="simulate('press', {{ $channels['bin_'.$bin->position]?->number ?? 'null' }})" @disabled(! isset($channels['bin_'.$bin->position])) class="btn">Press {{ $bin->code }}</button>
                @endforeach
                <button wire:click="simulate('bounce', {{ $channels['bin_1']?->number ?? 1 }})" class="btn">Bouncy contact</button>
                <button wire:click="simulate('disconnect')" class="btn">Unplug board</button>
                <button wire:click="simulate('reconnect')" class="btn">Plug back in</button>
                <p class="basis-full text-muted">An unplugged board goes quiet like the real one: the panel flags it after {{ config('bridge.stale_after_s') }} seconds and the offline message arrives after {{ config('bridge.simulated_offline_after_s') }}.</p>
            </div>
        @endif
    </section>
</div>
