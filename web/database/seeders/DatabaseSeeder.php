<?php

namespace Database\Seeders;

use App\Models\Bin;
use App\Models\Channel;
use App\Models\Device;
use Illuminate\Database\Seeder;

// Idempotent: runs at every start and leaves what already exists alone.
class DatabaseSeeder extends Seeder
{
    // Letters and digits only: USB readers type as a US keyboard, and on an Italian
    // layout a hyphen in a code arrives as an apostrophe.
    private const BINS = [
        1 => ['A', 'MUG100', 'Ceramic mug'],
        2 => ['B', 'NOTE200', 'A5 notebook'],
        3 => ['C', 'BTL300', 'Steel bottle'],
        4 => ['D', 'STND400', 'Phone stand'],
    ];

    private const INPUTS = ['bin_1', 'bin_2', 'bin_3', 'bin_4', 'report_empty', 'parcel_ready', null, null];

    private const RELAYS = ['lamp_1', 'lamp_2', 'lamp_3', 'lamp_4', 'lamp_ok', 'buzzer', null, null];

    public function run(): void
    {
        if (Device::exists()) {
            return;
        }

        $simulated = config('bridge.simulator_enabled');

        $this->device(['name' => 'Simulator', 'driver' => 'simulator', 'is_active' => $simulated, 'auto_run' => true]);
        $this->device(['name' => 'DT-R008', 'driver' => 'dtr008', 'is_active' => ! $simulated, 'topic_prefix' => config('bridge.topic_prefix')]);

        foreach (self::BINS as $position => [$code, $sku, $name]) {
            Bin::create(['position' => $position, 'code' => $code, 'sku' => $sku, 'name' => $name, 'stock' => 40]);
        }
    }

    private function device(array $attributes): void
    {
        $device = Device::create($attributes);

        foreach (range(1, 8) as $number) {
            $role = self::INPUTS[$number - 1];
            Channel::create(['device_id' => $device->id, 'kind' => 'input', 'number' => $number, 'label' => Channel::INPUT_ROLES[$role] ?? "Input $number", 'role' => $role, 'active_level' => 'low']);

            $role = self::RELAYS[$number - 1];
            Channel::create(['device_id' => $device->id, 'kind' => 'relay', 'number' => $number, 'label' => Channel::RELAY_ROLES[$role] ?? "Relay $number", 'role' => $role, 'commandable' => $role !== null]);
        }
    }
}
