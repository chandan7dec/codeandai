<?php

declare(strict_types=1);

/**
 * Dev helper: dump my QR matrix alongside the reference matrix produced by
 * python `qrcode` for the same payload, to debug encoding differences.
 *
 * Usage: php tools/dump_qr.php <payload>
 */

require __DIR__ . '/../includes/lib/qr_encoder.php';

$payload = $argv[1] ?? 'Hello';
$matrix = Freebuff\QR\QRCode::matrix($payload);
file_put_contents(__DIR__ . '/../data/mine_matrix.json', json_encode($matrix));
echo 'dumped mine_matrix.json for payload: ' . $payload . PHP_EOL;
