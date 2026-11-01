<?php
// Self-check without a board: php test.php

require __DIR__ . '/dtr008.php';

$failures = 0;

function check(string $name, bool $ok): void
{
    global $failures;
    echo ($ok ? 'ok    ' : 'FAIL  ') . "$name\n";
    $failures += $ok ? 0 : 1;
}

function fails(callable $fn, string $expected): bool
{
    try {
        $fn();
    } catch (RuntimeException $e) {
        return str_contains($e->getMessage(), $expected);
    }
    return false;
}

// Literal examples from the manual (PM 3.1, 3.5, 3.8.4).
check('relays from the manual', parseRelays('&0&4&1&0&1&0&') === [1 => 1, 2 => 0, 3 => 1, 4 => 0]);
check('inputs from the manual', parseInputs('&0&0&4&1&0&1&0&') === [1 => 1, 2 => 0, 3 => 1, 4 => 0]);
check('session check refused', fails(fn () => parseRelays('&302&/&'), 'Session check'));
check('truncated reply refused', fails(fn () => parseRelays('&0&8&1&0&'), 'invalid'));
check('stray value refused', fails(fn () => parseInputs('&0&0&2&1&x&'), 'invalid'));

@unlink(sys_get_temp_dir() . '/fake-dtr008.json');
$server = proc_open(['php', '-S', '127.0.0.1:8088', __DIR__ . '/fake-board.php'], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
register_shutdown_function(fn () => proc_terminate($server));
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', 8088); $i++) {
    usleep(100_000);
}

$host = '127.0.0.1:8088';
putenv('DTR008_RELAY_PASSWORD=1234');

$board = readBoard($host);
check('initial state: relays off, inputs high', array_sum($board['relays']) === 0 && array_sum($board['inputs']) === 8);
check('pulse without --confirm refused', fails(fn () => pulse($host, 1, false), '--confirm'));
check('pulse on a missing relay refused', fails(fn () => pulse($host, 9, true), 'does not exist'));
check('pulse confirmed', str_starts_with(pulse($host, 1, true), 'CONFIRMED'));
check('relay back to rest', readBoard($host)['relays'][1] === 0);

get($host, 'mode?m=wrong-echo');
check('echo different from the command refused', fails(fn () => pulse($host, 1, true), 'does not match'));
sleep(1);
get($host, 'mode?m=never');
check('relay never ON reported', fails(fn () => pulse($host, 1, true), 'never seen ON'));
get($host, 'mode?m=stuck');
check('relay stuck ON reported', fails(fn () => pulse($host, 1, true), 'still ON'));
get($host, 'relay_cgi.cgi?type=0&relay=0&on=0&time=0&pwd=1234&');
get($host, 'mode?m=lost');
check('lost reads: password not blamed', fails(fn () => pulse($host, 1, true), 'never seen ON'));
get($host, 'mode?m=');

get($host, 'relay_cgi.cgi?type=0&relay=1&on=1&time=0&pwd=1234&');
check('pulse on a relay already ON refused', fails(fn () => pulse($host, 2, true), 'already ON'));

putenv('DTR008_RELAY_PASSWORD=9999');
$noLeak = true;
check('wrong password refused', fails(function () use ($host, &$noLeak) {
    try {
        pulse($host, 1, true);
    } catch (RuntimeException $e) {
        $noLeak = !str_contains($e->getMessage(), '9999');
        throw $e;
    }
}, 'wrong relay password'));
check('password absent from the error message', $noLeak);

exit($failures ? 1 : 0);
