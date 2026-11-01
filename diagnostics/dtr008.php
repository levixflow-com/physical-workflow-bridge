<?php
// DT-R008 diagnostics over HTTP CGI (Programming Manual V2.0.2, chapter 3). Needs only the curl extension.
//   php dtr008.php status <host>
//   php dtr008.php pulse <host> <relay 1-8> --confirm

const TIMEOUT_S = 2;
const CONFIRM_WINDOW_S = 3;

function fields(string $body): array
{
    $f = explode('&', trim(trim($body), '&'));
    // Observed on firmware V3.1.2178A; the manual says the board does not reply.
    if ($f[0] === '-7') {
        throw new RuntimeException('wrong relay password: command refused by the board');
    }
    if ($f[0] !== '0') {
        throw new RuntimeException("the board replied with an error: $body (302 = Session check on)");
    }
    return $f;
}

// Result indexed from 1, like the board's terminals.
function bits(array $values, int $count, string $body): array
{
    if ($count < 1 || count($values) !== $count || array_diff($values, ['0', '1'])) {
        throw new RuntimeException("invalid reply: $body");
    }
    return array_combine(range(1, $count), array_map('intval', $values));
}

// &0&8&r1&...&r8&
function parseRelays(string $body): array
{
    $f = fields($body);
    return bits(array_slice($f, 2), (int) ($f[1] ?? 0), $body);
}

// &0&0&8&i1&...&i8&
function parseInputs(string $body): array
{
    $f = fields($body);
    return bits(array_slice($f, 3), (int) ($f[2] ?? 0), $body);
}

function get(string $host, string $path): string
{
    // curl, not file_get_contents: the board keeps the connection open and PHP would wait until the timeout.
    $curl = curl_init("http://$host/$path");
    curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => TIMEOUT_S]);
    $body = curl_exec($curl);
    if ($body === false) {
        // The password stays out of the message: strtok removes the query string.
        throw new RuntimeException('no reply from ' . strtok($path, '?'));
    }
    return $body;
}

function readBoard(string $host): array
{
    return [
        'relays' => parseRelays(get($host, 'relay_cgi_load.cgi')),
        'inputs' => parseInputs(get($host, 'input.cgi')),
    ];
}

function relayPassword(): string
{
    $pwd = getenv('DTR008_RELAY_PASSWORD') ?: '0';
    if (!ctype_digit($pwd) || (int) $pwd > 9999) {
        throw new RuntimeException('DTR008_RELAY_PASSWORD must be a number from 0 to 9999');
    }
    return $pwd;
}

// 500 ms jogging only: the board returns the relay to rest by itself even if this script stops.
function pulse(string $host, int $relay, bool $confirmed): string
{
    if (!$confirmed) {
        throw new RuntimeException('command not sent: add --confirm after checking that the relay has no critical load');
    }
    $before = parseRelays(get($host, 'relay_cgi_load.cgi'));
    if (!isset($before[$relay])) {
        throw new RuntimeException("relay $relay does not exist on this board");
    }
    if ($before[$relay] === 1) {
        throw new RuntimeException("relay $relay is already ON: command not sent");
    }

    $index = $relay - 1;
    $reply = fields(get($host, "relay_cgi.cgi?type=1&relay=$index&on=1&time=5&pwd=" . relayPassword() . '&'));
    if (array_slice($reply, 1, 3) !== ['1', (string) $index, '1']) {
        throw new RuntimeException('the reply does not match the command: ' . implode('&', $reply));
    }

    $sawOn = false;
    $deadline = microtime(true) + CONFIRM_WINDOW_S;
    while (microtime(true) < $deadline) {
        try {
            // The manual's r.N does not answer on firmware V3.1.2178A: read the full state again.
            $on = parseRelays(get($host, 'relay_cgi_load.cgi'))[$relay] === 1;
        } catch (RuntimeException) {
            // The command was already accepted: a lost read is not a password problem.
            continue;
        }
        if ($on) {
            $sawOn = true;
        } elseif ($sawOn) {
            return "CONFIRMED: relay $relay seen ON and back to OFF";
        }
    }
    throw new RuntimeException($sawOn
        ? "NOT CONFIRMED: relay $relay still ON after " . CONFIRM_WINDOW_S . ' s, check it now'
        : "NOT CONFIRMED: command accepted, but relay $relay was never seen ON");
}

function main(array $argv): int
{
    $args = array_values(array_filter(array_slice($argv, 1), fn ($a) => $a !== '--confirm'));
    try {
        switch ($args[0] ?? '') {
            case 'status':
                $board = readBoard($args[1] ?? throw new RuntimeException('missing <host>'));
                echo 'Relays ' . implode(' ', array_map(fn ($v) => $v ? 'ON ' : 'off', $board['relays'])) . "\n";
                echo 'Inputs ' . implode(' ', array_map(fn ($v) => $v ? 'H  ' : 'L  ', $board['inputs'])) . "\n";
                return 0;
            case 'pulse':
                if (!isset($args[2]) || !ctype_digit($args[2])) {
                    throw new RuntimeException('usage: pulse <host> <relay> --confirm');
                }
                echo pulse($args[1], (int) $args[2], in_array('--confirm', $argv, true)) . "\n";
                return 0;
            default:
                fwrite(STDERR, "usage: status <host> | pulse <host> <relay> --confirm\n");
                return 2;
        }
    } catch (RuntimeException $e) {
        fwrite(STDERR, "ERROR: {$e->getMessage()}\n");
        return 1;
    }
}

if (realpath($_SERVER['argv'][0]) === __FILE__) {
    exit(main($argv));
}
