<?php
/**
 * Draws the game logo: ANSI block art on a 60 x 30 character grid, written
 * out as an SVG so it looks the same on every device rather than depending on
 * whichever monospace font a phone happens to have.
 *
 *   php tools/make-logo.php
 *
 * Writes assets/img/ido-logo.svg, and prints the art as plain text so a change
 * can be checked in a terminal before anybody opens a browser. Edit the art
 * below and run it again; the SVG is never edited by hand.
 *
 * The same approach as Imperial Barons Online, ported to PHP so it runs on the
 * PHP that every WordPress install already has.
 */
if (PHP_SAPI !== 'cli') exit("CLI only.\n");

const W  = 60;   // columns
const H  = 30;   // rows
const CW = 10;   // cell width in the SVG
const CH = 20;   // cell height in the SVG

/** class => [colour, glows?] */
const PALETTE = [
    ''       => ['#1a2140', false],
    'frame'  => ['#ffc861', true],    // gilt frame, the game's amber
    'ramp'   => ['#4a1f6b', false],   // imperial purple
    'star'   => ['#cfd8ff', false],
    'shadow' => ['#2a1f55', false],
    'cyan'   => ['#4fd6ff', true],    // IMPERIAL, the game's accent
    'gold'   => ['#ffc23a', true],    // DOMINION, the eagle
    'red'    => ['#ff5c7a', true],    // ONLINE
    'marble' => ['#e6dfcc', false],
    'roof'   => ['#d0643f', false],
    'banner' => ['#c8102e', false],
    'wood'   => ['#b07a3c', false],
    'stone'  => ['#9aa3b2', false],
    'fire'   => ['#ff8c2a', true],
    'w1'     => ['#57d98a', true],
    'w2'     => ['#4fd6ff', true],
    'w3'     => ['#ffc23a', true],
    'w4'     => ['#ff5c7a', true],
];

$grid = array_fill(0, H, array_fill(0, W, [' ', '']));

/** Writes a string; spaces are transparent unless $blank. */
function put(int $r, int $c, string $s, string $cls, bool $blank = false): void {
    global $grid;
    foreach (mb_str_split($s) as $i => $ch) {
        if (($ch !== ' ' || $blank) && $c + $i >= 0 && $c + $i < W) {
            $grid[$r][$c + $i] = [$ch, $ch === ' ' ? '' : $cls];
        }
    }
}

/** Draws art whose colours come from a parallel map of single-letter keys. */
function put_map(int $r, int $c, array $rows, array $map, array $key): void {
    global $grid;
    foreach ($rows as $dr => $line) {
        $colours = mb_str_split($map[$dr]);
        foreach (mb_str_split($line) as $dc => $ch) {
            if ($ch === ' ') continue;
            $grid[$r + $dr][$c + $dc] = [$ch, $key[$colours[$dc] ?? ''] ?? ''];
        }
    }
}

const FONT = [
    'I' => ['█████', '  █  ', '  █  ', '  █  ', '█████'],
    'M' => ['█   █', '██ ██', '█ █ █', '█   █', '█   █'],
    'P' => ['████ ', '█   █', '████ ', '█    ', '█    '],
    'E' => ['█████', '█    ', '████ ', '█    ', '█████'],
    'R' => ['████ ', '█   █', '████ ', '█  █ ', '█   █'],
    'A' => [' ███ ', '█   █', '█████', '█   █', '█   █'],
    'L' => ['█    ', '█    ', '█    ', '█    ', '█████'],
    'D' => ['████ ', '█   █', '█   █', '█   █', '████ '],
    'O' => [' ███ ', '█   █', '█   █', '█   █', ' ███ '],
    'N' => ['█   █', '██  █', '█ █ █', '█  ██', '█   █'],
];

/** A word in the block font, centred, with a drop shadow down and right. */
function word(int $r, string $text, string $cls, int $gap): void {
    global $grid;
    $len = strlen($text);
    $c0  = intdiv(W - ($len * 5 + ($len - 1) * $gap), 2);
    $solid = [];
    for ($i = 0; $i < $len; $i++) {
        foreach (FONT[$text[$i]] as $dr => $line) {
            foreach (mb_str_split($line) as $dc => $px) {
                if ($px === '█') $solid[($r + $dr) . ',' . ($c0 + $i * (5 + $gap) + $dc)] = true;
            }
        }
    }
    foreach (array_keys($solid) as $at) {
        [$y, $x] = array_map('intval', explode(',', $at));
        if (!isset($solid[($y + 1) . ',' . ($x + 1)]) && $grid[$y + 1][$x + 1][0] === ' ') {
            $grid[$y + 1][$x + 1] = ['░', 'shadow'];
        }
    }
    foreach (array_keys($solid) as $at) {
        [$y, $x] = array_map('intval', explode(',', $at));
        $grid[$y][$x] = ['█', $cls];
    }
}

// -- The art -----------------------------------------------------------------

// Frame, with a purple ramp along the top and bottom.
put(0, 0, '╔' . str_repeat('═', W - 2) . '╗', 'frame');
put(H - 1, 0, '╚' . str_repeat('═', W - 2) . '╝', 'frame');
for ($r = 1; $r < H - 1; $r++) { put($r, 0, '║', 'frame'); put($r, W - 1, '║', 'frame'); }
$ramp = mb_substr(str_repeat('░▒▓█▓▒░', 9), 0, W - 2);
put(1, 1, $ramp, 'ramp');
put(H - 2, 1, implode('', array_reverse(mb_str_split($ramp))), 'ramp');

// A night sky over the city.
foreach ([[2, 6, '·'], [2, 19, '+'], [2, 33, '·'], [2, 47, '*'], [2, 54, '·'], [9, 4, '*'], [9, 15, '·'],
          [9, 29, '+'], [9, 42, '·'], [9, 55, '*'], [16, 8, '·'], [16, 24, '*'], [16, 38, '·'], [16, 51, '+'],
          [20, 20, '·'], [18, 37, '*'], [22, 57, '·'], [26, 2, '·'], [27, 56, '*']] as [$r, $c, $ch]) {
    put($r, $c, $ch, 'star');
}

word(3, 'IMPERIAL', 'cyan', 1);    // 8 * 5 + 7 = 47 wide
word(10, 'DOMINION', 'gold', 1);   // the same width, so the two lines sit square
put(17, 1, str_repeat('═', W - 2), 'frame');
put(17, 0, '╠', 'frame');
put(17, W - 1, '╣', 'frame');
put(17, 22, '  O N L I N E  ', 'red', true);

// The scene: a temple, the eagle standard, and a catapult loosing a stone.
put_map(19, 3, [
    '     ▄▄█▄▄     ',
    '  ▄▄███████▄▄  ',
    ' ▀▀▀▀▀▀▀▀▀▀▀▀▀ ',
    '  █ █ █ █ █ █  ',
    '  █ █ █ █ █ █  ',
    ' ▄█▄█▄█▄█▄█▄█▄ ',
], [
    '     rrrrr     ',
    '  rrrrrrrrrrr  ',
    ' mmmmmmmmmmmmm ',
    '  m m m m m m  ',
    '  m m m m m m  ',
    ' mmmmmmmmmmmmm ',
], ['r' => 'roof', 'm' => 'marble']);

put_map(19, 23, [
    '▄▄▄   ▄█▄   ▄▄▄',
    '▀███▄▄███▄▄███▀',
    '  ▀▀▀█████▀▀▀  ',
    '     ▄▄█▄▄     ',
    '    ▓SPQR▓     ',
    '    ▀▀▀█▀▀     ',
], [
    'ggg   ggg   ggg',
    'ggggggggggggggg',
    '  ggggggggggg  ',
    '     ggggg     ',
    '    btttttb    ',
    '    bbbwbb     ',
], ['g' => 'gold', 'b' => 'banner', 't' => 'gold', 'w' => 'wood']);

put_map(19, 42, [
    ' ▄█▄         ',
    ' ▀█▀▄▄       ',
    '      ▀▀▄▄   ',
    '   ▄▄▄▄▄▄█▄▄ ',
    '  ███████████',
    '  ▀█▀     ▀█▀',
], [
    ' fff         ',
    ' fffww       ',
    '      wwww   ',
    '   wwwwwwwww ',
    '  wwwwwwwwwww',
    '  sss     sss',
], ['f' => 'fire', 'w' => 'wood', 's' => 'stone']);

$tag  = [['S E T T L E', 'w1'], ['B U I L D', 'w2'], ['M A R C H', 'w3'], ['C O N Q U E R', 'w4']];
$text = implode('   ', array_column($tag, 0));
$c    = intdiv(W - strlen($text), 2);
foreach ($tag as [$t, $cls]) { put(26, $c, $t, $cls); $c += strlen($t) + 3; }

// -- The SVG -------------------------------------------------------------------

const SHADE = ['█' => 1.0, '▓' => 0.75, '▒' => 0.5, '░' => 0.25];
const HALF  = ['▀' => 0, '▄' => 1];
const SINGLE = ['─' => 'ew', '│' => 'ns', '┌' => 'es', '┐' => 'ws', '└' => 'ne', '┘' => 'nw',
                '├' => 'nse', '┤' => 'nsw', '┬' => 'ews', '┴' => 'ewn', '┼' => 'nesw'];
const DOUBLE = ['═' => 'ew', '║' => 'ns', '╔' => 'es', '╗' => 'ws', '╚' => 'ne', '╝' => 'nw',
                '╠' => 'nse', '╣' => 'nsw', '╦' => 'ews', '╩' => 'ewn', '╬' => 'nesw'];

/** The line segments for a box-drawing character's arms. */
function arms(float $x, float $y, string $spec, bool $double): array {
    $cx = $x + CW / 2; $cy = $y + CH / 2;
    $ends = ['n' => [$cx, $y], 's' => [$cx, $y + CH], 'e' => [$x + CW, $cy], 'w' => [$x, $cy]];
    $out = [];
    foreach (str_split($spec) as $a) {
        [$ex, $ey] = $ends[$a];
        if (!$double) { $out[] = [$cx, $cy, $ex, $ey]; continue; }
        $d = 1.6;
        if ($a === 'n' || $a === 's') {
            $out[] = [$cx - $d, $cy, $ex - $d, $ey]; $out[] = [$cx + $d, $cy, $ex + $d, $ey];
        } else {
            $out[] = [$cx, $cy - $d, $ex, $ey - $d]; $out[] = [$cx, $cy + $d, $ex, $ey + $d];
        }
    }
    return $out;
}

$plain = []; $glow = [];
foreach ($grid as $r => $row) {
    foreach ($row as $c => [$ch, $cls]) {
        if ($ch === ' ') continue;
        $x = $c * CW; $y = $r * CH;
        [$colour, $glows] = PALETTE[$cls] ?? PALETTE[''];
        if ($glows) { $bucket = &$glow; } else { $bucket = &$plain; }
        if (isset(SHADE[$ch])) {
            $bucket[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="%s" fill-opacity="%.2f"/>',
                $x, $y, CW, CH, $colour, SHADE[$ch]);
        } elseif (isset(HALF[$ch])) {
            $bucket[] = sprintf('<rect x="%d" y="%d" width="%d" height="%d" fill="%s"/>',
                $x, $y + HALF[$ch] * CH / 2, CW, CH / 2, $colour);
        } elseif (isset(SINGLE[$ch]) || isset(DOUBLE[$ch])) {
            $double = isset(DOUBLE[$ch]);
            foreach (arms($x, $y, $double ? DOUBLE[$ch] : SINGLE[$ch], $double) as [$x1, $y1, $x2, $y2]) {
                $bucket[] = sprintf('<line x1="%.1f" y1="%.1f" x2="%.1f" y2="%.1f" stroke="%s" stroke-width="%s"/>',
                    $x1, $y1, $x2, $y2, $colour, $double ? '1' : '1.4');
            }
        } else {
            $bucket[] = sprintf('<text x="%.1f" y="%.1f" fill="%s">%s</text>',
                $x + CW / 2, $y + CH * 0.72, $colour, htmlspecialchars($ch, ENT_XML1));
        }
        unset($bucket);
    }
}

$font = 'font-family="Consolas, \'DejaVu Sans Mono\', Menlo, monospace" font-size="16" font-weight="700" text-anchor="middle"';
$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 600 600" width="600" height="600" role="img" aria-label="Imperial Dominion Online">
<!-- Generated by tools/make-logo.php. Edit the art there and run it again rather than editing this file. -->
<defs>
  <radialGradient id="g1" cx="20%" cy="15%" r="70%"><stop offset="0" stop-color="#4a1f8c" stop-opacity=".55"/><stop offset=".6" stop-color="#05030c" stop-opacity="0"/></radialGradient>
  <radialGradient id="g2" cx="85%" cy="90%" r="65%"><stop offset="0" stop-color="#8c1a1a" stop-opacity=".35"/><stop offset=".6" stop-color="#05030c" stop-opacity="0"/></radialGradient>
  <radialGradient id="g3" cx="50%" cy="10%" r="100%"><stop offset="0" stop-color="#14183a"/><stop offset=".48" stop-color="#0b0f14"/><stop offset="1" stop-color="#020308"/></radialGradient>
  <radialGradient id="vig" cx="50%" cy="45%" r="75%"><stop offset=".55" stop-color="#000" stop-opacity="0"/><stop offset="1" stop-color="#000" stop-opacity=".5"/></radialGradient>
  <pattern id="scan" width="3" height="3" patternUnits="userSpaceOnUse"><rect width="3" height="1" fill="#000" fill-opacity=".25"/></pattern>
  <filter id="glow" x="-10%" y="-10%" width="120%" height="120%"><feGaussianBlur stdDeviation="2.2" result="b"/><feMerge><feMergeNode in="b"/><feMergeNode in="SourceGraphic"/></feMerge></filter>
</defs>
<rect width="600" height="600" fill="url(#g3)"/>
<rect width="600" height="600" fill="url(#g1)"/>
<rect width="600" height="600" fill="url(#g2)"/>
<g ' . $font . ' shape-rendering="crispEdges">
' . implode("\n", $plain) . '
</g>
<g ' . $font . ' filter="url(#glow)">
' . implode("\n", $glow) . '
</g>
<rect width="600" height="600" fill="url(#scan)"/>
<rect width="600" height="600" fill="url(#vig)"/>
</svg>
';

$out = dirname(__DIR__) . '/assets/img/ido-logo.svg';
if (!is_dir(dirname($out))) mkdir(dirname($out), 0755, true);
file_put_contents($out, $svg);

foreach ($grid as $row) echo implode('', array_column($row, 0)), "\n";
printf("wrote %s: %d shapes, %d bytes\n", $out, count($plain) + count($glow), strlen($svg));
