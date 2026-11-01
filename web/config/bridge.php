<?php

return [
    // Measured on the real DT-R008: see docs/board-analysis.md.
    'stale_after_s' => 45,
    'simulated_offline_after_s' => 165,

    // Same input, within this time: a contact bounce, not a second press.
    'press_pause_ms' => (int) env('BENCH_PRESS_PAUSE_MS', 3000),
    'resync_window_ms' => 2000,
    'command_timeout_s' => 3,

    'buzzer_pulse_tenths' => 5,

    'simulator_enabled' => (bool) env('SIMULATOR_ENABLED', true),

    'mqtt' => [
        'host' => env('MQTT_HOST', 'mosquitto'),
        'port' => (int) env('MQTT_PORT', 1883),
        'user' => env('MQTT_USER'),
        'password' => env('MQTT_PASSWORD'),
    ],

    'relay_password' => env('DTR008_RELAY_PASSWORD', '0'),
    'topic_prefix' => env('DTR008_TOPIC_PREFIX', '/dingtian/relay00000'),
];
