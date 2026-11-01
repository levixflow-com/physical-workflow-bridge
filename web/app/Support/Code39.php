<?php

namespace App\Support;

// Code 39 needs no check digit and any USB reader understands it out of the box.
class Code39
{
    // Nine elements per character, bar and space in turn: n narrow, w wide.
    private const PATTERNS = [
        '0' => 'nnnwwnwnn', '1' => 'wnnwnnnnw', '2' => 'nnwwnnnnw', '3' => 'wnwwnnnnn', '4' => 'nnnwwnnnw',
        '5' => 'wnnwwnnnn', '6' => 'nnwwwnnnn', '7' => 'nnnwnnwnw', '8' => 'wnnwnnwnn', '9' => 'nnwwnnwnn',
        'A' => 'wnnnnwnnw', 'B' => 'nnwnnwnnw', 'C' => 'wnwnnwnnn', 'D' => 'nnnnwwnnw', 'E' => 'wnnnwwnnn',
        'F' => 'nnwnwwnnn', 'G' => 'nnnnnwwnw', 'H' => 'wnnnnwwnn', 'I' => 'nnwnnwwnn', 'J' => 'nnnnwwwnn',
        'K' => 'wnnnnnnww', 'L' => 'nnwnnnnww', 'M' => 'wnwnnnnwn', 'N' => 'nnnnwnnww', 'O' => 'wnnnwnnwn',
        'P' => 'nnwnwnnwn', 'Q' => 'nnnnnnwww', 'R' => 'wnnnnnwwn', 'S' => 'nnwnnnwwn', 'T' => 'nnnnwnwwn',
        'U' => 'wwnnnnnnw', 'V' => 'nwwnnnnnw', 'W' => 'wwwnnnnnn', 'X' => 'nwnnwnnnw', 'Y' => 'wwnnwnnnn',
        'Z' => 'nwwnwnnnn', '-' => 'nwnnnnwnw', '*' => 'nwnnwnwnn',
    ];

    // Returns the bars as [x, width] in narrow-element units, plus the total width.
    public static function bars(string $text): array
    {
        $x = 0;
        $bars = [];

        foreach (str_split('*'.strtoupper($text).'*') as $char) {
            foreach (str_split(self::PATTERNS[$char]) as $i => $element) {
                $width = $element === 'w' ? 3 : 1;
                if ($i % 2 === 0) {
                    $bars[] = [$x, $width];
                }
                $x += $width;
            }
            $x += 1;
        }

        return [$bars, $x - 1];
    }
}
