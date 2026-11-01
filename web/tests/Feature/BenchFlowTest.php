<?php

namespace Tests\Feature;

use App\Device\Dtr008Adapter;
use App\Device\RelayCommands;
use App\Device\SimulatedAdapter;
use App\Events\EventRecorder;
use App\Models\Bin;
use App\Models\Device;
use App\Models\Event;
use App\Models\Order;
use App\Models\RelayCommand;
use App\Models\SimulatorAction;
use App\Workflow\OrderBook;
use App\Workflow\PackingBench;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class BenchFlowTest extends TestCase
{
    use RefreshDatabase;

    private Device $device;

    private EventRecorder $recorder;

    private PackingBench $bench;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-31 12:00:00');
        $this->seed(DatabaseSeeder::class);
        $this->device = Device::where('driver', 'simulator')->first();
        $this->device->update(['auto_run' => false]);
        $this->recorder = app(EventRecorder::class);
        $this->bench = app(PackingBench::class);

        // Starting point: board connected, resync over.
        $this->recorder->seen($this->device);
        $this->recorder->online($this->device);
        foreach (range(1, 7) as $number) {
            $this->recorder->input($this->device, $number, 'HIGH');
            $this->recorder->relay($this->device, $number, false);
        }
        $this->advance(3000);
        RelayCommand::query()->delete();
        $this->recorder->seen($this->device);
    }

    private function advance(int $ms): void
    {
        Carbon::setTestNow(now()->addMilliseconds($ms));
    }

    private function bin(string $code): Bin
    {
        return Bin::where('code', $code)->first();
    }

    // Starts an order for 2 of bin A and 1 of bin B, and closes the lamp commands it opened.
    private function startOrder(): Order
    {
        $order = app(OrderBook::class)->create('SO-1', 'Test', [$this->bin('A')->id => 2, $this->bin('B')->id => 1]);
        $this->recorder->tick($this->device);
        $this->settleLamps();

        return $order->fresh();
    }

    // Plays the board: every open command is confirmed.
    private function settleLamps(): void
    {
        foreach (RelayCommand::with('channel')->whereIn('status', ['requested'])->get() as $command) {
            $command->update(['status' => 'sent', 'sent_at' => now()]);
            $this->recorder->relay($this->device, $command->channel->number, $command->expectsOn());
        }
    }

    private function press(int $input): void
    {
        $this->recorder->input($this->device, $input, 'LOW');
        $this->settleLamps();
        $this->advance(300);
        $this->recorder->input($this->device, $input, 'HIGH');
        $this->advance(3100);
    }

    private function relayState(string $role): ?string
    {
        return $this->device->channels()->where('role', $role)->value('state');
    }

    private function types(): array
    {
        return Event::orderBy('id')->pluck('type')->all();
    }

    public function test_a_queued_order_starts_and_lights_the_first_bin(): void
    {
        $order = $this->startOrder();

        $this->assertSame('picking', $order->status);
        $this->assertSame('ON', $this->relayState('lamp_1'));
        $this->assertSame('OFF', $this->relayState('lamp_2'));
    }

    public function test_no_order_starts_while_the_board_is_not_reporting(): void
    {
        $this->advance(46_000);
        app(OrderBook::class)->create('SO-1', 'Test', [$this->bin('A')->id => 1]);

        $this->recorder->tick($this->device);

        $this->assertSame('queued', Order::first()->status);
    }

    public function test_button_confirms_the_whole_line_and_moves_the_lamp(): void
    {
        $order = $this->startOrder();

        $this->press(1);

        $this->assertSame(2, $order->lines()->first()->picked_qty);
        $this->assertSame(38, $this->bin('A')->stock);
        $this->assertSame('pick_button', \App\Models\StockMovement::value('reason'));
        $this->assertSame('OFF', $this->relayState('lamp_1'));
        $this->assertSame('ON', $this->relayState('lamp_2'));
    }

    public function test_wrong_bin_is_stopped_and_changes_nothing(): void
    {
        $order = $this->startOrder();

        $this->press(3);

        $this->assertSame(0, $order->lines()->first()->picked_qty);
        $this->assertSame(40, $this->bin('A')->stock);
        $this->assertSame('Pressed bin C, expected bin A', Event::where('type', 'wrong_bin')->value('detail'));
        $this->assertSame('pulse', RelayCommand::whereRelation('channel', 'role', 'buzzer')->value('action'));
    }

    public function test_scan_counts_one_unit_and_the_wrong_code_is_stopped(): void
    {
        $order = $this->startOrder();

        $this->assertNull($this->bench->scan($this->device, 'MUG100', 'operator'));
        $this->assertSame(1, $order->lines()->first()->picked_qty);

        $message = $this->bench->scan($this->device, 'BTL300', 'operator');

        $this->assertStringContainsString('Wrong item', $message);
        $this->assertSame(1, $order->lines()->first()->picked_qty);
        $this->assertSame(1, Event::where('type', 'wrong_scan')->count());
    }

    public function test_scan_is_refused_while_the_board_is_not_reporting(): void
    {
        $order = $this->startOrder();
        $this->advance(46_000);

        $this->assertStringContainsString('not reporting', $this->bench->scan($this->device, 'MUG100', 'operator'));
        $this->assertSame(0, $order->lines()->first()->picked_qty);
    }

    public function test_last_pick_lights_green_and_nothing_else(): void
    {
        $order = $this->startOrder();

        $this->press(1);
        $this->press(2);

        $this->assertSame('packing', $order->fresh()->status);
        $this->assertSame('ON', $this->relayState('lamp_ok'));
        $this->assertSame('OFF', $this->relayState('lamp_2'));
    }

    public function test_parcel_ready_ships_the_order_and_starts_the_next(): void
    {
        $order = $this->startOrder();
        $this->press(1);
        $this->press(2);
        $next = app(OrderBook::class)->create('SO-2', 'Test', [$this->bin('C')->id => 1]);

        $this->recorder->input($this->device, 6, 'LOW');

        $this->assertSame('shipped', $order->fresh()->status);
        $this->assertSame('picking', $next->fresh()->status);
        $this->assertContains('order_shipped', $this->types());
    }

    public function test_empty_bin_records_the_difference_and_holds_until_restocked(): void
    {
        $order = $this->startOrder();

        $this->recorder->input($this->device, 5, 'LOW');

        $this->assertSame('on_hold', $order->fresh()->status);
        $this->assertSame(0, $this->bin('A')->stock);
        $this->assertSame('Bin A reported empty, system showed 40', Event::where('type', 'stock_difference')->value('detail'));

        $this->assertStringContainsString('Restock bin A', $this->bench->resume($this->device, $order->fresh()));

        $this->bench->restock($this->device, $this->bin('A'), 10, 'operator');
        $this->assertNull($this->bench->resume($this->device, $order->fresh()));
        $this->assertSame('picking', $order->fresh()->status);
    }

    public function test_held_order_does_not_block_the_next_one(): void
    {
        $first = $this->startOrder();
        $second = app(OrderBook::class)->create('SO-2', 'Test', [$this->bin('C')->id => 1]);

        $this->recorder->input($this->device, 5, 'LOW');

        $this->assertSame('on_hold', $first->fresh()->status);
        $this->assertSame('picking', $second->fresh()->status);
    }

    public function test_order_the_bin_cannot_cover_goes_on_hold_before_it_starts(): void
    {
        $this->bin('A')->update(['stock' => 1]);

        $order = $this->startOrder();

        $this->assertSame('on_hold', $order->status);
        $this->assertSame('Bin A is short: 2 needed, 1 in stock', $order->hold_reason);
        $this->assertSame('OFF', $this->relayState('lamp_1'));
    }

    public function test_reserved_units_lower_what_the_shop_can_sell(): void
    {
        $this->startOrder();

        $this->assertSame(2, $this->bin('A')->reserved());

        $this->press(1);

        $this->assertSame(0, $this->bin('A')->reserved());
    }

    public function test_heartbeat_creates_no_events(): void
    {
        $before = Event::count();

        foreach (range(1, 3) as $i) {
            $this->recorder->input($this->device, 1, 'HIGH');
            $this->recorder->relay($this->device, 1, false);
        }

        $this->assertSame($before, Event::count());
    }

    public function test_bouncy_contact_counts_as_one_press(): void
    {
        $order = $this->startOrder();

        foreach (['LOW', 'HIGH', 'LOW', 'HIGH', 'LOW'] as $level) {
            $this->recorder->input($this->device, 1, $level);
            $this->advance(300);
        }

        $this->assertSame(1, Event::where('type', 'press')->count());
        $this->assertSame(2, Event::where('type', 'input_bounce')->count());
        $this->assertSame(2, $order->lines()->first()->picked_qty);
    }

    public function test_input_changed_while_the_board_was_off_moves_nothing(): void
    {
        $order = $this->startOrder();
        $this->recorder->offline($this->device);
        $this->advance(60_000);
        $this->recorder->seen($this->device);
        $this->recorder->online($this->device);

        $this->recorder->input($this->device, 1, 'LOW');

        $this->assertSame(1, Event::where('type', 'input_resync')->where('value', 'LOW')->count());
        $this->assertSame(0, $order->lines()->first()->picked_qty);
    }

    public function test_repeated_command_rejected_while_the_first_is_open(): void
    {
        $relay = $this->device->channel('relay', 2);
        $commands = app(RelayCommands::class);

        $this->assertNotNull($commands->request($relay, 'pulse', 'operator', 5));
        $this->assertNull($commands->request($relay, 'pulse', 'operator', 5));
        $this->assertSame('Previous command still open', Event::where('type', 'command_rejected')->value('detail'));
    }

    public function test_relay_without_commands_is_rejected(): void
    {
        $this->assertNull(app(RelayCommands::class)->request($this->device->channel('relay', 8), 'pulse', 'operator', 5));
        $this->assertSame(0, RelayCommand::count());
    }

    public function test_command_goes_through_request_send_confirm(): void
    {
        $adapter = new SimulatedAdapter($this->device);
        $adapter->connect();
        $commands = app(RelayCommands::class);
        $command = $commands->request($this->device->channel('relay', 2), 'pulse', 'operator', 5);

        $commands->dispatch($adapter, $this->device);
        $this->assertSame('sent', $command->fresh()->status);
        $this->assertSame('OFF', $this->device->channel('relay', 2)->state);

        $this->advance(200);
        $adapter->poll($this->recorder);

        $this->assertSame('confirmed', $command->fresh()->status);
        $this->assertSame('ON', $this->device->channel('relay', 2)->state);

        $this->advance(600);
        $adapter->poll($this->recorder);

        $this->assertSame('OFF', $this->device->channel('relay', 2)->state);
    }

    public function test_unconfirmed_command_times_out(): void
    {
        $adapter = new SimulatedAdapter($this->device);
        $adapter->connect();
        SimulatorAction::create(['device_id' => $this->device->id, 'action' => 'disconnect']);
        $adapter->poll($this->recorder);
        $commands = app(RelayCommands::class);
        $command = $commands->request($this->device->channel('relay', 2), 'pulse', 'operator', 5);

        $commands->dispatch($adapter, $this->device);
        $this->advance(4000);
        $adapter->poll($this->recorder);
        $commands->dispatch($adapter, $this->device);

        $this->assertSame('timeout', $command->fresh()->status);
    }

    public function test_command_does_not_leave_without_a_connection(): void
    {
        $adapter = new SimulatedAdapter($this->device);
        $commands = app(RelayCommands::class);
        $command = $commands->request($this->device->channel('relay', 2), 'pulse', 'operator', 5);
        $this->advance(46_000);

        $commands->dispatch($adapter, $this->device);

        $this->assertSame('failed', $command->fresh()->status);
    }

    public function test_silence_goes_to_stale_then_offline_then_back(): void
    {
        $adapter = new SimulatedAdapter($this->device);
        $adapter->connect();
        $this->assertSame('live', $this->device->health());
        $adapter->poll($this->recorder);
        SimulatorAction::create(['device_id' => $this->device->id, 'action' => 'disconnect']);
        $adapter->poll($this->recorder);

        $this->advance(46_000);
        $adapter->poll($this->recorder);
        $this->recorder->tick($this->device);
        $this->assertSame('stale', $this->device->health());
        $this->assertContains('stale', $this->types());

        $this->advance(120_000);
        $adapter->poll($this->recorder);
        $this->assertSame('offline', $this->device->health());

        SimulatorAction::create(['device_id' => $this->device->id, 'action' => 'reconnect']);
        $adapter->poll($this->recorder);
        $this->assertSame('live', $this->device->health());
        $this->assertContains('fresh', $this->types());
    }

    public function test_press_while_unplugged_is_lost_as_on_the_real_board(): void
    {
        $adapter = new SimulatedAdapter($this->device);
        $adapter->connect();
        $adapter->poll($this->recorder);
        SimulatorAction::create(['device_id' => $this->device->id, 'action' => 'disconnect']);
        $adapter->poll($this->recorder);
        SimulatorAction::create(['device_id' => $this->device->id, 'action' => 'press', 'number' => 1]);
        $adapter->poll($this->recorder);

        $this->assertSame(0, Event::where('type', 'press')->count());
    }

    public function test_test_pulse_on_a_lamp_is_not_cut_short(): void
    {
        $lamp = $this->device->channels()->where('role', 'lamp_2')->first();

        app(RelayCommands::class)->request($lamp, 'pulse', 'operator', 5);
        $this->settleLamps();

        $this->assertSame('ON', $lamp->fresh()->state);
        $this->assertFalse(RelayCommand::where('channel_id', $lamp->id)->where('action', 'off')->exists());

        $this->advance(600);
        $this->recorder->relay($this->device, $lamp->number, false);

        $this->assertSame('OFF', $lamp->fresh()->state);
        $this->assertFalse(RelayCommand::where('channel_id', $lamp->id)->where('action', 'off')->exists());
    }

    public function test_lamp_wanted_during_a_pulse_lights_when_the_pulse_ends(): void
    {
        $lamp = $this->device->channels()->where('role', 'lamp_1')->first();

        app(RelayCommands::class)->request($lamp, 'pulse', 'operator', 5);
        $this->settleLamps();
        $this->startOrder();

        $this->assertFalse(RelayCommand::where('channel_id', $lamp->id)->where('action', 'on')->exists());

        // Measured on the board: a 500 ms pulse can end after 488 ms, before its nominal time.
        $this->advance(488);
        $this->recorder->relay($this->device, $lamp->number, false);

        $this->assertTrue(RelayCommand::where('channel_id', $lamp->id)->where('action', 'on')->exists());
    }

    public function test_pulse_still_ends_when_the_connection_drops_halfway(): void
    {
        $adapter = new SimulatedAdapter($this->device);
        $adapter->connect();
        $adapter->poll($this->recorder);
        $commands = app(RelayCommands::class);

        $commands->request($this->device->channels()->where('role', 'buzzer')->first(), 'pulse', 'workflow', 80);
        $commands->dispatch($adapter, $this->device);
        $this->advance(200);
        $adapter->poll($this->recorder);
        $this->assertSame('ON', $this->relayState('buzzer'));

        SimulatorAction::create(['device_id' => $this->device->id, 'action' => 'disconnect']);
        $adapter->poll($this->recorder);
        $this->advance(8000);
        $adapter->poll($this->recorder);
        SimulatorAction::create(['device_id' => $this->device->id, 'action' => 'reconnect']);
        $adapter->poll($this->recorder);

        $this->assertSame('OFF', $this->relayState('buzzer'));
    }

    public function test_simulated_shift_runs_an_order_through_to_shipped(): void
    {
        mt_srand(7);
        $this->device->update(['auto_run' => true]);
        $adapter = new SimulatedAdapter($this->device);
        $adapter->connect();
        $commands = app(RelayCommands::class);

        for ($i = 0; $i < 900 && ! Order::where('status', 'shipped')->exists(); $i++) {
            $adapter->poll($this->recorder);
            $commands->dispatch($adapter, $this->device);
            $this->recorder->tick($this->device);
            $this->advance(100);
        }

        $this->assertTrue(Order::where('status', 'shipped')->exists());
    }

    public function test_dtr008_reads_the_real_topics(): void
    {
        $device = Device::where('driver', 'dtr008')->first();
        $device->update(['topic_prefix' => '/dingtian/relay1', 'is_active' => true]);
        $adapter = new Dtr008Adapter($device);
        $topic = fn ($name) => "/dingtian/relay1/out/$name";

        $adapter->handle($this->recorder, $topic('lwt_availability'), 'online', false);
        $adapter->handle($this->recorder, $topic('input1'), '{"idx":"1","status":"HIGH"}', false);
        $adapter->handle($this->recorder, $topic('i1'), 'OFF', false);
        $adapter->handle($this->recorder, $topic('r1'), 'ON', false);
        $adapter->handle($this->recorder, $topic('sn'), '123', false);
        $adapter->handle($this->recorder, $topic('input9'), 'broken', false);

        $this->assertSame('online', $device->link_state);
        $this->assertSame('123', $device->fresh()->serial);
        $this->assertSame('HIGH', $device->channel('input', 1)->state);
        $this->assertSame('ON', $device->channel('relay', 1)->state);
    }

    public function test_dtr008_retained_message_is_not_proof_of_life(): void
    {
        $device = Device::where('driver', 'dtr008')->first();
        $device->update(['topic_prefix' => '/dingtian/relay1']);
        $adapter = new Dtr008Adapter($device);

        $adapter->handle($this->recorder, '/dingtian/relay1/out/r1', 'OFF', true);

        $this->assertNull($device->fresh()->last_seen_at);
        $this->assertSame('OFF', $device->channel('relay', 1)->state);
    }

    public function test_dtr008_lamp_lights_only_after_the_first_live_message(): void
    {
        $device = Device::where('driver', 'dtr008')->first();
        $device->update(['topic_prefix' => '/dingtian/relay1']);
        $adapter = new Dtr008Adapter($device);
        app(OrderBook::class)->create('SO-1', 'Test', [$this->bin('A')->id => 1]);
        $this->recorder->beginResync();

        $adapter->handle($this->recorder, '/dingtian/relay1/out/r1', 'OFF', true);
        $this->advance(3000);
        $this->recorder->tick($device);
        $this->assertSame(0, RelayCommand::count());

        foreach (range(1, 7) as $relay) {
            $adapter->handle($this->recorder, "/dingtian/relay1/out/r$relay", 'OFF', false);
        }
        $this->advance(3000);
        $this->recorder->tick($device);

        $this->assertSame('on', RelayCommand::whereRelation('channel', 'role', 'lamp_1')->where('channel_id', $device->channel('relay', 1)->id)->value('action'));
    }

    public function test_dtr008_command_uses_the_format_and_password_configured(): void
    {
        config(['bridge.relay_password' => '1234']);
        $device = Device::where('driver', 'dtr008')->first();
        $relay = $device->channel('relay', 3);

        $pulse = new RelayCommand(['action' => 'pulse', 'pulse_tenths' => 30]);
        $pulse->setRelation('channel', $relay);
        $off = new RelayCommand(['action' => 'off']);
        $off->setRelation('channel', $relay);

        $adapter = new Dtr008Adapter($device);

        $this->assertSame('{"type":"JOGGING","idx":"3","status":"ON","time":"30","pass":"1234"}', $adapter->control($pulse));
        $this->assertSame('{"type":"ON/OFF","idx":"3","status":"OFF","time":"0","pass":"1234"}', $adapter->control($off));
    }
}
