<?php
// Return JSON responses compatible with js/script.js
header('Content-Type: application/json');

// Include PHPMailer classes
require 'PHPMailer/src/Exception.php';
require 'PHPMailer/src/PHPMailer.php';
require 'PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

// Reduce error leakage in production; keep 0 (off) by default
error_reporting(E_ALL);
ini_set('display_errors', 0);

// Configuration fallback: prefer environment variables, otherwise use provided values
$CONFIG = [
    'RECAPTCHA_SECRET_KEY' => getenv('RECAPTCHA_SECRET_KEY') ?: '6LepqwIsAAAAANEoWBzgljCqFK69QvGJGDJFB31X',
    'EMAIL_USERNAME'       => getenv('EMAIL_USERNAME')       ?: 'farabetasnimul@gmail.com',
    'EMAIL_PASSWORD'       => getenv('EMAIL_PASSWORD')       ?: 'zwtpjrlmvlfderlh', // Gmail App Password (no spaces)
    'SMTP_HOST'            => getenv('SMTP_HOST')            ?: 'smtp.gmail.com',
    'SMTP_PORT'            => getenv('SMTP_PORT')            ?: 587,
];

function json_error($msg) {
    echo json_encode(['error' => $msg]);
    exit;
}

function json_success() {
    echo json_encode(['error' => false]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_error('Invalid request method.');
}

// Collect and sanitize inputs
$name    = isset($_POST['name'])    ? trim($_POST['name'])    : '';
$email   = isset($_POST['email'])   ? trim($_POST['email'])   : '';
$phone   = isset($_POST['phone'])   ? trim($_POST['phone'])   : '';
$message = isset($_POST['message']) ? trim($_POST['message']) : '';
$captcha = isset($_POST['g-recaptcha-response']) ? trim($_POST['g-recaptcha-response']) : '';

// Basic validation
if ($name === '' || $message === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    json_error('Please fill in all required fields correctly.');
}

// Verify Google reCAPTCHA v2
$recaptchaSecret = $CONFIG['RECAPTCHA_SECRET_KEY'];
if ($recaptchaSecret === '') {
    // If not configured, fail closed to prevent spam
    json_error('Captcha is not configured.');
}
if ($captcha === '') {
    json_error('Please complete the captcha.');
}

// Verify with Google
$verifyUrl = 'https://www.google.com/recaptcha/api/siteverify';
$postData = http_build_query([
    'secret'   => $recaptchaSecret,
    'response' => $captcha,
    'remoteip' => $_SERVER['REMOTE_ADDR'] ?? ''
]);

$result = false;
if (function_exists('curl_init')) {
    $ch = curl_init($verifyUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
    $resp = curl_exec($ch);
    curl_close($ch);
    $result = $resp;
} else {
    $opts = [ 'http' => [ 'method' => 'POST', 'header' => 'Content-type: application/x-www-form-urlencoded', 'content' => $postData, 'timeout' => 10 ] ];
    $context = stream_context_create($opts);
    $result = @file_get_contents($verifyUrl, false, $context);
}

if (!$result) {
    json_error('Captcha verification failed.');
}

$decoded = json_decode($result, true);
if (!$decoded || empty($decoded['success'])) {
    json_error('Captcha invalid.');
}

// Prevent header injection in names/addresses
$name  = preg_replace("/[\r\n]+/", ' ', strip_tags($name));
$phone = preg_replace("/[\r\n]+/", ' ', strip_tags($phone));

// Configure PHPMailer
$mail = new PHPMailer(true);
try {
    $mail->CharSet = 'UTF-8';
    $mail->isSMTP();
    $mail->Host       = $CONFIG['SMTP_HOST'];
    $mail->SMTPAuth   = true;
    $mail->Username   = $CONFIG['EMAIL_USERNAME'];
    $mail->Password   = $CONFIG['EMAIL_PASSWORD'];
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = (int)$CONFIG['SMTP_PORT'];
    $mail->SMTPDebug  = 0; // 0 in production
    $mail->Timeout    = 15;

    // From must typically match authenticated account for Gmail/most SMTP servers
    $mail->setFrom($mail->Username, 'Website Contact Form');
    // Where you want to receive messages (add both for testing/delivery)
    $mail->addAddress('farabetasnimul@gmail.com');
    $mail->addAddress('farabetasnimul@hotmail.com');
    // Let replies go to the sender who filled the form
    $mail->addReplyTo($email, $name);

    // Message body
    $mail->isHTML(true);
    $mail->Subject = 'New Contact Form Submission';
    $safeMessage = nl2br(htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    $mail->Body    = "<p>You have received a new message from your website contact form.</p>"
                  . "<p><strong>Name:</strong> " . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . "<br>"
                  . "<strong>Email:</strong> " . htmlspecialchars($email, ENT_QUOTES, 'UTF-8') . "<br>"
                  . "<strong>Phone:</strong> " . htmlspecialchars($phone, ENT_QUOTES, 'UTF-8') . "</p>"
                  . "<p><strong>Message:</strong><br>" . $safeMessage . "</p>";
    $mail->AltBody = "New contact form submission\n"
                  . "Name: $name\nEmail: $email\nPhone: $phone\n\nMessage:\n$message\n";

    // Validate credentials presence before sending
    if (empty($mail->Password)) {
        json_error('Email service is not configured. Please set EMAIL_PASSWORD on the server.');
    }

    // Send and respond JSON for the frontend to show success alert
    $mail->send();
    json_success();
} catch (Exception $e) {
    // Log server-side for troubleshooting without exposing details to user
    if (function_exists('error_log')) {
        error_log('Mailer error: ' . $e->getMessage());
    }
    // Do not leak detailed errors to the client in production
    json_error('Sorry, your message could not be sent. Please try again later.');
}
?>
