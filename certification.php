<?php

declare(strict_types=1);

/**
 * Certification Support
 *
 * Describes the certification guidance the Code & AI team provides and
 * funnels interested visitors into the dedicated WhatsApp group. The group
 * invite link comes from the .env / config layer (WHATSAPP_CERT_GROUP_URL),
 * falling back to the general community group when unset.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';

runStartup();
sendSecurityHeaders();

$certWhatsappUrl = WHATSAPP_CERT_GROUP_URL;
$certWhatsappLabel = WHATSAPP_CERT_GROUP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <meta name="description" content="The Code &amp; AI team supports you in clearing all kinds of IT certifications — cloud, AI, security, and more. Join the certification WhatsApp group for guidance, study plans, and exam tips.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Certification Support | Code &amp; AI">
    <meta property="og:description" content="Clear all kinds of IT certifications with the Code &amp; AI team. Join the certification WhatsApp group for study plans and exam guidance.">
    <meta property="og:url" content="https://learnai.dpdns.org/certification.php">
    <script src="/assets/js/theme.js"></script>
    <title>Certification Support | Code &amp; AI</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <main class="container landing-container">
        <header class="header landing-header">
            <div class="ig-logo" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                    <defs><linearGradient id="certGrad" x1="0" y1="48" x2="48" y2="0"><stop offset="0%" stop-color="#f59e0b"/><stop offset="50%" stop-color="#f97316"/><stop offset="100%" stop-color="#ef4444"/></linearGradient></defs>
                    <rect x="2" y="2" width="44" height="44" rx="12" stroke="url(#certGrad)" stroke-width="3" fill="none"/>
                    <circle cx="24" cy="20" r="9" stroke="url(#certGrad)" stroke-width="2.5" fill="none"/>
                    <path d="M20 19.5l2.5 2.5 5-5" stroke="url(#certGrad)" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"/>
                    <path d="M17 27.5l-3 12 10-5 10 5-3-12" stroke="url(#certGrad)" stroke-width="2.5" fill="none" stroke-linejoin="round"/>
                </svg>
            </div>
            <h1 class="landing-wordmark">Certification <span class="text-gradient">Support</span></h1>
            <p class="subtitle">Guided by the Code &amp; AI team — from study plan to exam day.</p>
        </header>

        <section class="card landing-hero">
            <span class="pill">Free guidance &middot; Community driven</span>
            <h2 class="landing-title">Clear any <span class="text-gradient">certification</span> with expert support.</h2>
            <p class="landing-text">The Code &amp; AI team supports you in clearing all kinds of certifications — cloud, AI &amp; data, networking, security, and project management. Our mentors and community members have walked the same path and know exactly what it takes.</p>
            <a href="<?= sanitize($certWhatsappUrl) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp">Join the Certification WhatsApp Group</a>
            <p class="help-text landing-help">Ask questions, get study plans, and hear real exam experiences — free of cost.</p>
        </section>

        <section class="card">
            <h2 class="card-heading">What you get in the group</h2>
            <div class="feature-list">
                <div class="feature-row"><span class="feature-icon" aria-hidden="true">🧭</span><div><strong>Personalized study plans</strong><p>Tell us your target certification and timeline — we help you plan backwards from exam day.</p></div></div>
                <div class="feature-row"><span class="feature-icon" aria-hidden="true">📝</span><div><strong>Real exam insights</strong><p>Question patterns, difficulty levels, and preparation tips from members who recently cleared the same exams.</p></div></div>
                <div class="feature-row"><span class="feature-icon" aria-hidden="true">🤝</span><div><strong>Peer &amp; mentor support</strong><p>Stuck on a topic? Post it in the group — mentors and certified members help you through it.</p></div></div>
                <div class="feature-row"><span class="feature-icon" aria-hidden="true">🎓</span><div><strong>All certification paths</strong><p>AWS, Azure, Google Cloud, AI/ML, cybersecurity, networking, PMP, and more — one community for all of them.</p></div></div>
            </div>
        </section>

        <section class="card">
            <h2 class="card-heading">How it works</h2>
            <div class="feature-list">
                <div class="feature-row"><span class="feature-icon" aria-hidden="true">1️⃣</span><div><strong>Join the group</strong><p>One click below — introduce yourself and mention the certification you're targeting.</p></div></div>
                <div class="feature-row"><span class="feature-icon" aria-hidden="true">2️⃣</span><div><strong>Get your roadmap</strong><p>We share the syllabus breakdown, best resources, and a realistic preparation timeline.</p></div></div>
                <div class="feature-row"><span class="feature-icon" aria-hidden="true">3️⃣</span><div><strong>Prepare &amp; clear it</strong><p>Practice questions, doubt-clearing sessions, and motivation until you pass. Then celebrate with us! 🎉</p></div></div>
            </div>
            <a href="<?= sanitize($certWhatsappUrl) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp" style="width: 100%; margin-top: 8px;">Join the Certification WhatsApp Group</a>
        </section>

        <div class="whatsapp-section">
            <h3>Preparing for a certification?</h3>
            <p>Join the <?= sanitize($certWhatsappLabel) ?> group — the community that clears exams together</p>
            <a href="<?= sanitize($certWhatsappUrl) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp">Join Group</a>
        </div>
        <?php siteFooter(); ?>
    </main>
</body>
</html>
