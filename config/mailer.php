<?php
// ============================================================
// mailer.php — PHPMailer config
// Place this in /WMSU-Receive-System/config/mailer.php
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
    $mail->Username   = 'eh202201365@wmsu.edu.ph';
    $mail->Password   = 'cfcentyulrqcdfdl';       // App password (spaces removed)
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = 587;

    // ── Sender ────────────────────────────────────────────────
    $mail->setFrom('eh202201365@wmsu.edu.ph', 'WMSU Document Management');
    $mail->CharSet = 'UTF-8';

    return $mail;
}
