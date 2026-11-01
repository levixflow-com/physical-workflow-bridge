<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Channel extends Model
{
    public const INPUT_ROLES = [
        'bin_1' => 'Bin A button',
        'bin_2' => 'Bin B button',
        'bin_3' => 'Bin C button',
        'bin_4' => 'Bin D button',
        'report_empty' => 'Bin empty button',
        'parcel_ready' => 'Parcel ready button',
    ];

    public const RELAY_ROLES = [
        'lamp_1' => 'Bin A lamp',
        'lamp_2' => 'Bin B lamp',
        'lamp_3' => 'Bin C lamp',
        'lamp_4' => 'Bin D lamp',
        'lamp_ok' => 'Green lamp',
        'buzzer' => 'Buzzer',
    ];

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['commandable' => 'boolean', 'state_at' => 'datetime'];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    public function latestCommand(): HasOne
    {
        return $this->hasOne(RelayCommand::class)->latestOfMany();
    }

    public function name(): string
    {
        return ($this->kind === 'input' ? 'I' : 'R').$this->number.' '.$this->label;
    }
}
