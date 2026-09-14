(function () {
    'use strict';

    // Idempotency guard: if this script ever gets included twice (two <script>
    // tags), the second copy must NOT bind another click listener — otherwise
    // one click toggles the theme twice and it looks broken. The first copy
    // remains fully functional.
    if (window.__codeAndAiThemeLoaded) return;
    window.__codeAndAiThemeLoaded = true;

    var storageKey = 'codeandai-theme';
    var documentElement = document.documentElement;

    function savedTheme() {
        try {
            return localStorage.getItem(storageKey);
        } catch (error) {
            return null;
        }
    }

    function prefersDark() {
        return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
    }

    function setTheme(theme, persist) {
        documentElement.setAttribute('data-theme', theme);
        if (persist) {
            try {
                localStorage.setItem(storageKey, theme);
            } catch (error) {
                // Continue with the current page theme when storage is unavailable.
            }
        }
        var meta = document.querySelector('meta[name="theme-color"]');
        if (meta) meta.setAttribute('content', theme === 'dark' ? '#0f1115' : '#fafafa');
        document.querySelectorAll('.theme-toggle').forEach(function (button) {
            var dark = theme === 'dark';
            button.setAttribute('aria-label', dark ? 'Switch to light mode' : 'Switch to dark mode');
            button.setAttribute('aria-pressed', dark ? 'true' : 'false');
        });
    }

    var saved = savedTheme();
    setTheme(saved === 'dark' || saved === 'light' ? saved : (prefersDark() ? 'dark' : 'light'), false);

    function bind() {
        document.querySelectorAll('.theme-toggle').forEach(function (button) {
            button.addEventListener('click', function () {
                setTheme(documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark', true);
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', bind);
    } else {
        bind();
    }
})();
