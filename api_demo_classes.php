<?php

declare(strict_types=1);
/**
 * API: List Active Demo Classes
 * 
 * Returns JSON list of active demo classes.
 * Equivalent to Python's /api/demo-classes endpoint.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/registration_service.php';
require_once __DIR__ . '/includes/class_management_service.php';

// Ensure DB is initialized
runStartup();
sendSecurityHeaders();

if (!isMethod('GET')) {
    jsonResponse(['error' => 'Method not allowed'], 405);
}

try {
    $demoClasses = (new ClassManagementService())->getAllClasses();
    $demoClasses = array_values(array_filter($demoClasses, function (array $demoClass): bool {
        return $demoClass['status'] === 'active' && $demoClass['registration_open'];
    }));
    
    $result = array_map(function($dc) {
        return [
            'id' => $dc['id'],
            'title' => $dc['title'],
            'topic' => $dc['topic'],
            'trainer_name' => $dc['trainer_name'],
            'scheduled_at' => $dc['scheduled_at'],
            'timezone' => $dc['timezone'],
            'teams_link' => $dc['teams_link'],
            'status' => $dc['status'],
            'registration_open' => $dc['registration_open'],
            'capacity' => $dc['capacity'],
            'registration_count' => $dc['registration_count'],
            'remaining_capacity' => $dc['remaining_capacity'],
        ];
    }, $demoClasses);
    
    jsonResponse($result);
    
} catch (Exception $e) {
    jsonResponse(['error' => 'Failed to fetch demo classes'], 500);
}
