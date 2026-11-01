<?php
// Fake DT-R008 for the tests: reproduces only the HTTP CGI replies.
// Start with: php -S 127.0.0.1:8088 fake-board.php

const FAKE_PASSWORD = '1234';

$file = sys_get_temp_dir() . '/fake-dtr008.json';
$s = is_file($file)
    ? json_decode(file_get_contents($file), true)
    : ['relays' => array_fill(0, 8, 0), 'inputs' => array_fill(0, 8, 1), 'until' => array_fill(0, 8, 0)];

foreach ($s['until'] as $i => $until) {
    if ($until && microtime(true) >= $until) {
        $s['relays'][$i] = 0;
        $s['until'][$i] = 0;
    }
}

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$mode = $s['mode'] ?? '';

if ($path === '/mode') {
    // Tests only: wrong-echo, stuck, never and lost simulate a misbehaving board.
    $s['mode'] = $_GET['m'];
} elseif ($mode === 'dead') {
    http_response_code(500);
} elseif ($path === '/relay_cgi_load.cgi') {
    echo '&0&8&' . implode('&', $s['relays']) . '&';
} elseif ($path === '/input.cgi') {
    echo '&0&0&8&' . implode('&', $s['inputs']) . '&';
} elseif ($path === '/relay_cgi.cgi') {
    if (($_GET['pwd'] ?? '') !== FAKE_PASSWORD) {
        // Observed on the real board: code -7 and an echo of the command.
        echo "&-7&{$_GET['type']}&{$_GET['relay']}&{$_GET['on']}&{$_GET['time']}&";
        exit;
    }
    $i = (int) $_GET['relay'];
    if ($mode !== 'never') {
        $s['relays'][$i] = (int) $_GET['on'];
        $s['until'][$i] = $_GET['type'] === '1' && $mode !== 'stuck' ? microtime(true) + $_GET['time'] / 10 : 0;
    }
    if ($mode === 'lost') {
        $s['mode'] = 'dead';
    }
    $echoed = $mode === 'wrong-echo' ? 7 : $_GET['relay'];
    echo "&0&{$_GET['type']}&$echoed&{$_GET['on']}&{$_GET['time']}&";
} else {
    http_response_code(404);
}

file_put_contents($file, json_encode($s));
