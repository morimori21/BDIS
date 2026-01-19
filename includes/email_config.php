<?php
require_once __DIR__ . '/../vendor/autoload.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Email configuration
define('SMTP_HOST', 'smtp.gmail.com');  
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'sanjosefnorte172@gmail.com'); 
define('SMTP_PASSWORD', 'scbl hhjv tluo fqwh');     
define('FROM_EMAIL', 'sanjosefnorte172@gmail.com');    
define('FROM_NAME', 'BDIS - Barangay Document Issuance System');

/**
 * Send OTP verification email
 * @param string $to_email Recipient email address
 * @param string $to_name Recipient name
 * @param string $otp_code OTP verification code
 * @return bool True if email sent successfully, false otherwise
 */
function sendOTPEmail($to_email, $to_name, $otp_code, $subject = 'BDIS Verification Code', $contextLine = 'To verify your action, please use the following verification code:') {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        // Recipients
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to_email, $to_name);

        // Content
        $mail->isHTML(true);
        $mail->Subject = $subject;
        
        $mail->Body = "
        <html>
        <head>
            <style>
                .container { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #007bff; color: white; padding: 20px; text-align: center; }
                .content { padding: 30px; background-color: #f8f9fa; }
                .otp-code { background-color: #e9ecef; padding: 15px; text-align: center; font-size: 24px; font-weight: bold; margin: 20px 0; border-radius: 5px; }
                .footer { padding: 20px; text-align: center; font-size: 12px; color: #6c757d; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h2>BDIS Verification</h2>
                </div>
                <div class='content'>
                    <p>Dear {$to_name},</p>
                    <p>{$contextLine}</p>
                    <div class='otp-code'>{$otp_code}</div>
                    <p>This code will expire in 15 minutes for security purposes.</p>
                    <p>If you did not request this action, please ignore this email.</p>
                </div>
                <div class='footer'>
                    <p>This is an automated email from BDIS. Please do not reply to this email.</p>
                </div>
            </div>
        </body>
        </html>";

        $mail->AltBody = "Dear {$to_name},\n\n{$contextLine}\n\nYour verification code is: {$otp_code}\n\nThis code will expire in 15 minutes.\n\nIf you did not request this action, please ignore this email.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed: {$mail->ErrorInfo}");
        return false;
    }
}

/**
 * Generate a random 6-digit OTP code
 * @return string
 */
function generateOTP() {
    return sprintf('%06d', mt_rand(100000, 999999));
}
?>