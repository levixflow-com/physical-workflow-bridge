<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SimulatorAction extends Model
{
    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['processed_at' => 'datetime'];
}
