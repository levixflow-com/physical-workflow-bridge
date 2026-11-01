<?php

namespace App\Events;

use App\Device\RelayCommands;
use App\Models\Device;
use App\Models\Event;
use App\Workflow\PackingBench;

// Turns board signals into events: drops heartbeat duplicates and separates
// presses from contact bounce and resyncs.
class EventRecorder
{
    private array $lastChangeMs = [];

    private int $resyncUntilMs = 0;

    private bool $stale = false;

    public function __construct(private PackingBench $bench, private RelayCommands $commands) {}

    // Right after a connection we receive states, not actions: for a moment they do not move the flow.
    public function beginResync(): void
    {
        $this->resyncUntilMs = $this->nowMs() + config('bridge.resync_window_ms');
    }

    public function seen(Device $device): void
    {
        $wasLive = $device->health() === 'live';

        if ($this->stale) {
            $this->stale = false;
            Event::record($device, 'device', 'fresh');
        }
        if ($device->last_seen_at === null || $device->last_seen_at->diffInSeconds(now(), true) >= 1) {
            $device->update(['last_seen_at' => now()]);
        }
        // Also at the end of a resync: the retained state arrives before the first live message.
        if (! $wasLive) {
            $this->beginResync();
        }
    }

    public function online(Device $device): void
    {
        if ($device->link_state !== 'online') {
            $device->update(['link_state' => 'online']);
            Event::record($device, 'device', 'online');
            $this->beginResync();
        }
    }

    public function offline(Device $device, ?array $raw = null): void
    {
        if ($device->link_state !== 'offline') {
            $device->update(['link_state' => 'offline']);
            Event::record($device, 'device', 'offline', ['payload' => $raw]);
        }
    }

    public function identity(Device $device, array $values): void
    {
        $device->fill($values);
        if ($device->isDirty()) {
            $device->save();
        }
    }

    public function input(Device $device, int $number, string $level, ?array $raw = null): void
    {
        $channel = $device->channel('input', $number);

        if ($channel === null || $channel->state === $level) {
            return;
        }

        $first = $channel->state === null;
        $previous = $this->lastChangeMs[$channel->id] ?? null;
        $inPause = $previous !== null && $this->nowMs() - $previous < config('bridge.press_pause_ms');
        $this->lastChangeMs[$channel->id] = $this->nowMs();
        $channel->update(['state' => $level, 'state_at' => now()]);

        $type = match (true) {
            $first || $this->resyncing() => 'input_resync',
            $level !== strtoupper($channel->active_level) => 'input_release',
            $inPause => 'input_bounce',
            default => 'press',
        };

        $event = Event::record($device, 'device', $type, [
            'channel_id' => $channel->id,
            'value' => $level,
            'detail' => $type === 'input_bounce' ? 'Inside the press pause: the flow does not change' : null,
            'payload' => $raw,
        ]);

        if ($type === 'press') {
            $this->bench->press($channel, $event);
        }
    }

    public function relay(Device $device, int $number, bool $on, ?array $raw = null): void
    {
        $channel = $device->channel('relay', $number);

        if ($channel === null) {
            return;
        }

        $this->commands->confirm($channel, $on);

        $state = $on ? 'ON' : 'OFF';
        if ($channel->state === $state) {
            return;
        }

        $type = $channel->state === null ? 'relay_resync' : 'relay_change';
        $channel->update(['state' => $state, 'state_at' => now()]);
        Event::record($device, 'device', $type, ['channel_id' => $channel->id, 'value' => $state, 'payload' => $raw]);
        $this->bench->syncLamps($device);
    }

    // Called on every bridge loop: watches for silence and puts the lamps right after a resync.
    public function tick(Device $device): void
    {
        if (! $this->stale && $device->health() === 'stale') {
            $this->stale = true;
            Event::record($device, 'system', 'stale', ['detail' => 'No message for over '.config('bridge.stale_after_s').' seconds']);
        }

        if ($this->resyncing()) {
            return;
        }

        if ($this->resyncUntilMs) {
            $this->resyncUntilMs = 0;
            $this->bench->syncLamps($device);
        }

        $this->bench->startNext($device);
    }

    private function resyncing(): bool
    {
        return $this->nowMs() < $this->resyncUntilMs;
    }

    private function nowMs(): int
    {
        return now()->getTimestampMs();
    }
}
