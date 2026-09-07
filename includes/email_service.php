<?php
/**
 * Email Service
 * 
 * Sends registration confirmation emails via SMTP.
 * Equivalent to Python's email_service.py using SendGrid.
 * 
 * This uses PHP's native mail() function or can be extended with PHPMailer.
 */

require_once __DIR__ . '/../config.php';

class EmailService {
    
    /**
     * Send a registration confirmation email
     * Returns true on success, false on failure.
     */
    public static function sendRegistrationConfirmation(
        string $toEmail,
        string $name,
        string $classTitle,
        string $scheduledDate,
        string $timezone,
        string $topic = '',
        string $trainerName = '',
        string $teamsLink = '',
        string $whatsappGroupUrl = ''
    ): bool {
        
        if (!ENABLE_EMAIL) {
            if (DEBUG) {
                error_log("[EMAIL] Skipped - ENABLE_EMAIL=false");
            }
            return false;
        }
        
        if (empty(SMTP_USER) || empty(SMTP_FROM_EMAIL)) {
            if (DEBUG) {
                error_log("[EMAIL] Skipped - SMTP credentials not configured");
            }
            return false;
        }
        
        if (DEBUG) {
            error_log("[EMAIL] Starting email send to $toEmail");
        }
        
        try {
            // Render email template using PHP include with extracted variables
            $templateFile = __DIR__ . '/../templates/email/registration_confirmation.html';
            
            // Extract variables for the template
            $app_name = APP_NAME;
            $training_topic = $topic;
            $trainer_name = $trainerName;
            $teams_link = $teamsLink;
            $whatsapp_group_url = $whatsappGroupUrl;
            $whatsapp_group_name = WHATSAPP_GROUP_NAME;
            
            ob_start();
            include $templateFile;
            $htmlBody = ob_get_clean();
            
            // Plain text fallback
            $textBody = "Hi $name,\n\n";
            $textBody .= "Your registration for $classTitle is confirmed!\n";
            if ($topic) $textBody .= "Topic: $topic\n";
            if ($trainerName) $textBody .= "Trainer: $trainerName\n";
            $textBody .= "Date: $scheduledDate ($timezone)\n";
            if ($teamsLink) {
                $textBody .= "Teams Link: $teamsLink\n";
            }
            $textBody .= "\nSee you in class!";
            
            // Send via SMTP
            $subject = "Registration Confirmed - $classTitle";
            $from = SMTP_FROM_NAME . " <" . SMTP_FROM_EMAIL . ">";
            
            $result = self::sendSMTP($toEmail, $from, $subject, $textBody, $htmlBody);
            
            if ($result) {
                if (DEBUG) {
                    error_log("[EMAIL] Confirmation email sent to $toEmail");
                }
                return true;
            } else {
                if (DEBUG) {
                    error_log("[EMAIL] Failed to send email to $toEmail");
                }
                return false;
            }
            
        } catch (Exception $e) {
            if (DEBUG) {
                error_log("[EMAIL] FAILED: " . get_class($e) . ": " . $e->getMessage());
            }
            return false;
        }
    }
    
    /**
     * Send email using PHP's native mail() with SMTP-like headers
     * For production, consider using PHPMailer or similar library
     */
    private static function sendSMTP(string $to, string $from, string $subject, string $textBody, string $htmlBody): bool {
        $headers = [
            'MIME-Version: 1.0',
            'Content-type: text/html; charset=UTF-8',
            "From: $from",
            "Reply-To: " . SMTP_FROM_EMAIL,
            'X-Mailer: PHP/' . phpversion(),
        ];
        
        $headerString = implode("\r\n", $headers);
        
        // Use PHP's mail() function
        // For production SMTP, integrate PHPMailer or similar
        return mail($to, $subject, $htmlBody, $headerString);
    }
}
