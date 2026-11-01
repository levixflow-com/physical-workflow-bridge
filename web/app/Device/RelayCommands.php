<?php

namespace App\Device;

use App\Models\Channel;
use App\Models\Device;
use App\Models\Event;
use App\Models\RelayCommand;
use Illuminate\Database\UniqueConstraintViolationException;
use Throwable;

class RelayCommands
{
    public function request(Channel $relay, string $action, string $origin, ?int $pulseTenths = null): ?RelayCommand
    {
        $device = $relay->device;
        $source = $origin === 'operator' ? 'operator' : 'system';

        if (! $relay->commandable) {
            $this->reject($device, $relay, $source, 'Relay is not enabled for commands');

            return null;
        }
        if (($action === 'on' && $relay->state === 'ON') || ($action === 'off' && $relay->state === 'OFF')) {
            return null;
        }
        if ($action === 'pulse' && $relay->state === 'ON') {
            $this->reject($device, $relay, $source, 'Relay already on');

            return null;
        }

        try {
            $command = RelayCommand::create([
                'channel_id' => $relay->id,
                'action' => $action,
                'origin' => $origin,
                'pulse_tenths' => $pulseTenths,
                'requested_at' => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            $this->reject($device, $relay, $source, 'Previous command still open');

            return null;
        }

        Event::record($device, $source, 'command_requested', ['channel_id' => $relay->id, 'value' => strtoupper($action)]);

        return $command;
    }

    public function dispatch(BoardAdapter $adapter, Device $device): void
    {
        foreach ($this->commands($device, 'requested') as $command) {
            if ($device->health() !== 'live') {
                $this->close($device, $command, 'failed', 'command_failed', 'Board disconnected or not reporting');

                continue;
            }
            try {
                $adapter->send($command);
                $command->update(['status' => 'sent', 'sent_at' => now()]);
                Event::record($device, 'system', 'command_sent', ['channel_id' => $command->channel_id, 'value' => strtoupper($command->action)]);
            } catch (Throwable $e) {
                $this->close($device, $command, 'failed', 'command_failed', $e->getMessage());
            }
        }

        foreach ($this->commands($device, 'sent') as $command) {
            if ($command->sent_at->lt(now()->subSeconds(config('bridge.command_timeout_s')))) {
                $this->close($device, $command, 'timeout', 'command_timeout', 'Sent, but the relay state did not change');
            }
        }
    }

    public function confirm(Channel $relay, bool $on): void
    {
        $command = RelayCommand::where('channel_id', $relay->id)->where('status', 'sent')->first();

        if ($command?->expectsOn() === $on) {
            $command->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            Event::record($relay->device, 'device', 'command_confirmed', ['channel_id' => $relay->id, 'value' => $on ? 'ON' : 'OFF']);
        }
    }

    private function commands(Device $device, string $status)
    {
        return RelayCommand::with('channel')
            ->where('status', $status)
            ->whereHas('channel', fn ($q) => $q->where('device_id', $device->id))
            ->get();
    }

    private function close(Device $device, RelayCommand $command, string $status, string $type, string $error): void
    {
        $command->update(['status' => $status, 'error' => $error]);
        Event::record($device, 'system', $type, ['channel_id' => $command->channel_id, 'detail' => $error]);
    }

    private function reject(Device $device, Channel $relay, string $source, string $reason): void
    {
        Event::record($device, $source, 'command_rejected', ['channel_id' => $relay->id, 'detail' => $reason]);
    }
}
