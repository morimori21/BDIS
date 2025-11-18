<?php
require_once __DIR__ . '/../vendor/autoload.php';
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Email configuration - Update these settings with your email provider details
define('SMTP_HOST', 'smtp.gmail.com');  // Change to your SMTP host
define('SMTP_PORT', 587);
define('SMTP_USERNAME', 'sanjosefnorte172@gmail.com');  // Change to your email
define('SMTP_PASSWORD', 'scbl hhjv tluo fqwh');     // Change to your app password
define('FROM_EMAIL', 'sanjosefnorte172@gmail.com');     // Change to your email
define('FROM_NAME', 'BDIS - Barangay Document Issuance System');


function sendAccountStatusEmail($to, $name, $status, $remarks = null) {
        $mail = new PHPMailer(true);
    try {
    
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USERNAME;
        $mail->Password   = SMTP_PASSWORD;
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = SMTP_PORT;

        $mail->SMTPDebug = SMTP::DEBUG_SERVER; // enable SMTP debug
        $mail->Debugoutput = 'error_log';

            // Recipients
        $mail->setFrom(FROM_EMAIL, FROM_NAME);
        $mail->addAddress($to, $name);

        $mail->isHTML(true);
        $mail->Subject = $status === 'verified' 
            ? 'Your Account Has Been Verified'
            : 'Your Account Registration Was Rejected';

        $body = $status === 'verified'
            ? "<p>Dear $name,</p>
               <p>Congratulations! Your account has been <strong>verified</strong> by the admin.</p>
               <p>You can now login to access services.</p>
               <p>Regards,<br>Barangay Registration System</p>"
            : "<p>Dear $name,</p>
               <p>We regret to inform you that your registration was <strong>rejected</strong>.</p>
               <p>Reason: " . htmlspecialchars($remarks) . "</p>
               <p>Please contact the barangay office for assistance.</p>
               <p>Regards,<br>Barangay Registration System</p>";

        $mail->Body = $body;
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Email sending failed to $to: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send document request approval email
 * @param string $to_email Recipient email address
 * @param string $to_name Recipient name
 * @param string $document_type Type of document requested
 * @param int $request_id Request ID
 * @return bool True if email sent successfully, false otherwise
 */
function sendDocumentApprovalEmail($to_email, $to_name, $document_type, $request_id) {
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
        $mail->Subject = 'Document Request Approved - BDIS';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                .container { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #28a745; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { padding: 30px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-top: none; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #6c757d; }
                .btn { display: inline-block; padding: 10px 20px; background-color: #007bff; color: white; text-decoration: none; border-radius: 5px; margin-top: 15px; }
                .highlight { background-color: #fff3cd; padding: 15px; border-left: 4px solid #ffc107; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>✓ Request Approved</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>" . htmlspecialchars($to_name) . "</strong>,</p>
                    
                    <p>We are pleased to inform you that your document request has been <strong>approved</strong> by the barangay secretary.</p>
                    
                    <div class='highlight'>
                        <strong>Document Type:</strong> " . htmlspecialchars($document_type) . "<br>
                        <strong>Request ID:</strong> #" . htmlspecialchars($request_id) . "<br>
                        <strong>Status:</strong> In Progress
                    </div>
                    
                    <p>Your document is now being processed. You will receive another notification once your document is ready for pickup.</p>
                    
                    <p>You can track the status of your request by logging into the BDIS portal.</p>
                    
                    <p style='margin-top: 30px;'>
                        Best regards,<br>
                        <strong>Barangay Document Issuance System</strong><br>
                        San Jose Norte, Cabanatuan City
                    </p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply to this message.</p>
                    <p>&copy; 2025 BDIS - Barangay Document Issuance System</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->AltBody = "Dear $to_name,\n\nYour document request has been approved.\n\nDocument Type: $document_type\nRequest ID: #$request_id\nStatus: In Progress\n\nYou will receive another notification once your document is ready for pickup.\n\nBest regards,\nBarangay Document Issuance System";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Document approval email failed to $to_email: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send document request rejection email
 * @param string $to_email Recipient email address
 * @param string $to_name Recipient name
 * @param string $document_type Type of document requested
 * @param int $request_id Request ID
 * @param string $rejection_reason Reason for rejection
 * @return bool True if email sent successfully, false otherwise
 */
function sendDocumentRejectionEmail($to_email, $to_name, $document_type, $request_id, $rejection_reason) {
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
        $mail->Subject = 'Document Request Rejected - BDIS';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                .container { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #dc3545; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { padding: 30px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-top: none; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #6c757d; }
                .highlight { background-color: #f8d7da; padding: 15px; border-left: 4px solid #dc3545; margin: 15px 0; }
                .reason-box { background-color: #fff; padding: 15px; border: 1px solid #dee2e6; border-radius: 5px; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>✗ Request Rejected</h1>
                </div>
                <div class='content'>
                    <p>Dear <strong>" . htmlspecialchars($to_name) . "</strong>,</p>
                    
                    <p>We regret to inform you that your document request has been <strong>rejected</strong> by the barangay secretary.</p>
                    
                    <div class='highlight'>
                        <strong>Document Type:</strong> " . htmlspecialchars($document_type) . "<br>
                        <strong>Request ID:</strong> #" . htmlspecialchars($request_id) . "<br>
                        <strong>Status:</strong> Rejected
                    </div>
                    
                    <div class='reason-box'>
                        <strong>Reason for Rejection:</strong><br>
                        <p style='margin: 10px 0 0 0; color: #721c24;'>" . htmlspecialchars($rejection_reason) . "</p>
                    </div>
                    
                    <p>If you believe this decision was made in error or if you need further clarification, please visit the barangay office during office hours or contact us directly.</p>
                    
                    <p>You may submit a new request after addressing the issues mentioned above.</p>
                    
                    <p style='margin-top: 30px;'>
                        Best regards,<br>
                        <strong>Barangay Document Issuance System</strong><br>
                        San Jose Norte, Cabanatuan City
                    </p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply to this message.</p>
                    <p>&copy; 2025 BDIS - Barangay Document Issuance System</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->AltBody = "Dear $to_name,\n\nYour document request has been rejected.\n\nDocument Type: $document_type\nRequest ID: #$request_id\nStatus: Rejected\n\nReason: $rejection_reason\n\nPlease visit the barangay office for more information or to submit a new request.\n\nBest regards,\nBarangay Document Issuance System";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Document rejection email failed to $to_email: " . $mail->ErrorInfo);
        return false;
    }
}

/**
 * Send document ready for pickup email
 * @param string $to_email Recipient email address
 * @param string $to_name Recipient name
 * @param string $document_type Type of document requested
 * @param int $request_id Request ID
 * @return bool True if email sent successfully, false otherwise
 */
function sendDocumentReadyEmail($to_email, $to_name, $document_type, $request_id) {
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
        $mail->Subject = 'Document Ready for Pickup - BDIS';
        
        $mail->Body = "
        <html>
        <head>
            <style>
                .container { font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background-color: #28a745; color: white; padding: 20px; text-align: center; border-radius: 5px 5px 0 0; }
                .content { padding: 30px; background-color: #f8f9fa; border: 1px solid #dee2e6; border-top: none; }
                .footer { text-align: center; padding: 20px; font-size: 12px; color: #6c757d; }
                .highlight { background-color: #d4edda; padding: 15px; border-left: 4px solid #28a745; margin: 15px 0; }
                .pickup-info { background-color: #fff; padding: 20px; border: 2px solid #28a745; border-radius: 5px; margin: 20px 0; }
                .icon { font-size: 48px; text-align: center; margin: 15px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>✓ Document Ready for Pickup</h1>
                </div>
                <div class='content'>
                    <div class='icon'>📄</div>
                    
                    <p>Dear <strong>" . htmlspecialchars($to_name) . "</strong>,</p>
                    
                    <p>Great news! Your document request has been <strong>completed</strong> and is now <strong>ready for pickup</strong>.</p>
                    
                    <div class='highlight'>
                        <strong>Document Type:</strong> " . htmlspecialchars($document_type) . "<br>
                        <strong>Request ID:</strong> #" . htmlspecialchars($request_id) . "<br>
                        <strong>Status:</strong> <span style='color: #28a745;'>✓ Ready for Pickup</span>
                    </div>
                    
                    <div class='pickup-info'>
                        <h3 style='margin-top: 0; color: #28a745;'>📍 Pickup Instructions</h3>
                        <p><strong>Location:</strong> Barangay Office, San Jose Norte, Cabanatuan City</p>
                        <p><strong>Office Hours:</strong> Monday to Friday, 8:00 AM - 5:00 PM</p>
                        <p><strong>What to bring:</strong></p>
                        <ul>
                            <li>Valid government-issued ID</li>
                            <li>Request ID: #" . htmlspecialchars($request_id) . "</li>
                        </ul>
                    </div>
                    
                    <p><strong>Important:</strong> Please claim your document within <strong>30 days</strong> from the date of this notice. Unclaimed documents may be disposed of after the specified period.</p>
                    
                    <p>If you have any questions or concerns, please don't hesitate to contact the barangay office.</p>
                    
                    <p style='margin-top: 30px;'>
                        Best regards,<br>
                        <strong>Barangay Document Issuance System</strong><br>
                        San Jose Norte, Cabanatuan City
                    </p>
                </div>
                <div class='footer'>
                    <p>This is an automated email. Please do not reply to this message.</p>
                    <p>&copy; 2025 BDIS - Barangay Document Issuance System</p>
                </div>
            </div>
        </body>
        </html>
        ";

        $mail->AltBody = "Dear $to_name,\n\nYour document request is now ready for pickup!\n\nDocument Type: $document_type\nRequest ID: #$request_id\nStatus: Ready for Pickup\n\nPickup Location: Barangay Office, San Jose Norte, Cabanatuan City\nOffice Hours: Monday to Friday, 8:00 AM - 5:00 PM\n\nPlease bring:\n- Valid government-issued ID\n- Request ID: #$request_id\n\nPlease claim your document within 30 days.\n\nBest regards,\nBarangay Document Issuance System";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Document ready email failed to $to_email: " . $mail->ErrorInfo);
        return false;
    }
}
?>
