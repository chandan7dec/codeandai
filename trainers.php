<?php

require_once __DIR__ . '/includes/functions.php';

$trainers = [
    [
        'name' => 'Chandan Kumar',
        'expertise' => ['GenAI & Machine Learning', 'Python', 'DotNet'],
        'bio' => '15+ years building production ML systems for fintech and healthcare.',
    ],
    [
        'name' => 'Rajesh Kumar',
        'expertise' => ['Full-Stack Web', 'Java', 'React'],
        'bio' => '12+ years Architects scalable web platforms and mentors junior developers.',
    ],
    [
        'name' => 'Pankaj Kumar',
        'expertise' => ['Java', 'AWS', 'Cloud & DevOps'],
        'bio' => '15+ years of industry experience. Helps teams ship faster with CI/CD and infrastructure-as-code as Java expert.',
    ],
    [
        'name' => 'Sneha Iyer',
        'expertise' => ['Data Engineering', 'SQL', 'Big Data'],
        'bio' => 'Designs resilient data pipelines and analytics platforms.',
    ],
    [
        'name' => 'Vikram Singh',
        'expertise' => ['Cybersecurity', 'Penetration Testing', 'Networking'],
        'bio' => 'Secures cloud-native apps and trains teams on secure-by-design practices.',
    ],
    [
        'name' => 'Ananya Kapoor',
        'expertise' => ['Mobile Development', 'Flutter', 'iOS'],
        'bio' => 'Crafts cross-platform mobile experiences with a focus on performance.',
    ],
];

function renderTrainerCard(array $trainer): void {
    $initials = '';
    foreach (preg_split('/\s+/', trim($trainer['name'])) as $part) {
        if ($part !== '') {
            $initials .= strtoupper(substr($part, 0, 1));
        }
    }
    $initials = substr($initials, 0, 2);
    ?>
    <article class="training-card trainer-card">
        <div class="trainer-avatar" aria-hidden="true">
            <svg viewBox="0 0 64 64" fill="none" stroke="#8e8e8e" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="32" cy="22" r="11"/>
                <path d="M12 56c2.5-10 10.5-16 20-16s17.5 6 20 16"/>
            </svg>
            <span class="trainer-initials"><?= sanitize($initials) ?></span>
        </div>
        <div class="training-card-heading">
            <div>
                <p class="trainer-kicker">Trainer</p>
                <h3><?= sanitize($trainer['name']) ?></h3>
            </div>
        </div>
        <div class="trainer-expertise">
            <?php foreach ($trainer['expertise'] as $tag): ?>
                <span><?= sanitize($tag) ?></span>
            <?php endforeach; ?>
        </div>
        <p><?= sanitize($trainer['bio']) ?></p>
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
    <title>Our Trainers</title>
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
                    <defs><linearGradient id="trainerGrad" x1="0" y1="48" x2="48" y2="0"><stop offset="0%" stop-color="#f09433"/><stop offset="50%" stop-color="#dc2743"/><stop offset="100%" stop-color="#bc1888"/></linearGradient></defs>
                    <circle cx="24" cy="17" r="8" stroke="url(#trainerGrad)" stroke-width="3" fill="none"/>
                    <path d="M8 40c2.5-8 9-13 16-13s13.5 5 16 13" stroke="url(#trainerGrad)" stroke-width="3" stroke-linecap="round" fill="none"/>
                </svg>
            </div>
            <h1>Our Trainers</h1>
            <p class="subtitle">Meet the experts behind every training session</p>
        </header>
        <section class="calendar-section" aria-labelledby="trainers-heading">
            <div class="section-heading"><h2 id="trainers-heading">Featured Trainers</h2></div>
            <div class="training-grid">
                <?php foreach ($trainers as $trainer) renderTrainerCard($trainer); ?>
            </div>
        </section>
        <footer class="footer">
            <a href="/index.php">Home</a> &middot;
            <a href="/training-calendar.php">Training Calendar</a> &middot;
            <a href="/register.php">Register</a>
        </footer>
    </main>
</body>
</html>