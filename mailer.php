<?php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Manually require PHPMailer files
require 'PHPMailer/Exception.php';
require 'PHPMailer/PHPMailer.php';
require 'PHPMailer/SMTP.php';

function sendOTPCode($toEmail, $otp) {
    $mail = new PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        //$mail->Host       = 'smtp.gmail.com';
        //$mail->SMTPAuth   = true;
        //$mail->Username   = 'your-email@gmail.com';        // Your SMTP email
        //$mail->Password   = 'your-app-password';           // Your App Password
        $mail->isSMTP();
        $mail->Host       = 'sandbox.smtp.mailtrap.io';
        $mail->SMTPAuth   = true;
        $mail->Port       = 2525;
        $mail->Username   = 'f20eb5f67cad5e';
        $mail->Password   = '0813a35170c8f2';
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        $mail->Port       = 587;

        // Recipients
        $mail->setFrom('no-reply@driveease.com', 'DriveEase Rentals');
        $mail->addAddress($toEmail);

        // Content
        $mail->isHTML(false);
        $mail->Subject = 'Your DriveEase 2FA Login Code';
        $mail->Body    = "Your verification code is: $otp\n\nThis code expires in 10 minutes.";

        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log("Mail Error: {$mail->ErrorInfo}");
        return false;
    }
}
?>