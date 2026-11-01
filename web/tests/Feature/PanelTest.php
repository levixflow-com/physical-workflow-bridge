<?php

namespace Tests\Feature;

use App\Livewire\Bench;
use App\Livewire\BoardStatus;
use App\Livewire\DeviceManager;
use App\Models\Bin;
use App\Models\Channel;
use App\Models\Device;
use App\Models\Order;
use App\Models\RelayCommand;
use App\Models\SimulatorAction;
use App\Support\Code39;
use App\Workflow\OrderBook;
use App\Workflow\ParcelPhotos;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire;
use Tests\TestCase;

class PanelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DatabaseSeeder::class);
        Device::where('is_active', true)->update(['link_state' => 'online', 'last_seen_at' => now()]);
    }

    private function device(): Device
    {
        return Device::active()->first();
    }

    private function pickingOrder(): Order
    {
        $order = app(OrderBook::class)->create('SO-1', 'Test', [Bin::where('code', 'A')->value('id') => 2]);
        $order->update(['status' => 'picking', 'started_at' => now()]);

        return $order;
    }

    public function test_every_page_answers(): void
    {
        $order = app(OrderBook::class)->demo();

        foreach (['/', '/orders', "/orders/{$order->id}", '/stock', '/board', '/events', '/labels'] as $url) {
            $this->get($url)->assertOk();
        }
    }

    public function test_simulator_ignores_actions_it_cannot_run(): void
    {
        Livewire::test(Bench::class)->call('simulate', 'nonsense', 1)->call('simulate', 'press');

        $this->assertSame(0, SimulatorAction::count());
    }

    public function test_demo_order_skips_a_number_already_used_by_the_webhook(): void
    {
        $orders = app(OrderBook::class);
        $orders->create('SO-1002', 'Shop customer', [Bin::first()->id => 1]);

        $this->assertSame('SO-1003', $orders->demo()->number);
    }

    public function test_status_strip_declares_simulated_data(): void
    {
        Livewire::test(BoardStatus::class)->assertSee('Simulated board and synthetic orders');
    }

    public function test_status_strip_flags_old_data(): void
    {
        Device::where('is_active', true)->update(['last_seen_at' => now()->subMinute()]);

        Livewire::test(BoardStatus::class)->assertSee('Not reporting')->assertSee('last known state');
    }

    public function test_bench_gives_one_instruction(): void
    {
        $this->pickingOrder();

        Livewire::test(Bench::class)->assertSee('Take 2 × Ceramic mug from bin A');
    }

    public function test_sent_command_does_not_light_the_lamp_on_screen(): void
    {
        $this->pickingOrder();
        $relay = $this->device()->channels()->where('role', 'lamp_1')->first();
        $relay->update(['state' => 'OFF']);
        RelayCommand::create(['channel_id' => $relay->id, 'action' => 'on', 'origin' => 'workflow', 'status' => 'sent', 'requested_at' => now()]);

        Livewire::test(Bench::class)->assertSeeHtml('lamp lamp-pending')->assertDontSeeHtml('lamp lamp-on');
    }

    public function test_lamps_are_unknown_when_the_board_is_quiet(): void
    {
        Device::where('is_active', true)->update(['last_seen_at' => now()->subMinute()]);

        Livewire::test(Bench::class)->assertSeeHtml('lamp lamp-unknown');
    }

    public function test_scan_from_the_panel_picks_and_wrong_code_shows_why(): void
    {
        $order = $this->pickingOrder();

        Livewire::test(Bench::class)->call('scan', 'mug100')->assertSet('notice', '');
        $this->assertSame(1, $order->lines()->first()->picked_qty);

        Livewire::test(Bench::class)->call('scan', 'NOPE')->assertSee('Wrong item: NOPE');
    }

    public function test_simulator_off_takes_no_actions_and_disappears(): void
    {
        config(['bridge.simulator_enabled' => false]);
        Device::where('driver', 'dtr008')->update(['is_active' => true]);

        Livewire::test(Bench::class)->call('simulate', 'press', 1)->assertDontSee('Press A');

        $this->assertSame(0, SimulatorAction::count());
    }

    public function test_mapping_is_configurable_and_a_role_cannot_be_doubled(): void
    {
        $device = $this->device();
        $i1 = $device->channel('input', 1);
        $i8 = $device->channel('input', 8);

        Livewire::test(DeviceManager::class)
            ->set("form.$i8->id.role", 'bin_1')
            ->call('save')
            ->assertHasErrors('form');

        Livewire::test(DeviceManager::class)
            ->set("form.$i1->id.role", '')
            ->set("form.$i8->id.role", 'bin_1')
            ->set("form.$i8->id.active_level", 'high')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('high', $i8->fresh()->active_level);
        $this->assertSame('bin_1', $i8->fresh()->role);
    }

    public function test_panel_pulse_goes_through_the_command_queue(): void
    {
        $relay = $this->device()->channel('relay', 1);

        Livewire::test(DeviceManager::class)->call('pulse', $relay->id);

        $this->assertSame('requested', RelayCommand::first()->status);
        $this->assertInstanceOf(Channel::class, RelayCommand::first()->channel);
    }

    public function test_webhook_creates_an_order_from_a_woocommerce_shape(): void
    {
        $this->postJson('/api/orders', [
            'number' => 'WC-1042',
            'billing' => ['first_name' => 'Ada'],
            'line_items' => [['sku' => 'MUG100', 'quantity' => 2], ['sku' => 'BTL300', 'quantity' => 1]],
        ])->assertCreated();

        $order = Order::where('number', 'WC-1042')->first();
        $this->assertSame('Ada', $order->customer);
        $this->assertSame(2, $order->lines()->count());
    }

    public function test_webhook_refuses_an_unknown_sku(): void
    {
        $this->postJson('/api/orders', ['number' => 'WC-1', 'line_items' => [['sku' => 'NOPE', 'quantity' => 1]]])
            ->assertUnprocessable();

        $this->assertSame(0, Order::count());
    }

    public function test_stock_endpoint_takes_off_what_open_orders_hold(): void
    {
        $this->pickingOrder();

        $this->getJson('/api/stock')->assertOk()->assertJsonFragment(['sku' => 'MUG100', 'on_hand' => 40, 'reserved' => 2, 'available' => 38]);
    }

    public function test_photo_refuses_anything_but_a_jpeg(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(ParcelPhotos::class)->store($this->pickingOrder(), 'data:image/jpeg;base64,'.base64_encode('<?php echo 1;'));
    }

    public function test_photo_is_stored_and_served(): void
    {
        config(['database.connections.sqlite.database' => sys_get_temp_dir().'/bench.sqlite']);
        $order = $this->pickingOrder();

        app(ParcelPhotos::class)->store($order, 'data:image/jpeg;base64,'.base64_encode("\xFF\xD8\xFF\xE0test"));

        $this->get("/orders/{$order->id}/photo")->assertOk();
    }

    public function test_clock_keeps_the_hours(): void
    {
        $this->assertSame('01:15', Order::clock(75));
        $this->assertSame('12:32:47', Order::clock(45167));
    }

    public function test_code39_starts_and_ends_with_the_guard_character(): void
    {
        [$bars, $width] = Code39::bars('A');

        // Three characters of 15 units plus two gaps between them.
        $this->assertSame(15 * 3 + 2, $width);
        $this->assertCount(5 * 3, $bars);
    }
}
