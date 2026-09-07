<?php
/**
 * Registration Page
 * 
 * GET: Display registration form
 * POST: Handle registration form submission
 * 
 * Equivalent to Python's routes/public.py registration endpoints.
 */

require_once __DIR__ . '/includes/db.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/registration_service.php';
require_once __DIR__ . '/includes/email_service.php';
require_once __DIR__ . '/includes/class_management_service.php';

// Start session FIRST (before any $_SESSION access)
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Ensure DB is initialized
runStartup();

$formHeading = REGISTRATION_FORM_HEADING;
$activeClass = (new ClassManagementService())->getOpenClass();
$error = '';
$formData = [
    'name' => '',
    'email' => '',
    'phone_number' => '',
];

if (isMethod('POST')) {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    
    $formData = [
        'name' => $name,
        'email' => $email,
        'phone_number' => $phone_number,
    ];
    
    // Validate fields
    $fieldErrors = [];
    
    if (empty($name)) {
        $fieldErrors[] = "Name is required";
    }
    
    if (empty($email)) {
        $fieldErrors[] = "Email is required";
    } elseif (!isValidEmail($email)) {
        $fieldErrors[] = "Please enter a valid email address";
    }
    
    if (empty($phone_number)) {
        $fieldErrors[] = "Phone number is required";
    } elseif (!isValidPhone($phone_number)) {
        $fieldErrors[] = "Please enter a valid phone number";
    }
    
    if (!empty($fieldErrors)) {
        $error = implode(' ', $fieldErrors);
    } else {
        try {
            $service = new RegistrationService();
            $result = $service->createRegistration($name, $email, $phone_number);
            
            // Send confirmation email (non-blocking)
            try {
                if (DEBUG) {
                    error_log("[REG] Attempting to send email to $email");
                }
                $emailSent = EmailService::sendRegistrationConfirmation(
                    toEmail: $email,
                    name: $name,
                    classTitle: $result['demo_class']['title'],
                    topic: $result['demo_class']['topic'] ?? '',
                    trainerName: $result['demo_class']['trainer_name'] ?? '',
                    scheduledDate: $result['demo_class']['scheduled_at'],
                    timezone: $result['demo_class']['timezone'],
                    teamsLink: $result['demo_class']['teams_link'] ?? '',
                    whatsappGroupUrl: WHATSAPP_GROUP_INVITE_URL
                );
                
                if ($emailSent) {
                    if (DEBUG) {
                        error_log("[REG] Email sent successfully to $email");
                    }
                } else {
                    if (DEBUG) {
                        error_log("[REG] Email was NOT sent to $email");
                    }
                }
            } catch (Exception $emailErr) {
                if (DEBUG) {
                    error_log("[REG] Email exception: " . $emailErr->getMessage());
                }
            }
            
            // Redirect to success page with data
            $_SESSION['registration_result'] = $result;
            session_write_close();
            redirectTo('/success.php');
            
        } catch (InvalidArgumentException $e) {
            $error = $e->getMessage();
            if (DEBUG) {
                error_log("[REG] Registration validation failed: $error");
            }
        } catch (Exception $e) {
            $error = "Registration failed. Please try again.";
            if (DEBUG) {
                error_log("[REG] Registration failed: " . $e->getMessage());
            }
        }
    }
}

// Get registration result if coming from POST redirect
$registration = $_SESSION['registration_result'] ?? null;
unset($_SESSION['registration_result']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="#fafafa">
    <script src="/assets/js/theme.js"></script>
    <title>Register</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <button type="button" class="theme-toggle" aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-icon theme-icon-moon" aria-hidden="true">&#9790;</span>
        <span class="theme-icon theme-icon-sun" aria-hidden="true">&#9728;</span>
    </button>
    <div class="container">
        <div class="header">
            <div class="ig-logo">
                <svg width="48" height="48" viewBox="0 0 48 48" fill="none">
                    <defs>
                        <linearGradient id="igGrad" x1="0" y1="48" x2="48" y2="0">
                            <stop offset="0%" stop-color="#f09433"/>
                            <stop offset="25%" stop-color="#e6683c"/>
                            <stop offset="50%" stop-color="#dc2743"/>
                            <stop offset="75%" stop-color="#cc2366"/>
                            <stop offset="100%" stop-color="#bc1888"/>
                        </linearGradient>
                    </defs>
                    <rect x="2" y="2" width="44" height="44" rx="12" stroke="url(#igGrad)" stroke-width="3" fill="none"/>
                    <circle cx="24" cy="24" r="10" stroke="url(#igGrad)" stroke-width="3" fill="none"/>
                    <circle cx="37" cy="11" r="2.5" fill="url(#igGrad)"/>
                </svg>
            </div>
            <h1><?= sanitize($formHeading) ?></h1>
            <p class="subtitle">Join our upcoming class and get updates via WhatsApp</p>
        </div>

        <?php if ($activeClass): ?>
        <div class="class-details registration-class-details">
            <div class="detail-grid">
                <div class="detail-item">
                    <label>Course</label>
                    <span><?= sanitize($activeClass['title']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Topic</label>
                    <span><?= sanitize($activeClass['topic']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Trainer</label>
                    <span><?= sanitize($activeClass['trainer_name']) ?></span>
                </div>
                <div class="detail-item">
                    <label>Date</label>
                    <span><?= sanitize(formatScheduledDate($activeClass['scheduled_at'], $activeClass['timezone'])) ?></span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <?php if ($activeClass): ?>
        <form method="POST" action="/register.php" class="registration-form" novalidate>
            <div class="form-group">
                <label for="name">Full Name</label>
                <input
                    type="text"
                    id="name"
                    name="name"
                    value="<?= sanitize($formData['name']) ?>"
                    required
                    autocomplete="name"
                    placeholder="Enter your full name"
                >
            </div>

            <div class="form-group">
                <label for="email">Email Address</label>
                <input
                    type="email"
                    id="email"
                    name="email"
                    value="<?= sanitize($formData['email']) ?>"
                    required
                    autocomplete="email"
                    placeholder="Enter your email address"
                >
            </div>

            <div class="form-group">
                <label for="phone_number">Phone Number</label>
                <input
                    type="tel"
                    id="phone_number"
                    name="phone_number"
                    value="<?= sanitize($formData['phone_number']) ?>"
                    required
                    autocomplete="tel"
                    placeholder="+1 234 567 8900"
                >
                <small class="help-text">Include country code for international numbers</small>
            </div>

            <div class="privacy-notice">
                By registering, you agree to our <a href="/terms.php">Terms of Service</a> and <a href="/privacy.php">Privacy Policy</a>. We collect your name, email, and phone number for registration purposes only.
            </div>

            <button type="submit" class="btn btn-primary">Register Now</button>
        </form>
        <?php else: ?>
        <div class="alert alert-warning" role="status">Registration is currently closed. Please check back later.</div>
        <?php endif; ?>

        <div class="footer">
            <p>Need help? Contact the organizer</p>
        </div>
    </div>

    <script src="/assets/js/validation.js"></script>
</body>
</html>
