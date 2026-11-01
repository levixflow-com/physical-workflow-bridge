<?php

namespace App\Device;

use App\Events\EventRecorder;
use App\Models\Device;
use App\Models\RelayCommand;
use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\MqttClient;

class Dtr008Adapter implements BoardAdapter
{
    private ?MqttClient $client = null;

    private array $inbox = [];

    public function __construct(private Device $device) {}

    public function connect(): void
    {
        $mqtt = config('bridge.mqtt');
        $this->client = new MqttClient($mqtt['host'], $mqtt['port'], 'physical-workflow-bridge');
        $this->client->connect(
            (new ConnectionSettings)->setUsername($mqtt['user'])->setPassword($mqtt['password'])->setKeepAliveInterval(30)->setConnectTimeout(3),
            true,
        );
        $this->client->subscribe(
            $this->device->topic_prefix.'/out/#',
            function (string $topic, string $message, bool $retained) {
                $this->inbox[] = [$topic, $message, $retained];
            },
            1,
        );
    }

    public function poll(EventRecorder $recorder): void
    {
        $this->client->loopOnce(microtime(true));

        foreach ($this->inbox as [$topic, $message, $retained]) {
            $this->handle($recorder, $topic, $message, $retained);
        }
        $this->inbox = [];
    }

    // A retained message comes from the broker, not the board: it gives the last state, not proof of life.
    public function handle(EventRecorder $recorder, string $topic, string $message, bool $retained): void
    {
        $name = substr($topic, strlen($this->device->topic_prefix.'/out/'));
        $raw = compact('topic', 'message');

        if (! $retained) {
            $recorder->seen($this->device);
        }

        if ($name === 'lwt_availability') {
            $message === 'online' ? $recorder->online($this->device) : $recorder->offline($this->device, $raw);
        } elseif (preg_match('/^input(\d)$/', $name, $m)) {
            // inputX and not iX: iX is inverted compared with the level.
            $level = json_decode($message, true)['status'] ?? null;
            if (in_array($level, ['HIGH', 'LOW'], true)) {
                $recorder->input($this->device, (int) $m[1], $level, $raw);
            }
        } elseif (preg_match('/^r(\d)$/', $name, $m) && in_array($message, ['ON', 'OFF'], true)) {
            $recorder->relay($this->device, (int) $m[1], $message === 'ON', $raw);
        } elseif (isset(['ip' => 1, 'sn' => 1, 'hw_version' => 1, 'sw_version' => 1][$name])) {
            $recorder->identity($this->device, [['ip' => 'host', 'sn' => 'serial'][$name] ?? $name => $message]);
        }
    }

    public function send(RelayCommand $command): void
    {
        // QoS 0: a retransmitted QoS 1 would repeat the command. A loss shows up as a missing confirmation.
        $this->client->publish(
            $this->device->topic_prefix.'/in/control',
            $this->control($command),
            MqttClient::QOS_AT_MOST_ONCE,
        );
    }

    public function control(RelayCommand $command): string
    {
        $pulse = $command->action === 'pulse';

        // Without UNESCAPED_SLASHES "ON/OFF" becomes "ON\/OFF": the board ignores it without a reply.
        return json_encode([
            'type' => $pulse ? 'JOGGING' : 'ON/OFF',
            'idx' => (string) $command->channel->number,
            'status' => $command->expectsOn() ? 'ON' : 'OFF',
            'time' => $pulse ? (string) $command->pulse_tenths : '0',
            'pass' => (string) config('bridge.relay_password'),
        ], JSON_UNESCAPED_SLASHES);
    }

    public function disconnect(): void
    {
        if ($this->client?->isConnected()) {
            $this->client->disconnect();
        }
    }
}
