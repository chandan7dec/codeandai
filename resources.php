<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/training_resource_service.php';

runStartup();
sendSecurityHeaders();
$service = new TrainingResourceService();
$groups = $service->getPublishedGroupedByClass();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <meta name="description" content="Recordings and materials from past training sessions — watch session recordings and download slides from past Code & AI trainings.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Training Resources | Code & AI">
    <meta property="og:description" content="Watch recordings and download slides from past Code & AI training sessions.">
    <meta property="og:url" content="https://learnai.dpdns.org/resources.php">
    <script src="/assets/js/theme.js"></script>
    <title>Training Resources | Code &amp; AI</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <main class="container container-resources">
        <header class="header">
            <div class="ig-logo" aria-hidden="true">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                    <defs><linearGradient id="resGrad" x1="0" y1="48" x2="48" y2="0"><stop offset="0%" stop-color="#6366f1"/><stop offset="50%" stop-color="#8b5cf6"/><stop offset="100%" stop-color="#ec4899"/></linearGradient></defs>
                    <rect x="2" y="2" width="44" height="44" rx="12" stroke="url(#resGrad)" stroke-width="3" fill="none"/>
                    <path d="M16 20l8-5 8 5v8l-8 5-8-5z" stroke="url(#resGrad)" stroke-width="2.5" fill="none" stroke-linejoin="round"/>
                    <path d="M24 23v6M21 26h6" stroke="url(#resGrad)" stroke-width="2.5" stroke-linecap="round"/>
                </svg>
            </div>
            <h1>Training Resources</h1>
            <p class="subtitle">Recordings and materials from past training sessions</p>
        </header>

        <?php if ($groups === []): ?>
            <p class="empty-state">No training resources published yet. Check back after the next session.</p>
        <?php endif; ?>

        <?php foreach ($groups as $group): ?>
            <?php
                $class = $group['class'];
                $isPaid = $class['is_paid'] === 1;
                $dateLabel = '';
                try {
                    $dt = new DateTimeImmutable($class['scheduled_at'], new DateTimeZone($class['timezone']));
                    $dateLabel = $dt->format('D, d M Y');
                } catch (Exception $e) {
                    $dateLabel = sanitize((string)$class['scheduled_at']);
                }
            ?>
            <article class="resource-card" data-class-id="<?= sanitize((string)$class['id']) ?>">
                <div class="resource-card-heading">
                    <div>
                        <p class="training-kicker">Past training &middot; <?= sanitize($dateLabel) ?></p>
                        <h3><?= sanitize((string)$class['title']) ?></h3>
                        <p class="resource-topic"><strong>Topic:</strong> <?= sanitize((string)$class['topic']) ?></p>
                        <p class="resource-meta"><strong>Trainer:</strong> <?= sanitize((string)$class['trainer_name']) ?></p>
                    </div>
                    <span class="badge <?= $isPaid ? 'badge-paid' : 'badge-success' ?>"><?= $isPaid ? 'Paid' : 'Free' ?></span>
                </div>

                <?php $hasGated = false; ?>
                <div class="resource-items">
                    <?php foreach ($group['resources'] as $res): ?>
                        <?php if ($res['type'] === 'recording' && !empty($res['youtube_video_id'])): ?>
                            <div class="resource-item resource-recording">
                                <div class="video-facade"
                                     data-embed="<?= sanitize((string)$res['embed_url']) ?>"
                                     role="button" tabindex="0"
                                     aria-label="Play recording: <?= sanitize((string)$res['title']) ?>">
                                    <img src="<?= sanitize((string)$res['thumbnail_url']) ?>" alt="" loading="lazy">
                                    <span class="video-facade-play" aria-hidden="true">&#9654;</span>
                                </div>
                                <div class="resource-item-body">
                                    <p class="resource-item-title"><?= sanitize((string)$res['title']) ?></p>
                                    <a class="resource-external-link" href="<?= sanitize((string)$res['watch_url']) ?>" target="_blank" rel="noopener">Watch on YouTube &#8599;</a>
                                </div>
                            </div>
                        <?php elseif (!empty($res['resource_url'])): ?>
                            <?php $isCode = $res['type'] === 'code'; ?>
                            <div class="resource-item resource-doc<?= $isCode ? ' resource-code' : '' ?>">
                                <span class="resource-doc-icon" aria-hidden="true">
                                    <?php if ($isCode): ?>
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M16 3l5 5-5 5" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/><path d="M21 8H9a6 6 0 00-6 6v7" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                    <?php else: ?>
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                                    <?php endif; ?>
                                </span>
                                <div class="resource-item-body">
                                    <p class="resource-item-title"><?= sanitize((string)$res['title']) ?><?= $res['file_name'] ? ' <span class="resource-file-meta">(' . sanitize((string)$res['file_name']) . ($res['file_size_label'] ? ' &middot; ' . sanitize((string)$res['file_size_label']) : '') . ')</span>' : '' ?></p>
                                    <p class="resource-downloads"><?= !empty($res['github_repo']) ? 'GitHub &middot; ' . sanitize((string)$res['github_repo']) : sanitize((string)$res['resource_host']) ?></p>
                                </div>
                                <?php if ($isCode): ?>
                                <a class="btn btn-secondary resource-download-btn" href="<?= sanitize((string)$res['resource_url']) ?>" target="_blank" rel="noopener"><?= !empty($res['github_repo']) ? 'View on GitHub &#8599;' : 'Open code &#8599;' ?></a>
                                <?php else: ?>
                                <a class="btn btn-secondary resource-download-btn" href="/download.php?id=<?= sanitize((string)$res['id']) ?>" data-gated="<?= $gated ? '1' : '0' ?>" data-class-id="<?= sanitize((string)$class['id']) ?>" data-resource-title="<?= sanitize((string)$res['title']) ?>">Download</a>
                                <?php endif; ?>
                            </div>
                        <?php elseif (in_array($res['type'], ['slides', 'pdf'], true) && !empty($res['drive_file_id'])): ?>
                            <?php $gated = $isPaid && !$service->canDownload((string)$class['id'], null); $hasGated = $hasGated || $gated; ?>
                            <div class="resource-item resource-doc">
                                <span class="resource-doc-icon" aria-hidden="true">
                                    <?php if ($res['type'] === 'slides'): ?>
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><rect x="3" y="4" width="18" height="13" rx="2" stroke="currentColor" stroke-width="2"/><path d="M12 17v4M8 21h8" stroke="currentColor" stroke-width="2" stroke-linecap="round"/></svg>
                                    <?php else: ?>
                                        <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M14 2H6a2 2 0 00-2 2v16a2 2 0 002 2h12a2 2 0 002-2V8z" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/><path d="M14 2v6h6" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/></svg>
                                    <?php endif; ?>
                                </span>
                                <div class="resource-item-body">
                                    <p class="resource-item-title"><?= sanitize((string)$res['title']) ?><?= $res['file_name'] ? ' <span class="resource-file-meta">(' . sanitize((string)$res['file_name']) . ($res['file_size_label'] ? ' &middot; ' . sanitize((string)$res['file_size_label']) : '') . ')</span>' : '' ?></p>
                                    <p class="resource-downloads"><?= (int)$res['download_count'] ?> download<?= (int)$res['download_count'] === 1 ? '' : 's' ?></p>
                                </div>
                                <a class="btn btn-secondary resource-download-btn<?= $gated ? ' gated' : '' ?>"
                                   href="/download.php?id=<?= sanitize((string)$res['id']) ?>"
                                   data-gated="<?= $gated ? '1' : '0' ?>"
                                   data-class-id="<?= sanitize((string)$class['id']) ?>"
                                   data-resource-title="<?= sanitize((string)$res['title']) ?>">Download</a>
                            </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                </div>

                <?php if ($hasGated): ?>
                    <div class="resource-gate-note">
                        <p>&#128274; Materials from this paid training are available to attendees only. Enter the <strong>email you registered with</strong>:</p>
                        <form class="resource-gate-form" data-class-id="<?= sanitize((string)$class['id']) ?>">
                            <input type="email" name="attendee_email" required placeholder="you@example.com" aria-label="Registration email">
                            <button type="submit" class="btn btn-primary">Unlock downloads</button>
                        </form>
                        <p class="resource-gate-hint" hidden>Enter the email you registered with to unlock downloads.</p>
                    </div>
                <?php endif; ?>
            </article>
        <?php endforeach; ?>

        <?php siteFooter(); ?>
    </main>
    <script src="/assets/js/resources.js"></script>
</body>
</html>
