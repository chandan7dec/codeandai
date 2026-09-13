<?php

declare(strict_types=1);

/**
 * CSV export for the organizer dashboard.
 *
 * GET ?type=registrations  [demo_class_id, whatsapp_consent, search]
 * GET ?type=payments       [payment_status, payment_class, payment_search, payment_date_from, payment_date_to]
 *
 * Honors the same filters as the dashboard tables (taken from the query
 * string), so "export what I'm currently looking at" works.
 */

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/registration_service.php';
require_once __DIR__ . '/../includes/admin_dashboard_service.php';

runStartup();
sendSecurityHeaders();

if (!organizerAuthenticated()) {
    jsonResponse(['error' => 'Invalid API key. Log in at /login.php or pass api_key.'], 403);
}

$type = (string)($_GET['type'] ?? '');

$filters = [];
$filename = '';
$rows = [];
$header = [];

if ($type === 'registrations') {
    $demoClassId = trim((string)($_GET['demo_class_id'] ?? ''));
    $consent = (string)($_GET['whatsapp_consent'] ?? '');
    $consentBool = null;
    if ($consent === 'true') {
        $consentBool = true;
    } elseif ($consent === 'false') {
        $consentBool = false;
    }
    $search = trim((string)($_GET['search'] ?? ''));

    $service = new RegistrationService();
    $rows = $service->getRegistrations(
        demoClassId: $demoClassId !== '' ? $demoClassId : null,
        whatsappConsent: $consentBool,
        search: $search !== '' ? $search : null
    );

    $header = ['Name', 'Email', 'Phone', 'Class', 'Registered (UTC)', 'Status', 'WhatsApp Consent'];
    $filename = 'registrations-' . date('Y-m-d') . '.csv';
} elseif ($type === 'payments') {
    $filters = [
        'status'    => trim((string)($_GET['payment_status'] ?? '')),
        'class_id'  => trim((string)($_GET['payment_class'] ?? '')),
        'search'    => trim((string)($_GET['payment_search'] ?? '')),
        'date_from' => trim((string)($_GET['payment_date_from'] ?? '')),
        'date_to'   => trim((string)($_GET['payment_date_to'] ?? '')),
    ];

    $service = new AdminDashboardService();
    $rows = $service->getPaymentLogs($filters);

    $header = ['Transaction ID', 'Order ID', 'User', 'Email', 'Phone', 'Class', 'Amount', 'Currency', 'Status', 'Payer VPA', 'Date (UTC)'];
    $filename = 'payments-' . date('Y-m-d') . '.csv';
} else {
    jsonResponse(['error' => 'type must be registrations or payments'], 400);
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');

$out = fopen('php://output', 'w');
// UTF-8 BOM so Excel opens non-ASCII (₹, Hindi names) correctly.
fwrite($out, "\xEF\xBB\xBF");
fputcsv($out, $header);

foreach ($rows as $row) {
    if ($type === 'registrations') {
        fputcsv($out, [
            (string)($row['registrant_name'] ?? ''),
            (string)($row['registrant_email'] ?? ''),
            (string)($row['phone_number'] ?? ''),
            (string)($row['class_title'] ?? ''),
            (string)($row['created_at'] ?? ''),
            (string)($row['registration_status'] ?? ''),
            !empty($row['consented_to_whatsapp']) ? 'Yes' : 'No',
        ]);
    } else {
        fputcsv($out, [
            (string)($row['transaction_id'] ?? ''),
            (string)($row['merchant_order_id'] ?? ''),
            (string)($row['user_name'] ?? ''),
            (string)($row['user_email'] ?? ''),
            (string)($row['user_phone'] ?? ''),
            (string)($row['class_title'] ?? ''),
            number_format((float)($row['amount'] ?? 0), 2, '.', ''),
            (string)($row['currency'] ?? 'INR'),
            (string)($row['status'] ?? ''),
            (string)($row['payer_vpa'] ?? ''),
            (string)($row['created_at'] ?? ''),
        ]);
    }
}

fclose($out);
exit;
