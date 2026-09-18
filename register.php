<?php

declare(strict_types=1);
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
secureSessionStart();

// Ensure DB is initialized
runStartup();
sendSecurityHeaders();

$formHeading = REGISTRATION_FORM_HEADING;
$classService = new ClassManagementService();
// Which class is this page for?
//  - ?class=ID  -> the Register button on the training calendar
//  - otherwise  -> earliest open class (previous behaviour)
$requestedClass = null;
$rawClassParam = trim((string)($_GET['class'] ?? ''));
if ($rawClassParam !== '' && preg_match('/^[a-zA-Z0-9_-]{1,64}$/', $rawClassParam)) {
    $requestedClass = $classService->getById($rawClassParam);
    if ($requestedClass && $requestedClass['status'] === 'active' && !empty($requestedClass['registration_open']) && empty($requestedClass['is_past'])) {
    } else {
        $requestedClass = null;
    }
}
$activeClass = $requestedClass ?? $classService->getOpenClass();
$error = '';
$formData = [
    'name' => '',
    'email' => '',
    'phone_number' => '',
];

if (isMethod('POST')) {
    // ── CSRF check ──
    if (!csrfVerify($_POST['csrf_token'] ?? null)) {
        $error = "Your session expired. Please try again.";
        if (DEBUG) {
            error_log("[REG] CSRF token missing/invalid");
        }
    } else
    // ── Rate limit: 5 submissions / 10 min per IP (blocks spam seat-filling) ──
    if (!rateLimitRequest('register:' . clientIp(), 5, 600)) {
        $error = "Too many attempts. Please wait a few minutes and try again.";
        if (DEBUG) {
            error_log("[REG] Rate limit exceeded for " . clientIp());
        }
    } else {
    // Get form data
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $phone_number = trim($_POST['phone_number'] ?? '');
    $postedClassId = trim((string)($_POST['class_id'] ?? ''));
    
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
            $result = $service->createRegistration($name, $email, $phone_number, false, $postedClassId !== '' ? $postedClassId : null);

            // Paid class: hand over to the UPI payment page. The registration is
            // created with status 'pending'; the email/WhatsApp flow only runs
            // once the payment callback confirms the seat.
            if (!empty($result['requires_payment'])) {
                $_SESSION['payment_context'] = $result;
                session_write_close();
                redirectTo('/payment.php?order=' . urlencode($result['payment']['merchant_order_id']));
            }

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
    } // end rate-limit/CSRF else
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
    <meta name="description" content="Register for a live Code & AI training session — Python, AI tools and full-stack development, taught by industry experts.">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Register | Code & AI Live Training">
    <meta property="og:description" content="Reserve your seat for the next live training session.">
    <meta property="og:url" content="https://learnai.dpdns.org/register.php">
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
                <?php if (!empty($activeClass['is_paid'])): ?>
                <div class="detail-item">
                    <label>Fee</label>
                    <span class="price-tag">₹<?= number_format((float)$activeClass['price'], 2) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="alert alert-error" role="alert"><?= sanitize($error) ?></div>
        <?php endif; ?>

        <?php if ($activeClass): ?>
        <form method="POST" action="/register.php" class="registration-form" novalidate>
            <?= csrfField() ?>
            <input type="hidden" name="class_id" value="<?= sanitize($activeClass['id']) ?>">
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

            <?php if (!empty($activeClass['is_paid'])): ?>
            <div class="payment-hint">
                This is a <strong>paid class</strong> (₹<?= number_format((float)$activeClass['price'], 2) ?>). After registering you will be taken to a secure UPI payment page to confirm your seat.
            </div>
            <button type="submit" class="btn btn-primary">Register &amp; Pay via UPI</button>
            <?php else: ?>
            <button type="submit" class="btn btn-primary">Register Now</button>
            <?php endif; ?>
        </form>
        <?php else: ?>
        <div class="alert alert-warning" role="status">Registration is currently closed. Please check back later.</div>
        <?php endif; ?>

        <?php siteFooter('<p class="footer-note">Need help? Contact the organizer</p>'); ?>
    </div>

    <script src="/assets/js/validation.js"></script>
</body>
</html>
