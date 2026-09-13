<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/class_management_service.php';

runStartup();
sendSecurityHeaders();
$activeClass = (new ClassManagementService())->getOpenClass();
$whatsappUrl = WHATSAPP_GROUP_INVITE_URL;
$whatsappName = WHATSAPP_GROUP_NAME;
?>
<!DOCTYPE html>
<html lang="en">
<head>
	<meta charset="UTF-8">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<meta name="theme-color" content="#fafafa">
	<meta name="description" content="Live AI & software engineering trainings by industry experts. Join free and paid sessions on Python, AI tools, and full-stack development.">
	<meta property="og:type" content="website">
	<meta property="og:title" content="Code & AI | Live Training">
	<meta property="og:description" content="Live AI & software engineering trainings by industry experts. Free and paid sessions on Python, AI tools, and full-stack development.">
	<meta property="og:url" content="https://learnai.dpdns.org/">
	<script src="/assets/js/theme.js"></script>
	<title>Code &amp; AI | Live Training</title>
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
					<defs><linearGradient id="landingGrad" x1="0" y1="48" x2="48" y2="0"><stop offset="0%" stop-color="#f09433"/><stop offset="50%" stop-color="#dc2743"/><stop offset="100%" stop-color="#bc1888"/></linearGradient></defs>
					<rect x="2" y="2" width="44" height="44" rx="12" stroke="url(#landingGrad)" stroke-width="3" fill="none"/>
					<circle cx="24" cy="24" r="10" stroke="url(#landingGrad)" stroke-width="3" fill="none"/>
					<circle cx="37" cy="11" r="2.5" fill="url(#landingGrad)"/>
				</svg>
			</div>
			<h1 class="landing-wordmark">Code <span class="text-gradient">&amp;</span> AI</h1>
			<p class="subtitle">Live, hands-on training that turns beginners into AI builders.</p>
		</header>

		<section class="card landing-hero">
			<span class="pill">Live online training · Small groups</span>
			<h2 class="landing-title">Master Code.<br>Understand <span class="text-gradient">AI</span>.</h2>
			<p class="landing-text">Learn practical coding and apply it to real artificial-intelligence projects through live, focused training built around practice.</p>
			<a href="/register.php" class="btn btn-primary">Register for the Next Training</a>
			<a href="<?= sanitize($whatsappUrl) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp">Join the WhatsApp Group</a>
		</section>

		<section class="card">
			<h2 class="card-heading">What you'll learn</h2>
			<div class="feature-list">
				<div class="feature-row"><span class="feature-icon" aria-hidden="true">🐍</span><div><strong>Programming fundamentals</strong><p>Build a clear foundation with practical, working code.</p></div></div>
				<div class="feature-row"><span class="feature-icon" aria-hidden="true">🤖</span><div><strong>AI in practice</strong><p>Work with real tools and workflows to build useful solutions.</p></div></div>
				<div class="feature-row"><span class="feature-icon" aria-hidden="true">🚀</span><div><strong>Real projects</strong><p>Leave each session with something you can show and use.</p></div></div>
			</div>
		</section>

		<?php if ($activeClass): ?>
		<section class="card landing-class-card">
			<span class="pill">Next open training</span>
			<h2 class="card-heading"><?= sanitize($activeClass['title']) ?></h2>
			<div class="class-details">
				<div class="detail-grid">
					<div class="detail-item"><label>Topic</label><span><?= sanitize($activeClass['topic']) ?></span></div>
					<div class="detail-item"><label>Trainer</label><span><?= sanitize($activeClass['trainer_name']) ?></span></div>
					<div class="detail-item"><label>Date</label><span><?= sanitize(formatScheduledDate($activeClass['scheduled_at'], $activeClass['timezone'])) ?></span></div>
				</div>
			</div>
			<a href="/register.php" class="btn btn-primary">Reserve Your Spot</a>
			<p class="help-text landing-help">Registration takes less than a minute.</p>
		</section>
		<?php endif; ?>

		<div class="whatsapp-section"><h3>Join Our <?= sanitize($whatsappName) ?> WhatsApp Group</h3><p>Get training updates, announcements, and reminders</p><a href="<?= sanitize($whatsappUrl) ?>" target="_blank" rel="noopener" class="btn btn-whatsapp">Join Group</a></div>
		<footer class="footer"><p>Code &amp; AI · <a href="/trainers.php">Our trainers</a> · <a href="/training-calendar.php">View training calendar</a> · <a href="/resources.php">Training resources</a></p></footer>
	</main>
</body>
</html>
