<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RelayCommand extends Model
{
    public const STATUS_LABELS = [
        'requested' => 'Requested, not sent yet',
        'sent' => 'Sent, waiting for the board',
        'confirmed' => 'Confirmed by the board',
        'failed' => 'Failed',
        'timeout' => 'No confirmation from the board',
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $casts = ['requested_at' => 'datetime', 'sent_at' => 'datetime', 'confirmed_at' => 'datetime'];

    public function channel(): BelongsTo
    {
        return $this->belongsTo(Channel::class);
    }

    public function expectsOn(): bool
    {
        return $this->action !== 'off';
    }

    public function isOpen(): bool
    {
        return in_array($this->status, ['requested', 'sent'], true);
    }

    public function isPulsing(): bool
    {
        return $this->action === 'pulse' && $this->status === 'confirmed' && $this->channel->state === 'ON';
    }
}
