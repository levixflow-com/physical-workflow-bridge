<?php

namespace App\Device;

use App\Events\EventRecorder;
use App\Models\Device;
use App\Models\RelayCommand;
use App\Models\SimulatorAction;

// Behaves like the measured board: heartbeat every 30 s, relay confirmed after about 100 ms,
// late offline and, while disconnected, lost presses.
class SimulatedAdapter implements BoardAdapter
{
    private const HEARTBEAT_MS = 30_000;

    private const RELAY_DELAY_MS = 100;

    private array $inputs = [];

    private array $relays = [];

    private array $queue = [];

    private bool $announced = false;

    private int $nextBeat = 0;

    private ?int $silentSince = null;

    private bool $offlineSent = false;

    private ?SimulatedOperator $operator = null;

    public function __construct(private Device $device) {}

    public function connect(): void
    {
        foreach ($this->device->channels as $channel) {
            if ($channel->kind === 'relay') {
                $this->relays[$channel->number] = $channel->state === 'ON';
            } else {
                $this->inputs[$channel->number] = $channel->active_level === 'high' ? 'LOW' : 'HIGH';
            }
        }

        $this->operator = app(SimulatedOperator::class);
    }

    public function poll(EventRecorder $recorder): void
    {
        foreach (SimulatorAction::where('device_id', $this->device->id)->whereNull('processed_at')->orderBy('id')->get() as $action) {
            $this->perform($action->action, $action->number, $recorder);
            $action->update(['processed_at' => now()]);
        }

        foreach ($this->queue as $key => [$at, $apply]) {
            if ($this->nowMs() >= $at) {
                unset($this->queue[$key]);
                $apply($recorder);
            }
        }

        if ($this->silentSince !== null) {
            if (! $this->offlineSent && $this->nowMs() - $this->silentSince >= config('bridge.simulated_offline_after_s') * 1000) {
                $this->offlineSent = true;
                $recorder->offline($this->device);
            }

            return;
        }

        if ($this->nowMs() >= $this->nextBeat) {
            $this->nextBeat = $this->nowMs() + self::HEARTBEAT_MS;
            $this->snapshot($recorder);
        }

        $this->operator?->act($this->device, $this);
    }

    public function pressRole(string $role): void
    {
        $number = $this->device->channels()->where('kind', 'input')->where('role', $role)->value('number');

        if ($number !== null) {
            $this->press($number);
        }
    }

    private function perform(string $action, ?int $number, EventRecorder $recorder): void
    {
        match ($action) {
            'press' => $this->press($number),
            'bounce' => $this->bounce($number),
            'disconnect' => $this->silentSince ??= $this->nowMs(),
            'reconnect' => $this->reconnect($recorder),
        };
    }

    private function press(int $number): void
    {
        [$active, $idle] = $this->levels($number);
        $this->sequence($number, [[0, $active], [400, $idle]]);
    }

    private function bounce(int $number): void
    {
        [$active, $idle] = $this->levels($number);
        $this->sequence($number, [[0, $active], [80, $idle], [160, $active], [240, $idle], [320, $active], [900, $idle]]);
    }

    private function levels(int $number): array
    {
        $active = $this->device->channel('input', $number)?->active_level === 'high' ? 'HIGH' : 'LOW';

        return [$active, $active === 'LOW' ? 'HIGH' : 'LOW'];
    }

    private function sequence(int $number, array $steps): void
    {
        foreach ($steps as [$after, $level]) {
            $this->later($after, function (EventRecorder $recorder) use ($number, $level) {
                $this->inputs[$number] = $level;
                if ($this->silentSince === null) {
                    $recorder->seen($this->device);
                    $recorder->input($this->device, $number, $level);
                }
            });
        }
    }

    private function reconnect(EventRecorder $recorder): void
    {
        if ($this->silentSince === null) {
            return;
        }
        if (! $this->offlineSent) {
            $recorder->offline($this->device);
        }
        $this->silentSince = null;
        $this->offlineSent = false;
        $this->announced = false;
        $this->nextBeat = 0;
    }

    private function snapshot(EventRecorder $recorder): void
    {
        $recorder->seen($this->device);
        if (! $this->announced) {
            $this->announced = true;
            $recorder->identity($this->device, ['serial' => 'SIM-0001', 'sw_version' => 'simulator', 'hw_version' => 'simulator']);
            $recorder->online($this->device);
        }
        foreach ($this->inputs as $number => $level) {
            $recorder->input($this->device, $number, $level);
        }
        foreach ($this->relays as $number => $on) {
            $recorder->relay($this->device, $number, $on);
        }
    }

    public function send(RelayCommand $command): void
    {
        $number = $command->channel->number;
        $on = $command->expectsOn();

        $this->later(self::RELAY_DELAY_MS, fn (EventRecorder $recorder) => $this->setRelay($number, $on, $recorder));

        if ($command->action === 'pulse') {
            $this->later(self::RELAY_DELAY_MS + $command->pulse_tenths * 100, function (EventRecorder $recorder) use ($number) {
                // The board ends a pulse on its own timer, network or not: only the report can be lost.
                $this->relays[$number] = false;
                $this->setRelay($number, false, $recorder);
            });
        }
    }

    private function setRelay(int $number, bool $on, EventRecorder $recorder): void
    {
        if ($this->silentSince !== null) {
            return;
        }
        $this->relays[$number] = $on;
        $recorder->seen($this->device);
        $recorder->relay($this->device, $number, $on);
    }

    private function later(int $afterMs, callable $apply): void
    {
        $this->queue[] = [$this->nowMs() + $afterMs, $apply];
    }

    private function nowMs(): int
    {
        return now()->getTimestampMs();
    }

    public function disconnect(): void {}
}
