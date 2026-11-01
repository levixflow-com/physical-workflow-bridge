<?php

namespace App\Console\Commands;

use App\Device\BoardAdapter;
use App\Device\Dtr008Adapter;
use App\Device\RelayCommands;
use App\Device\SimulatedAdapter;
use App\Events\EventRecorder;
use App\Models\Device;
use App\Models\Event;
use Illuminate\Console\Command;
use Illuminate\Support\Sleep;
use PhpMqtt\Client\Exceptions\MqttClientException;

class BridgeRun extends Command
{
    private const LOOP_MS = 50;

    private const RECONNECT_MS = 5000;

    protected $signature = 'bridge:run';

    protected $description = 'Connects the active device to the packing bench';

    private ?BoardAdapter $adapter = null;

    private ?Device $device = null;

    private bool $brokerDown = false;

    public function handle(EventRecorder $recorder, RelayCommands $commands): int
    {
        while (true) {
            $active = Device::active()->first();

            if ($active?->id !== $this->device?->id || $active?->topic_prefix !== $this->device?->topic_prefix) {
                $this->adapter?->disconnect();
                $this->adapter = null;
                $this->device = $active;
            }

            if ($this->device === null) {
                Sleep::for(1)->second();

                continue;
            }

            try {
                if ($this->adapter === null) {
                    $this->attach($recorder);
                }
                $this->adapter->poll($recorder);
                $commands->dispatch($this->adapter, $this->device);
            } catch (MqttClientException $e) {
                $this->brokerLost($e);
                Sleep::for(self::RECONNECT_MS)->milliseconds();

                continue;
            }

            $recorder->tick($this->device);
            Sleep::for(self::LOOP_MS)->milliseconds();
        }
    }

    private function attach(EventRecorder $recorder): void
    {
        $adapter = $this->device->isSimulated() ? new SimulatedAdapter($this->device) : new Dtr008Adapter($this->device);
        $adapter->connect();
        $this->adapter = $adapter;
        $recorder->beginResync();

        if ($this->brokerDown) {
            $this->brokerDown = false;
            Event::record($this->device, 'system', 'broker_ok');
        }
    }

    private function brokerLost(MqttClientException $e): void
    {
        $this->adapter = null;

        if (! $this->brokerDown) {
            $this->brokerDown = true;
            $this->error($e->getMessage());
            Event::record($this->device, 'system', 'broker_error', ['detail' => $e->getMessage()]);
        }
    }
}
