<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

// Append-only log: nothing in the application updates or deletes a row,
// except linking a press to the order it belonged to.
class Event extends Model
{
    public const GROUPS = [
        'Orders' => ['order_started', 'pick', 'order_picked', 'order_shipped', 'order_resumed'],
        'Exceptions' => ['wrong_bin', 'wrong_scan', 'stock_difference', 'order_held'],
        'Stock' => ['pick', 'stock_difference', 'restock'],
        'Inputs' => ['press', 'input_release', 'input_bounce', 'input_resync'],
        'Relays and commands' => ['relay_change', 'relay_resync', 'command_requested', 'command_sent', 'command_confirmed', 'command_failed', 'command_timeout', 'command_rejected'],
        'Connection' => ['online', 'offline', 'stale', 'fresh', 'broker_error', 'broker_ok'],
    ];

    public const LABELS = [
        'press' => 'Input pressed',
        'input_release' => 'Input released',
        'input_bounce' => 'Contact bounce ignored',
        'input_resync' => 'Input state resynced',
        'relay_change' => 'Relay changed',
        'relay_resync' => 'Relay state resynced',
        'command_requested' => 'Command requested',
        'command_sent' => 'Command sent',
        'command_confirmed' => 'Command confirmed',
        'command_failed' => 'Command failed',
        'command_timeout' => 'No confirmation from board',
        'command_rejected' => 'Command rejected',
        'order_started' => 'Order started',
        'pick' => 'Picked',
        'order_picked' => 'All items picked',
        'order_shipped' => 'Parcel left the bench',
        'order_held' => 'Order on hold',
        'order_resumed' => 'Order resumed',
        'wrong_bin' => 'Wrong bin stopped',
        'wrong_scan' => 'Wrong item stopped',
        'stock_difference' => 'Stock difference',
        'restock' => 'Restocked',
        'online' => 'Board connected',
        'offline' => 'Board disconnected',
        'stale' => 'Board went quiet',
        'fresh' => 'Board reporting again',
        'broker_error' => 'Broker unreachable',
        'broker_ok' => 'Broker reachable',
    ];

    // Shown on the bench: what a supervisor cares about, without the signal noise.
    public const BENCH_FEED = ['order_started', 'pick', 'order_picked', 'order_shipped', 'order_held', 'order_resumed', 'wrong_bin', 'wrong_scan', 'stock_difference', 'restock', 'stale', 'fresh', 'offline', 'online', 'broker_error', 'broker_ok', 'command_timeout', 'command_failed'];

    public const EXCEPTIONS = ['wrong_bin', 'wrong_scan', 'stock_difference', 'order_held', 'stale', 'offline', 'broker_error', 'command_timeout', 'command_failed'];

    public const SOURCES = ['device' => 'Board', 'simulator' => 'Simulator', 'operator' => 'Operator', 'system' => 'System'];

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $casts = ['received_at' => 'datetime', 'is_demo' => 'boolean', 'payload' => 'array'];

    public static function record(Device $device, string $source, string $type, array $attributes = []): self
    {
        return self::create([
            'device_id' => $device->id,
            'source' => $source === 'device' && $device->isSimulated() ? 'simulator' : $source,
            'type' => $type,
            'is_demo' => $device->isSimulated(),
            'received_at' => now(),
        ] + $attributes);
    }

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function label(): string
    {
        return self::LABELS[$this->type] ?? $this->type;
    }

    public function isException(): bool
    {
        return in_array($this->type, self::EXCEPTIONS, true);
    }
}
