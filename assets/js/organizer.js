/**
 * Organizer Dashboard JavaScript
 * 
 * Handles follow-up recording, deletion, and modal interactions.
 * Equivalent to Python project's static/js/organizer.js.
 */

// API key management
var API_KEY = '';

function classApiRequest(url, method, body) {
    var options = { method: method, headers: { 'X-API-Key': getApiKey(), 'Content-Type': 'application/json' } };
    if (body) options.body = JSON.stringify(body);
    return fetch(url, options).then(function(response) {
        return response.json().then(function(data) {
            if (!response.ok) throw new Error(data.error || 'Class operation failed');
            return data;
        });
    });
}

function resetClassForm() {
    var form = document.getElementById('classManagementForm');
    if (form) form.reset();
    document.getElementById('classId').value = '';
}

function editClass(classData) {
    document.getElementById('classId').value = classData.id;
    document.getElementById('classTitle').value = classData.title || '';
    document.getElementById('classTopic').value = classData.topic || '';
    document.getElementById('classTrainerName').value = classData.trainer_name || '';
    document.getElementById('classScheduledAt').value = (classData.scheduled_at || '').replace(' ', 'T').slice(0, 16);
    document.getElementById('classTimezone').value = classData.timezone || 'UTC';
    document.getElementById('classTeamsLink').value = classData.teams_link || '';
    document.getElementById('classCapacity').value = classData.capacity || '';
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

function showClassMessage(message, isError) {
    var element = document.getElementById('classManagementMessage');
    if (!element) return;
    element.textContent = message;
    element.className = 'alert ' + (isError ? 'alert-error' : 'alert-success');
    element.style.display = 'block';
}

function getApiKey() {
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

// Handle follow-up form submission
document.addEventListener('DOMContentLoaded', function() {
    var classForm = document.getElementById('classManagementForm');
    if (classForm) classForm.addEventListener('submit', function(event) {
        event.preventDefault();
        var formData = new FormData(classForm);
        var payload = Object.fromEntries(formData.entries());
        payload.registration_open = false;
        if (payload.capacity === '') payload.capacity = null;
        var id = payload.id;
        delete payload.id;
        classApiRequest('/organizer/api_demo_classes.php' + (id ? '?id=' + encodeURIComponent(id) : ''), id ? 'PUT' : 'POST', payload)
            .then(function() { window.location.reload(); })
            .catch(function(error) { showClassMessage(error.message, true); });
    });

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
});
