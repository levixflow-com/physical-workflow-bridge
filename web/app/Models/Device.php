<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Device extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['last_seen_at' => 'datetime', 'is_active' => 'boolean', 'auto_run' => 'boolean'];

    public function channels(): HasMany
    {
        return $this->hasMany(Channel::class)->orderBy('number');
    }

    public function channel(string $kind, int $number): ?Channel
    {
        return $this->channels()->where('kind', $kind)->where('number', $number)->first();
    }

    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true)
            ->when(! config('bridge.simulator_enabled'), fn ($q) => $q->where('driver', '!=', 'simulator'));
    }

    public function isSimulated(): bool
    {
        return $this->driver === 'simulator';
    }

    // Computed here, not stored: if the bridge stops, the panel still notices.
    public function health(): string
    {
        return match (true) {
            $this->link_state === 'offline' => 'offline',
            $this->last_seen_at === null => 'unknown',
            $this->last_seen_at->lt(now()->subSeconds(config('bridge.stale_after_s'))) => 'stale',
            default => 'live',
        };
    }
}
