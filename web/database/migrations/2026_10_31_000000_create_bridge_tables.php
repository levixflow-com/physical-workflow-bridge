<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('driver');
            $table->string('topic_prefix')->nullable();
            $table->string('host')->nullable();
            $table->string('serial')->nullable();
            $table->string('hw_version')->nullable();
            $table->string('sw_version')->nullable();
            $table->string('link_state')->default('unknown');
            $table->timestamp('last_seen_at')->nullable();
            $table->boolean('is_active')->default(false);
            $table->boolean('auto_run')->default(false);
        });

        Schema::create('channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained();
            $table->string('kind');
            $table->unsignedTinyInteger('number');
            $table->string('label');
            $table->string('role')->nullable();
            $table->string('active_level')->nullable();
            $table->boolean('commandable')->default(false);
            $table->string('state')->nullable();
            $table->timestamp('state_at')->nullable();
            $table->unique(['device_id', 'kind', 'number']);
        });

        Schema::create('bins', function (Blueprint $table) {
            $table->id();
            $table->unsignedTinyInteger('position')->unique();
            $table->string('code');
            $table->string('sku')->unique();
            $table->string('name');
            $table->integer('stock');
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('number')->unique();
            $table->string('customer');
            $table->string('status')->default('queued')->index();
            $table->string('hold_reason')->nullable();
            $table->string('photo_path')->nullable();
            $table->timestamp('started_at', 3)->nullable();
            $table->timestamp('picked_at', 3)->nullable();
            $table->timestamp('shipped_at', 3)->nullable();
            $table->timestamps();
        });

        Schema::create('order_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained();
            $table->foreignId('bin_id')->constrained();
            $table->unsignedSmallInteger('qty');
            $table->unsignedSmallInteger('picked_qty')->default(0);
        });

        Schema::create('stock_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('bin_id')->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->integer('delta');
            $table->integer('stock_after');
            $table->string('reason');
            $table->timestamp('created_at', 3);
        });

        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->nullable()->constrained();
            $table->foreignId('channel_id')->nullable()->constrained();
            $table->foreignId('order_id')->nullable()->constrained();
            $table->foreignId('bin_id')->nullable()->constrained();
            $table->string('source');
            $table->string('type')->index();
            $table->string('value')->nullable();
            $table->string('detail')->nullable();
            $table->boolean('is_demo');
            $table->timestamp('received_at', 3)->index();
            $table->json('payload')->nullable();
        });

        Schema::create('relay_commands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('channel_id')->constrained();
            $table->string('action');
            $table->string('origin');
            $table->unsignedSmallInteger('pulse_tenths')->nullable();
            $table->string('status')->default('requested');
            $table->timestamp('requested_at', 3);
            $table->timestamp('sent_at', 3)->nullable();
            $table->timestamp('confirmed_at', 3)->nullable();
            $table->string('error')->nullable();
        });

        // Repeat-command protection: enforced by the database, so it also holds between panel and bridge.
        DB::statement("CREATE UNIQUE INDEX one_open_command ON relay_commands (channel_id) WHERE status IN ('requested', 'sent')");

        Schema::create('simulator_actions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('device_id')->constrained();
            $table->string('action');
            $table->unsignedTinyInteger('number')->nullable();
            $table->timestamp('processed_at')->nullable();
        });
    }
};
