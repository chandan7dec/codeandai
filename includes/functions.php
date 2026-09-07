<?php
/**
 * Helper Functions
 * 
 * Common utilities used across the application.
 */

/**
 * Generate a UUID v4
 */
function generateUUID(): string {
    $data = random_bytes(16);
    
    // Set version to 0100
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    // Set variant to 10xx
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

/**
 * Sanitize input for XSS prevention
 */
function sanitize(string $input): string {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

/**
 * Get current UTC datetime string
 */
function utcnow(): string {
    return gmdate('Y-m-d H:i:s');
}

/**
 * Normalize phone number for duplicate comparison
 * Equivalent to RegistrationService.normalize_phone()
 */
function normalizePhone(string $phone): string {
    // Remove all non-digit characters except leading +
    $cleaned = preg_replace('/[^\d+]/', '', $phone);
    // Remove leading + if present
    if (strpos($cleaned, '+') === 0) {
        $cleaned = substr($cleaned, 1);
    }
    // Remove leading 00 or 011 (international dialing prefixes)
    if (strpos($cleaned, '00') === 0) {
        $cleaned = substr($cleaned, 2);
    } elseif (strpos($cleaned, '011') === 0) {
        $cleaned = substr($cleaned, 3);
    }
    return $cleaned;
}

/**
 * Normalize email for duplicate comparison
 */
function normalizeEmail(string $email): string {
    return strtolower(trim($email));
}

/**
 * Generate duplicate key for registration prevention
 */
function generateDuplicateKey(string $email, string $phone, string $demoClassId): string {
    $normalizedEmail = normalizeEmail($email);
    $normalizedPhone = normalizePhone($phone);
    $keyString = "$normalizedEmail:$normalizedPhone:$demoClassId";
    return hash('sha256', $keyString);
}

/**
 * Validate email format
 */
function isValidEmail(string $email): bool {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Validate phone number (at least 7 digits after cleaning)
 */
function isValidPhone(string $phone): bool {
    $cleaned = preg_replace('/[^\d+]/', '', $phone);
    return strlen($cleaned) >= 7;
}

/**
 * Format scheduled date for display
 */
function formatScheduledDate(string $scheduledAt, string $timezone): string {
    try {
        $dt = new DateTime($scheduledAt, new DateTimeZone($timezone));
        return $dt->format('M d, Y \a\t h:i A T');
    } catch (Exception $e) {
        return $scheduledAt;
    }
}

/**
 * Send JSON response
 */
function jsonResponse(array $data, int $statusCode = 200): void {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Send HTML error response
 */
function htmlError(string $message, int $statusCode = 500): void {
    http_response_code($statusCode);
    echo "<h1>Error</h1><p>" . sanitize($message) . "</p>";
    exit;
}

/**
 * Redirect to URL
 */
function redirectTo(string $url): void {
    header("Location: $url");
    exit;
}

/**
 * Get the current request URI path
 */
function getRequestPath(): string {
    $uri = $_SERVER['REQUEST_URI'];
    // Remove query string
    $path = parse_url($uri, PHP_URL_PATH);
    return $path;
}

/**
 * Check if request method matches
 */
function isMethod(string $method): bool {
    return $_SERVER['REQUEST_METHOD'] === strtoupper($method);
}

/**
 * Get request body as associative array (for JSON requests)
 */
function getJsonBody(): array {
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    return is_array($data) ? $data : [];
}
