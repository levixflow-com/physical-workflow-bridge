<?php

namespace App\Device;

use App\Events\EventRecorder;
use App\Models\RelayCommand;

interface BoardAdapter
{
    public function connect(): void;

    // Hands what arrived to the EventRecorder, without blocking.
    public function poll(EventRecorder $recorder): void;

    // Returns once the command has left: the confirmation comes later, from the relay state.
    public function send(RelayCommand $command): void;

    public function disconnect(): void;
}
