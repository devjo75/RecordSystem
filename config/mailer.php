<?php
// ============================================================
// mailer.php — PHPMailer config for InfinityFree
// ============================================================

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../phpmailer/src/Exception.php';
require_once __DIR__ . '/../phpmailer/src/PHPMailer.php';
require_once __DIR__ . '/../phpmailer/src/SMTP.php';

function createMailer(): PHPMailer {
    $mail = new PHPMailer(true);

    // ── SMTP Settings ─────────────────────────────────────────
    $mail->isSMTP();
    $mail->Host       = 'smtp.gmail.com';
    $mail->SMTPAuth   = true;
    $mail->Username   = 'sardanijolouis27@gmail.com';           // ← Keep or change
    $mail->Password   = 'hvwvfflefmupfqik';                  // ← Replace with real Gmail App Password
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // ── Sender ────────────────────────────────────────────────
    $mail->setFrom('sardanijolouis27@gmail.com', 'WMSU Document Management');
    $mail->CharSet = 'UTF-8';

    return $mail;
}