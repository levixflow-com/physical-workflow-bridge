<?php

use App\Livewire\Bench;
use App\Livewire\DeviceManager;
use App\Livewire\EventLog;
use App\Livewire\OrderDetail;
use App\Livewire\Orders;
use App\Livewire\Stock;
use App\Models\Bin;
use App\Models\Order;
use Illuminate\Support\Facades\Route;

Route::livewire('/', Bench::class)->name('bench');
Route::livewire('/orders', Orders::class)->name('orders');
Route::livewire('/orders/{order}', OrderDetail::class)->name('orders.show');
Route::livewire('/stock', Stock::class)->name('stock');
Route::livewire('/board', DeviceManager::class)->name('board');
Route::livewire('/events', EventLog::class)->name('events');

Route::get('/orders/{order}/photo', fn (Order $order) => response()->file($order->photo_path ?? abort(404)))->name('orders.photo');
Route::view('/labels', 'labels', ['bins' => fn () => Bin::orderBy('position')->get()])->name('labels');
