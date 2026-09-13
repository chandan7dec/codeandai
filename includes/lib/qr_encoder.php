<?php

declare(strict_types=1);

/**
 * Self-contained QR Code encoder (Model 2, byte mode, error correction L).
 *
 * Pure PHP, no external dependencies. Produces PNG images by hand-rolling
 * minimal PNG chunks with zlib (both always available in PHP).
 *
 * Used by UpiService to render UPI payment QR codes without requiring the
 * chillerlan/php-qrcode composer package (which needs network access to
 * install). Supports versions 1-6 (up to 134 payload bytes at ECC L), which
 * comfortably fits UPI payment URIs. Output is a spec-compliant QR Code that
 * any UPI app can scan; it is not intended to be a general-purpose QR library.
 *
 * Spec references: ISO/IEC 18004:2015 (QR Code Model 2).
 */

namespace Freebuff\QR;

final class QRCode
{
    /** Byte mode indicator (ISO/IEC 18004 §8.4.4): binary 0100. */
    private const MODE_BYTE = 0b0100;

    /** Format-info ECC indicator for level L (ISO/IEC 18004 §8.9: L = 01). */
    private const FORMAT_ECC_L = 0b01;

    /** Data capacity in BYTES for byte mode, ECC L, versions 1-7. */
    private const BYTE_CAPACITIES = [
        1 => 17, 2 => 32, 3 => 53, 4 => 78, 5 => 106, 6 => 134, 7 => 156,
    ];

    /**
     * RS block structure for ECC L: version => list of [data codewords, ecc codewords, group count].
     * ISO/IEC 18004 tables 13-16, level L.
     */
    private const BLOCKS_L = [
        1 => [[19, 7, 1]],
        2 => [[34, 10, 1]],
        3 => [[55, 15, 1]],
        4 => [[80, 20, 1]],
        5 => [[108, 26, 1]],
        6 => [[68, 18, 2]],
        7 => [[78, 20, 2]],
    ];

    /** Alignment pattern center coordinates per version (Annex E). */
    private const ALIGNMENT_CENTERS = [
        2 => [6, 18],
        3 => [6, 22],
        4 => [6, 26],
        5 => [6, 30],
        6 => [6, 34],
        7 => [6, 22, 38],
    ];

    private function __construct()
    {
    }

    /**
     * Encode $data into a PNG image (black modules on white background).
     */
    public static function png(string $data, int $moduleSize = 6, int $quietZone = 4): string
    {
        $matrix = self::matrix($data);
        $count = count($matrix);
        $dimension = $count + $quietZone * 2;
        $size = $dimension * $moduleSize;

        // 1 byte per pixel; palette index 0 = white, 1 = black.
        $pixels = str_repeat("\x00", $size * $size);
        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if (!$matrix[$row][$col]) {
                    continue;
                }
                for ($dy = 0; $dy < $moduleSize; $dy++) {
                    $y = ($row + $quietZone) * $moduleSize + $dy;
                    $x = ($col + $quietZone) * $moduleSize;
                    $offset = $y * $size + $x;
                    $pixels = substr_replace(
                        $pixels,
                        str_repeat("\x01", $moduleSize),
                        $offset,
                        $moduleSize
                    );
                }
            }
        }

        return self::buildPng($pixels, $size, $size);
    }

    /**
     * Encode $data into a module matrix (true = dark module).
     *
     * @param int|null $forceMask testing aid: force a specific mask pattern (0-7)
     *                            instead of automatic penalty-based selection.
     * @return array<int, array<int, bool>>
     */
    public static function matrix(string $data, ?int $forceMask = null): array
    {
        $version = self::selectVersion($data);
        $size = 17 + $version * 4;

        $codewords = self::buildCodewords($data, $version);
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        self::drawFunctionPatterns($modules, $reserved, $version, $size);
        self::placeData($modules, $reserved, $codewords, $size, $version);

        $mask = $forceMask ?? self::bestMask($modules, $reserved, $size);
        self::applyMask($modules, $reserved, $mask, $size);
        self::drawFormatInfo($modules, $size, $mask);

        return $modules;
    }

    private static function selectVersion(string $data): int
    {
        $length = strlen($data);
        foreach (self::BYTE_CAPACITIES as $version => $capacityBytes) {
            if ($length <= $capacityBytes) {
                return $version;
            }
        }
        throw new \OverflowException(
            'QR payload too long (' . $length . ' bytes) for supported versions 1-6 (max 134 bytes, ECC L).'
        );
    }

    /**
     * Full codeword stream: data bits + terminator + pad + ECC, interleaved.
     *
     * @return int[] bytes
     */
    private static function buildCodewords(string $data, int $version): array
    {
        $blocks = [];
        foreach (self::BLOCKS_L[$version] as [$dataCw, $eccCw, $groups]) {
            for ($i = 0; $i < $groups; $i++) {
                $blocks[] = ['data' => $dataCw, 'ecc' => $eccCw];
            }
        }

        $totalDataCodewords = 0;
        foreach ($blocks as $block) {
            $totalDataCodewords += $block['data'];
        }

        // ── Data bit stream ──
        $bits = self::padBinary(self::MODE_BYTE, 4);
        $bits .= self::padBinary(strlen($data), $version < 10 ? 8 : 16);
        $dataLength = strlen($data);
        for ($i = 0; $i < $dataLength; $i++) {
            $bits .= self::padBinary(ord($data[$i]), 8);
        }

        // Terminator (up to 4 zero bits), then pad to a byte boundary.
        $capacityBits = $totalDataCodewords * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        $bits = str_pad($bits, (int)(ceil(strlen($bits) / 8) * 8), '0');

        // Pad codewords: 11101100 / 00010001 alternating.
        $padBytes = ['11101100', '00010001'];
        $padIndex = 0;
        while (strlen($bits) < $capacityBits) {
            $bits .= $padBytes[$padIndex % 2];
            $padIndex++;
        }

        $dataCodewords = [];
        for ($i = 0; $i < $totalDataCodewords; $i++) {
            $dataCodewords[] = bindec(substr($bits, $i * 8, 8));
        }

        // ── Split into blocks + compute RS ECC ──
        $dataBlocks = [];
        $eccBlocks = [];
        $offset = 0;
        foreach ($blocks as $block) {
            $chunk = array_slice($dataCodewords, $offset, $block['data']);
            $offset += $block['data'];
            $dataBlocks[] = $chunk;
            $eccBlocks[] = self::reedSolomon($chunk, $block['ecc']);
        }

        // ── Interleave data then ECC codewords ──
        $result = [];
        $maxData = max(array_map('count', $dataBlocks));
        for ($i = 0; $i < $maxData; $i++) {
            foreach ($dataBlocks as $chunk) {
                if ($i < count($chunk)) {
                    $result[] = $chunk[$i];
                }
            }
        }
        $maxEcc = max(array_map('count', $eccBlocks));
        for ($i = 0; $i < $maxEcc; $i++) {
            foreach ($eccBlocks as $chunk) {
                if ($i < count($chunk)) {
                    $result[] = $chunk[$i];
                }
            }
        }

        return $result;
    }

    private static function padBinary(int $value, int $length): string
    {
        return str_pad(decbin($value), $length, '0', STR_PAD_LEFT);
    }

    /**
     * Reed-Solomon ECC using GF(256) arithmetic (poly 0x11d).
     *
     * @param int[] $data
     * @return int[] ecc codewords
     */
    private static function reedSolomon(array $data, int $eccCount): array
    {
        $generator = self::rsGenerator($eccCount); // [1, c(n-1), ..., c0]
        $result = array_fill(0, $eccCount, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ $result[0];
            array_shift($result);
            $result[] = 0;
            for ($i = 0; $i < $eccCount; $i++) {
                // generator[i + 1] skips the leading x^n coefficient.
                $result[$i] ^= self::gmul($generator[$i + 1], $factor);
            }
        }

        return $result;
    }

    /**
     * @return int[] generator polynomial coefficients in descending order,
     *               INCLUDING the leading 1: [1, c(n-1), ..., c0]
     */
    private static function rsGenerator(int $eccCount): array
    {
        $generator = [1]; // descending: polynomial "1"
        for ($i = 0; $i < $eccCount; $i++) {
            // Multiply the generator by (x + α^i).
            $next = array_fill(0, count($generator) + 1, 0);
            foreach ($generator as $j => $coef) {
                $next[$j] ^= $coef;                            // × x
                $next[$j + 1] ^= self::gmul($coef, self::gexp($i)); // × α^i
            }
            $generator = $next;
        }
        return $generator;
    }

    private static array $expTable = [];
    private static array $logTable = [];

    private static function initTables(): void
    {
        if (!empty(self::$expTable)) {
            return;
        }
        self::$expTable = array_fill(0, 512, 0);
        self::$logTable = array_fill(0, 256, 0);
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            self::$expTable[$i] = $x;
            self::$logTable[$x] = $i;
            $x <<= 1;
            if ($x & 0x100) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            self::$expTable[$i] = self::$expTable[$i - 255];
        }
    }

    private static function gexp(int $power): int
    {
        self::initTables();
        return self::$expTable[$power % 255];
    }

    private static function gmul(int $a, int $b): int
    {
        self::initTables();
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$expTable[self::$logTable[$a] + self::$logTable[$b]];
    }

    /**
     * Draw finder patterns, separators, timing patterns, alignment patterns
     * and reserve the format info areas.
     */
    private static function drawFunctionPatterns(array &$modules, array &$reserved, int $version, int $size): void
    {
        // Finder patterns + separators.
        $positions = [[0, 0], [$size - 7, 0], [0, $size - 7]];
        foreach ($positions as [$x, $y]) {
            self::drawFinderPattern($modules, $reserved, $x, $y);
        }

        // Timing patterns.
        for ($i = 8; $i < $size - 8; $i++) {
            $dark = $i % 2 === 0;
            if (!$reserved[6][$i]) {
                $modules[6][$i] = $dark;
                $reserved[6][$i] = true;
            }
            if (!$reserved[$i][6]) {
                $modules[$i][6] = $dark;
                $reserved[$i][6] = true;
            }
        }

        // Alignment patterns, skipping overlaps with finder patterns.
        if ($version >= 2) {
            $centers = self::ALIGNMENT_CENTERS[$version];
            $last = $centers[count($centers) - 1];
            foreach ($centers as $row) {
                foreach ($centers as $col) {
                    $overlapsFinder = ($row === 6 && $col === 6)
                        || ($row === 6 && $col === $last)
                        || ($row === $last && $col === 6);
                    if ($overlapsFinder) {
                        continue;
                    }
                    self::drawAlignmentPattern($modules, $reserved, $row, $col);
                }
            }
        }

        // Version information blocks (only version >= 7), two copies:
        // top-right (rows 0-5, cols size-11..size-9) and bottom-left
        // (rows size-11..size-9, cols 0-5).
        if ($version >= 7) {
            $rem = $version;
            for ($i = 0; $i < 12; $i++) {
                $rem = ($rem << 1) ^ (($rem >> 11) * 0x1f25);
            }
            $bits = ($version << 12) | $rem;
            for ($i = 0; $i < 6; $i++) {
                for ($j = 0; $j < 3; $j++) {
                    $dark = (($bits >> ($i * 3 + $j)) & 1) === 1;
                    $modules[$i][$size - 11 + $j] = $dark;
                    $reserved[$i][$size - 11 + $j] = true;
                    $modules[$size - 11 + $j][$i] = $dark;
                    $reserved[$size - 11 + $j][$i] = true;
                }
            }
        }

        // Reserve format info areas + dark module.
        for ($i = 0; $i < 9; $i++) {
            if ($i !== 6) {
                $reserved[8][$i] = true;
                $reserved[$i][8] = true;
            }
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $reserved[8][$i] = true;
            $reserved[$i][8] = true;
        }
        $modules[$size - 8][8] = true; // dark module (always dark)
    }

    private static function drawFinderPattern(array &$modules, array &$reserved, int $x, int $y): void
    {
        $size = count($modules);
        for ($dy = -1; $dy <= 7; $dy++) {
            for ($dx = -1; $dx <= 7; $dx++) {
                $row = $y + $dy;
                $col = $x + $dx;
                if ($row < 0 || $row >= $size || $col < 0 || $col >= $size) {
                    continue;
                }
                $reserved[$row][$col] = true;
                $inFinder = $dx >= 0 && $dx <= 6 && $dy >= 0 && $dy <= 6;
                if (!$inFinder) {
                    $modules[$row][$col] = false; // separator
                    continue;
                }
                // 7x7 dark border, 5x5 light ring, 3x3 dark center.
                $ring = max(abs($dx - 3), abs($dy - 3));
                $modules[$row][$col] = $ring !== 2;
            }
        }
    }

    private static function drawAlignmentPattern(array &$modules, array &$reserved, int $centerRow, int $centerCol): void
    {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $row = $centerRow + $dy;
                $col = $centerCol + $dx;
                $ring = max(abs($dx), abs($dy));
                $modules[$row][$col] = $ring !== 1;
                $reserved[$row][$col] = true;
            }
        }
    }

    /**
     * Place data codewords in the zigzag column pattern, skipping reserved modules.
     * Remainder bits (0 for v1, 7 for v2-6) are left light (zero bits).
     *
     * @param int[] $codewords
     */
    private static function placeData(array &$modules, array $reserved, array $codewords, int $size, int $version): void
    {
        $bitStream = '';
        foreach ($codewords as $byte) {
            $bitStream .= self::padBinary($byte & 0xff, 8);
        }

        $bitIndex = 0;
        $bitCount = strlen($bitStream);
        $upward = true;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right = 5; // skip the vertical timing column
            }
            for ($i = 0; $i < $size; $i++) {
                $row = $upward ? $size - 1 - $i : $i;
                for ($c = 0; $c < 2; $c++) {
                    $col = $right - $c;
                    if ($reserved[$row][$col]) {
                        continue;
                    }
                    $modules[$row][$col] = $bitIndex < $bitCount && $bitStream[$bitIndex] === '1';
                    $bitIndex++;
                }
            }
            $upward = !$upward;
        }
    }

    /**
     * Evaluate all 8 mask patterns and return the id with the lowest penalty score.
     */
    private static function bestMask(array $modules, array $reserved, int $size): int
    {
        $bestMask = 0;
        $bestPenalty = PHP_INT_MAX;
        for ($mask = 0; $mask < 8; $mask++) {
            $candidate = $modules;
            self::applyMask($candidate, $reserved, $mask, $size);
            $penalty = self::maskPenalty($candidate, $size);
            if ($penalty < $bestPenalty) {
                $bestPenalty = $penalty;
                $bestMask = $mask;
            }
        }
        return $bestMask;
    }

    private static function applyMask(array &$modules, array $reserved, int $mask, int $size): void
    {
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col < $size; $col++) {
                if ($reserved[$row][$col]) {
                    continue;
                }
                if (self::maskCondition($mask, $row, $col)) {
                    $modules[$row][$col] = !$modules[$row][$col];
                }
            }
        }
    }

    private static function maskCondition(int $mask, int $row, int $col): bool
    {
        switch ($mask) {
            case 0: return ($row + $col) % 2 === 0;
            case 1: return $row % 2 === 0;
            case 2: return $col % 3 === 0;
            case 3: return ($row + $col) % 3 === 0;
            case 4: return (intdiv($row, 2) + intdiv($col, 3)) % 2 === 0;
            case 5: return (($row * $col) % 2 + ($row * $col) % 3) === 0;
            case 6: return ((($row * $col) % 2 + ($row * $col) % 3) % 2) === 0;
            case 7: return ((($row + $col) % 2 + ($row * $col) % 3) % 2) === 0;
        }
        return false;
    }

    /**
     * ISO/IEC 18004 §8.8.2 penalty score (used only for mask selection).
     */
    private static function maskPenalty(array $modules, int $size): int
    {
        $penalty = 0;

        // Rule 1: runs of 5+ same-colour modules in rows/columns.
        for ($row = 0; $row < $size; $row++) {
            $penalty += self::runPenalty($modules[$row]);
        }
        for ($col = 0; $col < $size; $col++) {
            $column = [];
            for ($row = 0; $row < $size; $row++) {
                $column[] = $modules[$row][$col];
            }
            $penalty += self::runPenalty($column);
        }

        // Rule 2: 2x2 blocks of the same colour.
        for ($row = 0; $row < $size - 1; $row++) {
            for ($col = 0; $col < $size - 1; $col++) {
                $v = $modules[$row][$col];
                if ($v === $modules[$row][$col + 1]
                    && $v === $modules[$row + 1][$col]
                    && $v === $modules[$row + 1][$col + 1]) {
                    $penalty += 3;
                }
            }
        }

        // Rule 3: finder-like patterns 1011101 with 4 light modules on either side.
        $finder = [true, false, true, true, true, false, true];
        $light = [false, false, false, false];
        $patterns = [
            array_merge($finder, $light),
            array_merge($light, $finder),
        ];
        for ($row = 0; $row < $size; $row++) {
            for ($col = 0; $col <= $size - 11; $col++) {
                foreach ($patterns as $pattern) {
                    $match = true;
                    for ($i = 0; $i < 11; $i++) {
                        if ($modules[$row][$col + $i] !== $pattern[$i]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $penalty += 40;
                    }
                }
            }
        }
        for ($col = 0; $col < $size; $col++) {
            for ($row = 0; $row <= $size - 11; $row++) {
                foreach ($patterns as $pattern) {
                    $match = true;
                    for ($i = 0; $i < 11; $i++) {
                        if ($modules[$row + $i][$col] !== $pattern[$i]) {
                            $match = false;
                            break;
                        }
                    }
                    if ($match) {
                        $penalty += 40;
                    }
                }
            }
        }

        // Rule 4: dark-module proportion deviation from 50%.
        $darkCount = 0;
        foreach ($modules as $line) {
            foreach ($line as $module) {
                if ($module) {
                    $darkCount++;
                }
            }
        }
        $total = $size * $size;
        $percent = ($darkCount * 100) / $total;
        $prevMultiple = (int)(floor($percent / 5.0)) * 5;   // e.g. 45 for 48.2%
        $nextMultiple = $prevMultiple + 5;                  // e.g. 50
        $deviation = min(abs(100 - 2 * $prevMultiple), abs(100 - 2 * $nextMultiple));
        $penalty += $deviation * 10;

        return $penalty;
    }

    /** @param bool[] $line */
    private static function runPenalty(array $line): int
    {
        $penalty = 0;
        $run = 1;
        $count = count($line);
        for ($i = 1; $i < $count; $i++) {
            if ($line[$i] === $line[$i - 1]) {
                $run++;
            } else {
                if ($run >= 5) {
                    $penalty += 3 + ($run - 5);
                }
                $run = 1;
            }
        }
        if ($run >= 5) {
            $penalty += 3 + ($run - 5);
        }
        return $penalty;
    }

    private static function drawFormatInfo(array &$modules, int $size, int $mask): void
    {
        // Format info = 2 ECC bits + 3 mask bits + 10 BCH bits, XOR 0x5412.
        $data = (self::FORMAT_ECC_L << 3) | $mask;

        // BCH remainder: long-divide (data << 10) by generator 0x537.
        $rem = $data << 10;
        for ($i = 14; $i >= 10; $i--) {
            if (($rem >> $i) & 1) {
                $rem ^= 0x537 << ($i - 10);
            }
        }
        $bits = (($data << 10) | $rem) ^ 0x5412;

        $bit = static fn (int $i): bool => (($bits >> $i) & 1) === 1;

        // First copy (around the top-left finder).
        // Vertical strip in column 8: bits 0-5 at rows 0-5, bit 6 at row 7.
        for ($i = 0; $i <= 5; $i++) {
            $modules[$i][8] = $bit($i);
        }
        $modules[7][8] = $bit(6);
        $modules[8][8] = $bit(7);
        // Horizontal strip in row 8: bit 8 at column 7, bits 9-14 at columns 5-0.
        $modules[8][7] = $bit(8);
        for ($i = 9; $i < 15; $i++) {
            $modules[8][14 - $i] = $bit($i);
        }

        // Second copy (split below the top-right / right of the bottom-left finder).
        // Row 8 right side: bits 0-7 at columns size-1 .. size-8.
        for ($i = 0; $i < 8; $i++) {
            $modules[8][$size - 1 - $i] = $bit($i);
        }
        // Column 8 bottom side: bits 8-14 at rows size-7 .. size-1.
        for ($i = 8; $i < 15; $i++) {
            $modules[$size - 15 + $i][8] = $bit($i);
        }
        $modules[$size - 8][8] = true; // dark module (always dark)
    }

    /**
     * Encode pixels into a minimal valid PNG (8-bit indexed colour).
     *
     * @param string $pixels raw bytes, one byte per pixel (0=white, 1=black)
     */
    private static function buildPng(string $pixels, int $width, int $height): string
    {
        $raw = '';
        for ($row = 0; $row < $height; $row++) {
            $raw .= "\x00"; // filter type: none
            $raw .= substr($pixels, $row * $width, $width);
        }
        $idat = gzcompress($raw, 9);

        $chunk = static function (string $type, string $data): string {
            return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
        };

        // IHDR: width, height, bit depth 8, colour type 3 (palette), no compression/
        // filter/interlace overrides.
        $ihdr = pack('N2C5', $width, $height, 8, 3, 0, 0, 0);
        $plte = "\xff\xff\xff\x00\x00\x00"; // index 0 white, index 1 black

        return "\x89PNG\r\n\x1a\n"
            . $chunk('IHDR', $ihdr)
            . $chunk('PLTE', $plte)
            . $chunk('IDAT', $idat)
            . $chunk('IEND', '');
    }
}
