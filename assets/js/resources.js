/**
 * Training Resources page behaviour.
 *  - YouTube facade: thumbnail placeholder swaps to the embedded player on click
 *    (keeps the page fast — no video players are loaded until requested)
 *  - Gate forms: paid-class materials ask for the registration email; once
 *    accepted (server-verified on click), gated download links carry the email
 */
(function () {
    'use strict';

    // ── YouTube facade ──
    document.querySelectorAll('.video-facade').forEach(function (facade) {
        function activate() {
            if (facade.dataset.activated) return;
            facade.dataset.activated = '1';
            var embed = facade.getAttribute('data-embed');
            if (!embed) return;
            var iframe = document.createElement('iframe');
            iframe.src = embed + (embed.indexOf('?') === -1 ? '?' : '&') + 'autoplay=1&rel=0';
            iframe.title = 'Training recording';
            iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture';
            iframe.allowFullscreen = true;
            iframe.style.width = '100%';
            iframe.style.height = '100%';
            iframe.style.border = '0';
            facade.classList.add('is-playing');
            facade.innerHTML = '';
            facade.appendChild(iframe);
        }
        facade.addEventListener('click', activate);
        facade.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                activate();
            }
        });
    });

    // ── Paid-class gate forms ──
    document.querySelectorAll('.resource-gate-form').forEach(function (form) {
        form.addEventListener('submit', function (e) {
            e.preventDefault();
            var card = form.closest('.resource-card');
            if (!card) return;
            var classId = form.getAttribute('data-class-id');
            var emailInput = form.querySelector('input[name="attendee_email"]');
            var email = emailInput ? emailInput.value.trim() : '';
            if (!email) return;

            // Point every gated download button in this card at the email check.
            // The server re-verifies on click, so a wrong email just shows the
            // unlock form again.
            card.querySelectorAll('.resource-download-btn.gated').forEach(function (btn) {
                var url = new URL(btn.getAttribute('href'), window.location.origin);
                url.searchParams.set('email', email);
                btn.setAttribute('href', url.pathname + url.search);
                btn.classList.remove('gated');
            });

            var note = form.closest('.resource-gate-note');
            if (note) {
                var hint = note.querySelector('.resource-gate-hint');
                if (hint) hint.hidden = true;
                note.classList.add('is-unlocked-pending');
            }
            // Give immediate feedback: try the first gated link.
            var first = card.querySelector('.resource-download-btn');
            if (first) {
                // Defer so the click happens after the DOM updates.
                setTimeout(function () { first.click(); }, 50);
            }
        });
    });
})();
