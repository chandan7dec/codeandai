/**
 * Organizer Dashboard JavaScript
 * 
 * Handles follow-up recording, deletion, and modal interactions.
 * Equivalent to Python project's static/js/organizer.js.
 */

// API key management
var API_KEY = '';

// Registration state of the class currently loaded into the edit form.
// New classes default to closed (use the "Open" button after creating); when
// editing, editClass() seeds this from the class so a save never silently
// closes a registration that was open.
var currentEditRegistrationOpen = false;

function classApiRequest(url, method, body) {
    var options = { method: method, headers: { 'X-API-Key': getApiKey(), 'Content-Type': 'application/json' } };
    if (body) options.body = JSON.stringify(body);
    return fetch(url, options).then(function(response) {
        return response.text().then(function(text) {
            // Parse defensively: an empty or non-JSON body (network drop, PHP
            // fatal, proxy timeout) previously crashed response.json() with
            // "Unexpected end of JSON input" instead of a readable error.
            var data = {};
            if (text) {
                try { data = JSON.parse(text); } catch (e) { data = {}; }
            }
            if (!response.ok) {
                var error = new Error(data.error || 'Class operation failed (HTTP ' + response.status + ')');
                if (data.fields) error.fields = data.fields;
                if (data.detail) error.message += ' — ' + data.detail;
                throw error;
            }
            return data;
        });
    });
}

function resetClassForm() {
    var form = document.getElementById('classManagementForm');
    if (form) form.reset();
    document.getElementById('classId').value = '';
    currentEditRegistrationOpen = false;
    updatePriceFieldState();
}

// Enable the price input only when the "Is Paid" checkbox is ticked.
function updatePriceFieldState() {
    var isPaid = document.getElementById('classIsPaid');
    var price = document.getElementById('classPrice');
    if (isPaid && price) {
        price.disabled = !isPaid.checked;
        if (!isPaid.checked) price.value = '';
    }
}

function editClass(classData) {
    document.getElementById('classId').value = classData.id;
    currentEditRegistrationOpen = parseInt(classData.registration_open, 10) === 1;
    document.getElementById('classTitle').value = classData.title || '';
    document.getElementById('classTopic').value = classData.topic || '';
    document.getElementById('classTrainerName').value = classData.trainer_name || '';
    document.getElementById('classScheduledAt').value = (classData.scheduled_at || '').replace(' ', 'T').slice(0, 16);
    document.getElementById('classTimezone').value = classData.timezone || 'UTC';
    document.getElementById('classTeamsLink').value = classData.teams_link || '';
    document.getElementById('classCapacity').value = classData.capacity || '';
    var isPaid = document.getElementById('classIsPaid');
    var price = document.getElementById('classPrice');
    if (isPaid) isPaid.checked = parseInt(classData.is_paid, 10) === 1;
    if (price) {
        price.value = classData.is_paid ? (classData.price || '') : '';
        price.disabled = !isPaid.checked;
    }
    window.scrollTo({ top: 0, behavior: 'smooth' });
}

function changeClassStatus(id, action) {
    classApiRequest('/organizer/api_class_status.php?id=' + encodeURIComponent(id), 'POST', { action: action })
        .then(function() { window.location.reload(); })
        .catch(function(error) { showClassMessage(error.message, true); });
}

function deleteClass(id, title) {
    if (!confirm('Delete class "' + title + '"? This cannot be undone.')) return;
    classApiRequest('/organizer/api_demo_classes.php?id=' + encodeURIComponent(id), 'DELETE')
        .then(function() { window.location.reload(); })
        .catch(function(error) { showClassMessage(error.message, true); });
}

function changeClassStatus(id, action) {
    classApiRequest('/organizer/api_class_status.php?id=' + encodeURIComponent(id), 'POST', { action: action })
        .then(function() { window.location.reload(); })
        .catch(function(error) { showClassMessage(error.message, true); });
}

// Manual reconciliation: admin override of payment status (missed callbacks).
function reconcilePayment(paymentId, status) {
    var action = status === 'success'
        ? 'Mark this payment as SUCCESS and confirm the registration?'
        : 'Mark this payment as FAILED and release the seat?';
    if (!confirm(action + '\nUse this only if the UPI callback was missed.')) return;
    classApiRequest('/organizer/api_payment_reconcile.php?payment_id=' + encodeURIComponent(paymentId), 'POST', { status: status })
        .then(function() { window.location.reload(); })
        .catch(function(error) { showClassMessage(error.message, true); });
}

// ── Training Resources ──

// Show the YouTube or Drive fields depending on the selected resource type.
function updateResourceFieldVisibility() {
    var type = document.getElementById('resType');
    if (!type) return;
    document.querySelectorAll('#resourceForm .res-field-group').forEach(function (group) {
        var kinds = (group.getAttribute('data-for') || '').split(/\s+/);
        group.hidden = kinds.indexOf(type.value) === -1;
    });
}

function setResourceMessage(message, isError) {
    var element = document.getElementById('resourceMessage');
    if (!element) return;
    element.textContent = message;
    element.style.color = isError ? '#dc2626' : '';
}

function addResource(event) {
    event.preventDefault();
    var classId = document.getElementById('resClassId').value;
    var type = document.getElementById('resType').value;
    var payload = {
        class_id: classId,
        type: type,
        title: document.getElementById('resTitle').value.trim(),
        is_published: document.getElementById('resPublished').value === '1'
    };
    if (type === 'recording') {
        payload.youtube_url = document.getElementById('resYoutubeUrl').value.trim();
    } else {
        payload.drive_url = document.getElementById('resDriveUrl').value.trim();
        var fileName = document.getElementById('resFileName').value.trim();
        var fileSize = document.getElementById('resFileSize').value.trim();
        if (fileName) payload.file_name = fileName;
        if (fileSize) payload.file_size_label = fileSize;
    }
    if (!payload.class_id) { setResourceMessage('Select a training class first.', true); return; }

    classApiRequest('/organizer/api_training_resources.php', 'POST', payload)
        .then(function(data) {
            setResourceMessage(data.message || 'Resource added.');
            window.location.reload();
        })
        .catch(function(error) {
            var fields = error.fields || {};
            var detail = Object.values(fields).join(' ');
            setResourceMessage((error.message || 'Failed to add resource.') + (detail ? ' — ' + detail : ''), true);
        });
}

function toggleResourcePublish(id, publish) {
    classApiRequest('/organizer/api_training_resources.php?action=publish', 'POST', { id: id, published: publish })
        .then(function() { window.location.reload(); })
        .catch(function(error) { setResourceMessage(error.message, true); });
}

function deleteResource(id) {
    if (!confirm('Delete this resource? This cannot be undone.')) return;
    classApiRequest('/organizer/api_training_resources.php?id=' + encodeURIComponent(id), 'DELETE')
        .then(function() { window.location.reload(); })
        .catch(function(error) { setResourceMessage(error.message, true); });
}

function showClassMessage(message, isError) {
    var element = document.getElementById('classManagementMessage');
    if (!element) return;
    element.textContent = message;
    element.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
    element.style.display = 'block';
}

function getApiKey() {
    // Session-authenticated (logged in via login.php): requests need no key.
    if (window.ORGANIZER_SESSION) return 'session';
    if (API_KEY) return API_KEY;
    
    // Try to get from localStorage
    API_KEY = localStorage.getItem('organizer_api_key');
    if (API_KEY) return API_KEY;
    
    // Prompt user
    API_KEY = prompt('Enter organizer API key:');
    if (API_KEY) {
        localStorage.setItem('organizer_api_key', API_KEY);
    }
    
    return API_KEY || '';
}

// Modal management
function recordFollowUp(registrationId) {
    var modal = document.getElementById('followUpModal');
    var registrationIdInput = document.getElementById('registrationId');
    
    registrationIdInput.value = registrationId;
    modal.style.display = 'flex';
}

function closeModal() {
    var modal = document.getElementById('followUpModal');
    modal.style.display = 'none';
    
    // Reset form
    document.getElementById('followUpForm').reset();
}

// Close modal when clicking outside
window.onclick = function(event) {
    var modal = document.getElementById('followUpModal');
    if (event.target === modal) {
        closeModal();
    }
};

// Handle follow-up form submission (see also class form handler below)
document.addEventListener('DOMContentLoaded', function() {
    var classForm = document.getElementById('classManagementForm');
    if (classForm) {
        // Toggle price field availability with the "Is Paid" checkbox.
        var isPaidCheckbox = document.getElementById('classIsPaid');
        if (isPaidCheckbox) isPaidCheckbox.addEventListener('change', updatePriceFieldState);
        updatePriceFieldState();

        classForm.addEventListener('submit', function(event) {
        event.preventDefault();
        var formData = new FormData(classForm);
        var payload = Object.fromEntries(formData.entries());
        // Preserve the class's current registration state (hard-coding false
        // here closed registration on every edit of an existing class).
        payload.registration_open = currentEditRegistrationOpen;
        if (payload.capacity === '') payload.capacity = null;
        // "Is Paid" checkbox: absent from FormData when unchecked.
        payload.is_paid = document.getElementById('classIsPaid').checked ? 1 : 0;
        if (!payload.is_paid || payload.price === '' || payload.price === undefined) {
            payload.price = '0.00';
        }
        var id = payload.id;
        delete payload.id;
        classApiRequest('/organizer/api_demo_classes.php' + (id ? '?id=' + encodeURIComponent(id) : ''), id ? 'PUT' : 'POST', payload)
            .then(function() { window.location.reload(); })
            .catch(function(error) { showClassMessage(error.message, true); });
        });
    }

    var followUpForm = document.getElementById('followUpForm');
    if (!followUpForm) return;
    
    followUpForm.addEventListener('submit', function(e) {
        e.preventDefault();
        
        var formData = new FormData(followUpForm);
        var registrationId = formData.get('registration_id');
        
        // Create form data for URL encoded submission
        var urlEncodedData = new URLSearchParams(formData).toString();
        
        fetch('/organizer/api_follow_up.php?registration_id=' + registrationId, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-API-Key': getApiKey()
            },
            body: urlEncodedData
        })
        .then(function(response) {
            return response.json().then(function(data) {
                if (!response.ok) {
                    throw new Error(data.error || 'Failed to record follow-up');
                }
                return data;
            });
        })
        .then(function(data) {
            alert('Follow-up recorded successfully!');
            closeModal();
            // Reload page to show updated data
            window.location.reload();
        })
        .catch(function(error) {
            alert('Error: ' + error.message);
        });
    });
});

// Delete a registration
function deleteRegistration(registrationId, registrantName) {
    if (!confirm('Are you sure you want to delete the registration for "' + registrantName + '"?\n\nThis action cannot be undone.')) {
        return;
    }
    
    var apiKey = getApiKey();
    
    fetch('/organizer/api_delete.php?registration_id=' + registrationId, {
        method: 'DELETE',
        headers: {
            'X-API-Key': apiKey
        }
    })
    .then(function(response) {
        return response.json().then(function(data) {
            if (!response.ok) {
                throw new Error(data.error || 'Failed to delete registration');
            }
            return data;
        });
    })
    .then(function(data) {
        alert('Registration deleted successfully!');
        window.location.reload();
    })
    .catch(function(error) {
        alert('Error: ' + error.message);
    });
}

// Auto-store API key from URL on page load
document.addEventListener('DOMContentLoaded', function() {
    var urlParams = new URLSearchParams(window.location.search);
    var urlApiKey = urlParams.get('api_key');
    if (urlApiKey) {
        localStorage.setItem('organizer_api_key', urlApiKey);
        API_KEY = urlApiKey;
    }

    // Training resources form wiring
    var resourceForm = document.getElementById('resourceForm');
    if (resourceForm) {
        resourceForm.addEventListener('submit', addResource);
        document.getElementById('resType').addEventListener('change', updateResourceFieldVisibility);
        updateResourceFieldVisibility();
    }

    initCollapsibleSections();
});

// ── Collapsible dashboard sections ──
// Each section heading toggles its .section-body. State is remembered in
// localStorage per section id so the layout survives page reloads.
function initCollapsibleSections() {
    var STORAGE_KEY = 'organizer_dashboard_sections';
    var state = {};
    try {
        state = JSON.parse(localStorage.getItem(STORAGE_KEY) || '{}');
        if (typeof state !== 'object' || state === null || Array.isArray(state)) {
            state = {};
        }
    } catch (e) {
        state = {};
    }

    function persist() {
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
        } catch (e) {
            /* storage unavailable (private mode) — collapsing just won't persist */
        }
    }

    function apply(section, collapsed) {
        section.classList.toggle('collapsed', collapsed);
        var toggle = section.querySelector('.section-toggle');
        if (toggle) {
            toggle.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
        }
    }

    document.querySelectorAll('.collapsible-section').forEach(function(section) {
        var id = section.id;
        if (!id) {
            return;
        }

        // Restore previous session state
        if (state[id]) {
            apply(section, true);
        }

        var toggle = section.querySelector('.section-toggle');
        if (!toggle) {
            return;
        }

        toggle.addEventListener('click', function() {
            var collapsed = !section.classList.contains('collapsed');
            apply(section, collapsed);
            state[id] = collapsed;
            persist();
        });

        // Keyboard support (heading has role="button" + tabindex="0")
        toggle.addEventListener('keydown', function(e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                toggle.click();
            }
        });
    });
}
