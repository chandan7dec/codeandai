/**
 * Client-side Form Validation
 * 
 * Inline validation for the registration form.
 * Equivalent to Python project's static/js/validation.js.
 */

document.addEventListener('DOMContentLoaded', function () {
    const form = document.querySelector('.registration-form');
    if (!form) return;

    function clearError(input) {
        const group = input.closest('.form-group');
        const existing = group.querySelector('.field-error');
        if (existing) existing.remove();
        input.style.borderColor = '';
        group.classList.remove('has-error');
    }

    function showError(input, message) {
        const group = input.closest('.form-group');
        // Remove any existing error first
        const existing = group.querySelector('.field-error');
        if (existing) existing.remove();

        const span = document.createElement('span');
        span.className = 'field-error';
        span.textContent = message;
        group.appendChild(span);

        input.style.borderColor = 'var(--ig-red)';
        group.classList.add('has-error');
    }

    // Clear error as user types
    form.querySelectorAll('input').forEach(function (input) {
        input.addEventListener('input', function () {
            clearError(input);
        });
    });

    form.addEventListener('submit', function (e) {
        // Clear all previous errors
        form.querySelectorAll('.field-error').forEach(function (el) { el.remove(); });
        form.querySelectorAll('input').forEach(function (input) {
            input.style.borderColor = '';
        });

        let isValid = true;

        // Validate name
        const name = form.querySelector('#name');
        if (!name.value.trim()) {
            showError(name, 'Name is required');
            isValid = false;
        }

        // Validate email
        const email = form.querySelector('#email');
        const emailRegex = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
        if (!email.value.trim()) {
            showError(email, 'Email is required');
            isValid = false;
        } else if (!emailRegex.test(email.value)) {
            showError(email, 'Please enter a valid email address');
            isValid = false;
        }

        // Validate phone
        const phone = form.querySelector('#phone_number');
        const phoneClean = phone.value.replace(/[^\d+]/g, '');
        if (!phone.value.trim()) {
            showError(phone, 'Phone number is required');
            isValid = false;
        } else if (phoneClean.length < 7) {
            showError(phone, 'Please enter a valid phone number');
            isValid = false;
        }

        if (!isValid) {
            e.preventDefault();
            // Scroll to the first error
            const firstError = form.querySelector('.field-error');
            if (firstError) {
                firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
        }
    });
});
