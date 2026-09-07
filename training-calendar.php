<?php

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/class_management_service.php';

runStartup();
$calendar = (new ClassManagementService())->getCalendarClasses();

function renderTrainingCard(array $training): void {
    $isOpen = $training['registration_open'] && ($training['capacity'] === null || $training['remaining_capacity'] > 0);
    ?>
    <article class="training-card">
        <div class="training-date-chip">
            <span><?= sanitize(date('M', strtotime($training['scheduled_at']))) ?></span>
            <strong><?= sanitize(date('d', strtotime($training['scheduled_at']))) ?></strong>
        </div>
        <div class="training-card-heading">
            <div>
                <p class="training-kicker">Training session</p>
                <h3><?= sanitize($training['title']) ?></h3>
            </div>
            <span class="badge <?= $isOpen ? 'badge-success' : 'badge-warning' ?>"><?= $isOpen ? 'Open' : 'Closed' ?></span>
        </div>
        <p class="training-topic"><strong>Topic:</strong> <?= sanitize($training['topic']) ?></p>
        <p><strong>Trainer:</strong> <?= sanitize($training['trainer_name']) ?></p>
        <p><strong>When:</strong> <?= sanitize(formatScheduledDate($training['scheduled_at'], $training['timezone'])) ?></p>
        <p><strong>Availability:</strong> <?= $training['capacity'] === null ? 'Unlimited' : (int)$training['remaining_capacity'] . ' seats remaining' ?></p>
        <?php if ($isOpen): ?><a class="btn btn-primary" href="/register.php">Register</a><?php endif; ?>
    </article>
    <?php
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <script src="/assets/js/theme.js"></script>
    <title>Training Calendar</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <main class="container container-calendar">
        <header class="header">
            <div class="ig-logo" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                    <defs><linearGradient id="calendarGrad" x1="0" y1="48" x2="48" y2="0"><stop offset="0%" stop-color="#f09433"/><stop offset="50%" stop-color="#dc2743"/><stop offset="100%" stop-color="#bc1888"/></linearGradient></defs>
                    <rect x="2" y="2" width="44" height="44" rx="12" stroke="url(#calendarGrad)" stroke-width="3" fill="none"/>
                    <path d="M14 18h20M17 13v6M31 13v6M14 23h20M14 29h6M23 29h5M14 35h6" stroke="url(#calendarGrad)" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <h1>Training Calendar</h1>
            <p class="subtitle">Explore active and upcoming training sessions</p>
        </header>
        <section class="calendar-section" aria-labelledby="active-training-heading">
            <div class="section-heading"><h2 id="active-training-heading">Active Training</h2></div>
            <?php if ($calendar['active']): ?><div class="training-grid"><?php foreach ($calendar['active'] as $training) renderTrainingCard($training); ?></div>
            <?php else: ?><p class="empty-state">No active training sessions.</p><?php endif; ?>
        </section>
        <section class="calendar-section" aria-labelledby="upcoming-training-heading">
            <div class="section-heading"><h2 id="upcoming-training-heading">Upcoming Training</h2></div>
            <?php if ($calendar['upcoming']): ?><div class="training-grid"><?php foreach ($calendar['upcoming'] as $training) renderTrainingCard($training); ?></div>
            <?php else: ?><p class="empty-state">No upcoming training sessions.</p><?php endif; ?>
        </section>
        <footer class="footer"><a href="/register.php">Go to registration</a></footer>
    </main>
</body>
</html>