<?php

declare(strict_types=1);

/**
 * Local UPI callback simulator (development tool).
 *
 * Signs a callback payload with the app's UPI_CALLBACK_SECRET and POSTs it to
 * organizer/upi-callback.php, exactly like the UPI network would. Lets you test
 * the full paid flow locally: register -> payment.php (QR) -> simulate callback
 * -> confirmed registration + receipt.
 *
 * Usage:
 *   php tools/simulate_upi_callback.php --order ORD_XXXX [--status SUCCESS]
 *   php tools/simulate_upi_callback.php --latest          # newest initiated payment
 *
 * Options:
 *   --order <id>    Merchant order ID to settle (required unless --latest)
 *   --latest        Use the most recent 'initiated' payment in the DB
 *   --status        SUCCESS (default) | FAILED | EXPIRED | PENDING
 *   --amount <n>    Override amount (default: stored payment amount)
 *   --payer <vpa>   Payer VPA (default: student@upi)
 *   --url <base>    App base URL (default: BASE_URL from config)
 *   --list          Show recent payments and exit
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/upi_service.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only.\n");
}

// ── Parse args ──────────────────────────────────────────────────────────
$argv0 = array_shift($argv);
$opts = ['order' => null, 'latest' => false, 'status' => 'SUCCESS', 'amount' => null, 'payer' => 'student@upi', 'url' => null, 'list' => false];
while ($argv !== []) {
    $arg = array_shift($argv);
    $val = null;
    if (str_contains($arg, '=')) {
        [$arg, $val] = explode('=', $arg, 2);
    } elseif ($argv !== [] && !str_starts_with((string)$argv[0], '--')) {
        $val = array_shift($argv);
    }
    switch (ltrim($arg, '-')) {
        case 'order':  $opts['order'] = $val; break;
        case 'latest': $opts['latest'] = true; break;
        case 'status': $opts['status'] = strtoupper((string)$val); break;
        case 'amount': $opts['amount'] = $val; break;
        case 'payer':  $opts['payer'] = $val; break;
        case 'url':    $opts['url'] = $val; break;
        case 'list':   $opts['list'] = true; break;
        default:
            fwrite(STDERR, "Unknown option: $arg\n\n" . preg_replace('/^ \/.*?\n/m', '', "See file header for usage.\n"));
            exit(2);
    }
}

$db = getDB();
$sel = static function (string $sql, array $params = []) use ($db) {
    if ($db instanceof PDO) {
        $st = $db->prepare($sql);
        $st->execute($params);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row === false ? null : $row;
    }
    $st = mysqli_prepare($db, $sql);
    if ($params !== []) {
        $types = str_repeat('s', count($params));
        mysqli_stmt_bind_param($st, $types, ...$params);
    }
    mysqli_stmt_execute($st);
    $row = mysqli_stmt_get_result($st)->fetch_assoc();
    return $row === false ? null : $row;
};

// ── --list mode ─────────────────────────────────────────────────────────
if ($opts['list']) {
    echo "Recent payments:\n";
    $rows = [];
    if ($db instanceof PDO) {
        $rows = $db->query('SELECT merchant_order_id, amount, status, created_at FROM payments ORDER BY created_at DESC LIMIT 10')->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $res = mysqli_query($db, 'SELECT merchant_order_id, amount, status, created_at FROM payments ORDER BY created_at DESC LIMIT 10');
        while ($r = mysqli_fetch_assoc($res)) { $rows[] = $r; }
    }
    foreach ($rows as $r) {
        printf("  %-24s %10s  %-9s  %s\n", $r['merchant_order_id'], $r['amount'], $r['status'], $r['created_at']);
    }
    if ($rows === []) {
        echo "  (none — register for a paid class first)\n";
    }
    exit(0);
}

// ── Resolve target payment ──────────────────────────────────────────────
$payment = null;
if ($opts['order'] !== null) {
    $payment = $sel('SELECT * FROM payments WHERE merchant_order_id = ?', [$opts['order']]);
    if (!$payment) {
        fwrite(STDERR, "No payment found for order {$opts['order']}\n");
        exit(1);
    }
} elseif ($opts['latest']) {
    $payment = $sel("SELECT * FROM payments WHERE status = 'initiated' ORDER BY created_at DESC LIMIT 1");
    if (!$payment) {
        fwrite(STDERR, "No 'initiated' payment found. Register for a paid class first (or run with --list).\n");
        exit(1);
    }
} else {
    fwrite(STDERR, "Specify --order ORD_XXXX or --latest (see header for usage).\n");
    exit(2);
}

if (in_array($payment['status'], ['success', 'failed', 'expired'], true)) {
    fwrite(STDERR, "Payment {$payment['merchant_order_id']} is already final ({$payment['status']}). Callbacks are idempotent — this will be a no-op.\n");
}

// ── Build + sign payload ────────────────────────────────────────────────
$amount = $opts['amount'] !== null ? number_format((float)$opts['amount'], 2, '.', '') : (string)$payment['amount'];
$payload = [
    'txnId' => 'SIM' . strtoupper(bin2hex(random_bytes(6))),
    'merchantOrderId' => (string)$payment['merchant_order_id'],
    'amount' => $amount,
    'status' => $opts['status'],
    'timestamp' => (string)time(),
    'payerVpa' => $opts['payer'],
    'payeeVpa' => UPI_MERCHANT_VPA,
];
$signature = UpiService::signCallback($payload);
$body = json_encode($payload + ['signature' => $signature]);

$base = rtrim($opts['url'] ?: (defined('BASE_URL') ? BASE_URL : 'http://localhost:8000'), '/');
$endpoint = $base . '/organizer/upi-callback.php';

printf(
    "Payment  %s (amount %s, status now: %s)\nSimulating %s callback → %s\n",
    $payment['merchant_order_id'],
    $payment['amount'],
    $payment['status'],
    $opts['status'],
    $endpoint
);

// ── POST via HTTP so the real endpoint (rate limit, signature check, transaction) runs ──
try {
    [$code, $response] = httpPostJson($endpoint, $body);
} catch (Throwable $e) {
    fwrite(STDERR, "Request failed: " . $e->getMessage() . "\nIs the dev server running? Try:  php -S localhost:8000 -t .\n");
    exit(1);
}

echo "HTTP $code: $response\n";
exit($code >= 200 && $code < 300 ? 0 : 1);

/**
 * POST JSON to $endpoint. Tries curl, then the http:// stream wrapper,
 * then a raw TCP socket (works everywhere, handles chunked responses).
 *
 * @return array{0:int, 1:string} [statusCode, responseBody]
 */
function httpPostJson(string $endpoint, string $body): array
{
    $headers = ["Content-Type: application/json", "Accept: application/json", "Content-Length: " . strlen($body)];

    // 1) curl (when available)
    if (function_exists('curl_init')) {
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_CONNECTTIMEOUT => 5,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($resp !== false) {
            return [$code, (string)$resp];
        }
        throw new RuntimeException($err !== '' ? $err : 'curl request failed');
    }

    // 2) http:// stream wrapper
    $ctx = stream_context_create(['http' => [
        'method' => 'POST',
        'header' => implode("\r\n", $headers),
        'content' => $body,
        'timeout' => 10,
        'ignore_errors' => true,
    ]]);
    $resp = @file_get_contents($endpoint, false, $ctx);
    if ($resp !== false && isset($http_response_header[0]) && preg_match('#HTTP/1\.\d (\d{3})#', $http_response_header[0], $m)) {
        return [(int)$m[1], (string)$resp];
    }

    // 3) raw TCP socket
    $p = parse_url($endpoint);
    $host = $p['host'] ?? '127.0.0.1';
    $port = (int)($p['port'] ?? (($p['scheme'] ?? 'http') === 'https' ? 443 : 80));
    $path = ($p['path'] ?? '/') . (isset($p['query']) ? '?' . $p['query'] : '');
    $errNo = 0;
    $errStr = '';
    $sock = @stream_socket_client("tcp://$host:$port", $errNo, $errStr, 10);
    if (!$sock) {
        throw new RuntimeException("connect to $host:$port failed — $errStr");
    }
    stream_set_timeout($sock, 10);
    $req = "POST $path HTTP/1.1\r\nHost: $host:$port\r\n" . implode("\r\n", $headers) . "\r\nConnection: close\r\n\r\n$body";
    fwrite($sock, $req);
    $raw = '';
    while (!feof($sock)) {
        $chunk = fgets($sock, 8192);
        if ($chunk === false) break;
        $raw .= $chunk;
    }
    fclose($sock);
    if (!preg_match('#^HTTP/1\.\d (\d{3})#', $raw, $m)) {
        throw new RuntimeException('no valid HTTP response (is the server running?)');
    }
    $code = (int)$m[1];
    $sep = strpos($raw, "\r\n\r\n");
    $respBody = $sep === false ? '' : substr($raw, $sep + 4);
    // Decode chunked transfer encoding if present
    if (stripos(substr($raw, 0, (int)$sep), 'transfer-encoding: chunked') !== false) {
        $decoded = '';
        $pos = 0;
        while ($pos < strlen($respBody)) {
            $lineEnd = strpos($respBody, "\r\n", $pos);
            if ($lineEnd === false) break;
            $size = (int)hexdec(trim(substr($respBody, $pos, $lineEnd - $pos)));
            if ($size === 0) break;
            $decoded .= substr($respBody, $lineEnd + 2, $size);
            $pos = $lineEnd + 2 + $size + 2;
        }
        $respBody = $decoded;
    }
    return [$code, $respBody];
}
