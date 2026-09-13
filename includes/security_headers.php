<?php

declare(strict_types=1);

/**
 * Security headers — PHP-level defense in depth.
 *
 * Sent from every page in addition to the Apache `.htaccess` header block,
 * so the protections hold even when the app is served by a server that
 * ignores .htaccess (nginx, PHP built-in dev server, ...).
 *
 * Content-Security-Policy notes:
 *  - 'unsafe-inline' for scripts is required by the organizer dashboard's
 *    inline onclick handlers and small inline bootstrap scripts; everything
 *    else on the site uses external script files. Frame-src is limited to
 *    YouTube's privacy-enhanced embed host (resources page player).
 *  - 'unsafe-inline' for styles covers the small inline style attributes.
 */

if (!function_exists('sendSecurityHeaders')) {
    /**
     * Send the standard security headers once per response.
     */
    function sendSecurityHeaders(): void
    {
        if (headers_sent()) {
            return;
        }

        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
        header("Content-Security-Policy: "
            . "default-src 'self'; "
            . "script-src 'self' 'unsafe-inline'; "
            . "style-src 'self' 'unsafe-inline'; "
            . "img-src 'self' data: https://i.ytimg.com https://img.youtube.com; "
            . "frame-src https://www.youtube-nocookie.com https://www.youtube.com; "
            . "connect-src 'self'; "
            . "font-src 'self' data:; "
            . "object-src 'none'; "
            . "base-uri 'self'; "
            . "form-action 'self'; "
            . "frame-ancestors 'self'");
        header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
    }
}
