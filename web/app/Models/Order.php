<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    public const STATUS_LABELS = [
        'queued' => 'Queued',
        'picking' => 'Picking',
        'packing' => 'Packing',
        'on_hold' => 'On hold',
        'shipped' => 'Shipped',
    ];

    // On the bench right now: one at a time, the operator works one parcel.
    public const ACTIVE = ['picking', 'packing'];

    // Still holding stock back from the shop.
    public const OPEN = ['queued', 'picking', 'on_hold'];

    protected $guarded = [];

    protected $casts = [
        'started_at' => 'datetime',
        'picked_at' => 'datetime',
        'shipped_at' => 'datetime',
    ];

    public function lines(): HasMany
    {
        return $this->hasMany(OrderLine::class)->orderBy('id');
    }

    public static function active(): ?self
    {
        return self::whereIn('status', self::ACTIVE)->orderBy('id')->first();
    }

    public static function nextQueued(): ?self
    {
        return self::where('status', 'queued')->orderBy('id')->first();
    }

    public function currentLine(): ?OrderLine
    {
        return $this->lines()->with('bin')->whereColumn('picked_qty', '<', 'qty')->first();
    }

    public function seconds(): ?int
    {
        return $this->started_at && $this->shipped_at ? (int) $this->started_at->diffInSeconds($this->shipped_at, true) : null;
    }

    public static function clock(int $seconds): string
    {
        return ($seconds >= 3600 ? intdiv($seconds, 3600).':' : '').gmdate('i:s', $seconds);
    }
}
