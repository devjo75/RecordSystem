<?php
session_start();

require_once '../auth-guard/Auth.php';
require_once '../config/db.php';
require_once '../config/mailer.php';

$pdo     = getPDO();
$success = '';
$error   = '';

define('UPLOAD_DIR', __DIR__ . '/../uploads/documents/');
define('UPLOAD_MAX_MB', 10);
$allowed_mime_types = [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'image/jpeg',
    'image/png',
];

if (!is_dir(UPLOAD_DIR)) mkdir(UPLOAD_DIR, 0755, true);

function getInitialsFromEmail($email) {
    if (empty($email)) return 'U';
    $namePart = explode('@', $email)[0];
    $parts    = preg_split('/[._-]/', $namePart);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    if (empty($initials) && !empty($email)) $initials = strtoupper(substr($email, 0, 1));
    return $initials ?: 'U';
}

$user_email        = $_SESSION['user_email'] ?? '';
$user_initials     = getInitialsFromEmail($user_email);
$user_role         = $_SESSION['user_role']  ?? 'user';
$user_role_display = ucfirst($user_role);

// ── Handle form submission ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['_memo_submit'])) {

    $document_type = trim($_POST['documentType'] ?? '');
    $receiver_ids  = $_POST['receivers'] ?? [];
    $is_draft      = isset($_POST['save_as_draft']);

    if (empty($document_type)) {
        $error = 'Please select a document type.';
    } elseif (!$is_draft && empty($_FILES['fileUpload']['name'][0])) {
        $error = 'Please upload at least one document.';
    } elseif (!$is_draft && empty($receiver_ids)) {
        $error = 'Please select at least one receiver.';
    } else {

        // ── Save uploaded files ───────────────────────────────
        $saved_files = [];
        $file_errors = [];

        if (!empty($_FILES['fileUpload']['name'][0])) {
            $count = count($_FILES['fileUpload']['name']);
            for ($i = 0; $i < $count; $i++) {
                if ($_FILES['fileUpload']['error'][$i] !== UPLOAD_ERR_OK) continue;

                $size = $_FILES['fileUpload']['size'][$i];
                if ($size > UPLOAD_MAX_MB * 1024 * 1024) {
                    $file_errors[] = $_FILES['fileUpload']['name'][$i] . ' exceeds 10MB.';
                    continue;
                }

                $mime = mime_content_type($_FILES['fileUpload']['tmp_name'][$i]);
                if (!in_array($mime, $allowed_mime_types, true)) {
                    $file_errors[] = $_FILES['fileUpload']['name'][$i] . ' has an unsupported type.';
                    continue;
                }

                $ext         = pathinfo($_FILES['fileUpload']['name'][$i], PATHINFO_EXTENSION);
                $stored_name = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
                $dest        = UPLOAD_DIR . $stored_name;

                if (move_uploaded_file($_FILES['fileUpload']['tmp_name'][$i], $dest)) {
                    $saved_files[] = [
                        'original_name' => $_FILES['fileUpload']['name'][$i],
                        'stored_name'   => $stored_name,
                        'path'          => $dest,
                        'file_path'     => 'uploads/documents/' . $stored_name,
                        'mime'          => $mime,
                        'size'          => $size,
                    ];
                } else {
                    $file_errors[] = 'Failed to save: ' . $_FILES['fileUpload']['name'][$i];
                }
            }
        }

        if (!empty($file_errors)) {
            $error = implode('<br>', array_map('htmlspecialchars', $file_errors));
        } else {

            // ── Generate document ref ─────────────────────────
            $prefix       = strtoupper(substr($document_type, 0, 2));
            $document_ref = $prefix . '-' . date('Y') . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
            $document_id  = 0;

            // ── Save files to document_files table ───────────
            if (!empty($saved_files)) {
                $file_stmt = $pdo->prepare("
                    INSERT INTO document_files
                        (document_type, document_id, original_name, stored_name, file_path, mime_type, file_size)
                    VALUES
                        (:document_type, :document_id, :original_name, :stored_name, :file_path, :mime_type, :file_size)
                ");
                foreach ($saved_files as $f) {
                    $file_stmt->execute([
                        ':document_type' => $document_type,
                        ':document_id'   => $document_id,
                        ':original_name' => $f['original_name'],
                        ':stored_name'   => $f['stored_name'],
                        ':file_path'     => $f['file_path'],
                        ':mime_type'     => $f['mime'],
                        ':file_size'     => $f['size'],
                    ]);
                }
            }

            // ── Fetch receivers ───────────────────────────────
            $receivers_data = [];
            if (!empty($receiver_ids)) {
                $placeholders = implode(',', array_fill(0, count($receiver_ids), '?'));
                $stmt = $pdo->prepare("SELECT id, name, email FROM receivers WHERE id IN ($placeholders)");
                $stmt->execute(array_map('intval', $receiver_ids));
                $receivers_data = $stmt->fetchAll();
            }

            // ── Send emails ───────────────────────────────────
            $mail_errors    = [];
            $mail_sent      = 0;
            $document_label = ucwords(str_replace('_', ' ', $document_type));
            $sender_name    = $user_email ?: 'WMSU Records Office';
            $date_sent      = date('F d, Y \a\t h:i A');
            $base_url       = 'http://' . $_SERVER['HTTP_HOST'] . '/WMSU-Receive-System';

            foreach ($receivers_data as $receiver) {
                if (empty($receiver['email'])) continue;

                // Generate unique token
                $token = bin2hex(random_bytes(32));

                // Insert into document_recipients
                try {
                    $pdo->prepare("
                        INSERT INTO document_recipients
                            (document_type, document_id, document_ref, receiver_id, status, token)
                        VALUES
                            (:document_type, :document_id, :document_ref, :receiver_id, 'pending', :token)
                    ")->execute([
                        ':document_type' => $document_type,
                        ':document_id'   => $document_id,
                        ':document_ref'  => $document_ref,
                        ':receiver_id'   => (int) $receiver['id'],
                        ':token'         => $token,
                    ]);
                } catch (PDOException $e) {
                    $mail_errors[] = "DB error for {$receiver['name']}: " . $e->getMessage();
                    continue;
                }

                // Both URLs use the same token, different action
                $confirm_url  = $base_url . '/acknowledge.php?token=' . urlencode($token) . '&action=confirm';
                $download_url = $base_url . '/acknowledge.php?token=' . urlencode($token) . '&action=download';

                // Build file list for email body
                $files_list = '';
                foreach ($saved_files as $f) {
                    $files_list .= '<li style="margin:4px 0;color:#374151;">'
                        . htmlspecialchars($f['original_name'])
                        . ' <span style="color:#9CA3AF;font-size:12px;">('
                        . round($f['size'] / 1024, 1) . ' KB)</span></li>';
                }
                if (!$files_list) $files_list = '<li style="color:#9CA3AF;">No files attached</li>';

                try {
                    $mail = createMailer();
                    $mail->addAddress($receiver['email'], $receiver['name']);
                    $mail->Subject = '[WMSU Records] New Document: ' . $document_label;

                    // Still attach the file so receiver has it directly too
                    foreach ($saved_files as $f) {
                        $mail->addAttachment($f['path'], $f['original_name']);
                    }

                    $mail->isHTML(true);
                    $mail->Body = '
                    <!DOCTYPE html>
                    <html>
                    <head><meta charset="UTF-8"></head>
                    <body style="margin:0;padding:0;background:#f3f4f6;font-family:\'IBM Plex Sans\',Arial,sans-serif;">
                        <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
                        <tr><td align="center">
                        <table width="600" cellpadding="0" cellspacing="0"
                            style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">

                            <!-- Header -->
                            <tr>
                                <td style="background:#AA0003;padding:28px 32px;">
                                    <h1 style="margin:0;color:#ffffff;font-size:22px;font-weight:700;">
                                        WMSU Document Management
                                    </h1>
                                    <p style="margin:4px 0 0;color:#ffb3b6;font-size:13px;">
                                        Western Mindanao State University
                                    </p>
                                </td>
                            </tr>

                            <!-- Body -->
                            <tr>
                                <td style="padding:32px;">
                                    <p style="margin:0 0 6px;color:#6B7280;font-size:13px;">Hello,</p>
                                    <h2 style="margin:0 0 20px;color:#111827;font-size:18px;font-weight:600;">
                                        You have a new document assigned to you
                                    </h2>

                                    <!-- Document Details -->
                                    <table width="100%" cellpadding="0" cellspacing="0"
                                        style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:24px;">
                                        <tr><td style="padding:20px;">
                                            <table width="100%" cellpadding="6" cellspacing="0">
                                                <tr>
                                                    <td style="color:#6B7280;font-size:13px;width:140px;">Document Type</td>
                                                    <td style="color:#111827;font-size:13px;font-weight:600;">' . htmlspecialchars($document_label) . '</td>
                                                </tr>
                                                <tr>
                                                    <td style="color:#6B7280;font-size:13px;">Reference #</td>
                                                    <td style="color:#111827;font-size:13px;font-weight:600;">' . htmlspecialchars($document_ref) . '</td>
                                                </tr>
                                                <tr>
                                                    <td style="color:#6B7280;font-size:13px;">Receiver</td>
                                                    <td style="color:#111827;font-size:13px;font-weight:600;">' . htmlspecialchars($receiver['name']) . '</td>
                                                </tr>
                                                <tr>
                                                    <td style="color:#6B7280;font-size:13px;">Sent By</td>
                                                    <td style="color:#111827;font-size:13px;font-weight:600;">' . htmlspecialchars($sender_name) . '</td>
                                                </tr>
                                                <tr>
                                                    <td style="color:#6B7280;font-size:13px;">Date</td>
                                                    <td style="color:#111827;font-size:13px;font-weight:600;">' . $date_sent . '</td>
                                                </tr>
                                            </table>
                                        </td></tr>
                                    </table>

                                    <!-- Attached Files -->
                                    <p style="margin:0 0 8px;color:#374151;font-size:13px;font-weight:600;">
                                        Attached Files
                                    </p>
                                    <ul style="margin:0 0 28px;padding-left:20px;font-size:13px;">' . $files_list . '</ul>

                                    <!-- Action Buttons -->
                                    <table width="100%" cellpadding="0" cellspacing="0"
                                        style="background:#FEF2F2;border:1px solid #FECACA;border-radius:8px;margin-bottom:24px;">
                                        <tr><td style="padding:24px;text-align:center;">
                                            <p style="margin:0 0 18px;color:#374151;font-size:13px;font-weight:500;">
                                                Please confirm receipt using one of the options below:
                                            </p>

                                            <!-- Row of two buttons -->
                                            <table cellpadding="0" cellspacing="0" style="margin:0 auto;">
                                                <tr>
                                                    <!-- Mark as Received -->
                                                    <td style="padding-right:10px;">
                                                        <a href="' . $confirm_url . '"
                                                            style="display:inline-block;background:#AA0003;color:#ffffff;
                                                                font-size:13px;font-weight:700;padding:12px 24px;
                                                                border-radius:8px;text-decoration:none;">
                                                            &#10003;&nbsp; Mark as Received
                                                        </a>
                                                    </td>
                                                    <!-- Download (also marks as received) -->
                                                    <td style="padding-left:10px;">
                                                        <a href="' . $download_url . '"
                                                            style="display:inline-block;background:#1D4ED8;color:#ffffff;
                                                                font-size:13px;font-weight:700;padding:12px 24px;
                                                                border-radius:8px;text-decoration:none;">
                                                            &#8595;&nbsp; Download Document
                                                        </a>
                                                    </td>
                                                </tr>
                                            </table>

                                            <p style="margin:16px 0 0;color:#9CA3AF;font-size:11px;">
                                                Both buttons will confirm your receipt. Links are unique to you.
                                            </p>
                                        </td></tr>
                                    </table>

                                    <p style="margin:0;color:#9CA3AF;font-size:12px;">
                                        The document is also attached to this email for your convenience.
                                        Please do not reply to this email.
                                    </p>
                                </td>
                            </tr>

                            <!-- Footer -->
                            <tr>
                                <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px 32px;text-align:center;">
                                    <p style="margin:0;color:#9CA3AF;font-size:11px;">
                                        Automated message from WMSU Document Management System.<br>
                                        Western Mindanao State University — Records Office
                                    </p>
                                </td>
                            </tr>

                        </table>
                        </td></tr>
                        </table>
                    </body>
                    </html>';

                    $mail->AltBody = "Hello {$receiver['name']},\n\n"
                        . "You have a new document: {$document_label} ({$document_ref})\n"
                        . "Sent by: {$sender_name}\n"
                        . "Date: {$date_sent}\n\n"
                        . "Mark as Received:\n" . $confirm_url . "\n\n"
                        . "Download Document (also marks as received):\n" . $download_url . "\n\n"
                        . "— WMSU Document Management System";

                    $mail->send();
                    $mail_sent++;

                } catch (\Exception $e) {
                    $mail_errors[] = "Could not send to {$receiver['name']}: " . $e->getMessage();
                }
            }

            // ── Final status message ──────────────────────────
            if ($is_draft) {
                $success = 'Document saved as draft.';
            } elseif (empty($mail_errors)) {
                $success = "Document submitted! Email"
                    . ($mail_sent > 1 ? 's' : '')
                    . " sent to {$mail_sent} receiver"
                    . ($mail_sent > 1 ? 's' : '') . ". Reference: {$document_ref}";
            } else {
                $success = "Document submitted. {$mail_sent} email(s) sent.";
                $error   = implode('<br>', $mail_errors);
            }

            $_POST = [];
        }
    }
}

// ── Load receivers from DB ────────────────────────────────────
$all_receivers = $pdo->query("
    SELECT id, name, department AS dept, role, email
    FROM receivers ORDER BY name ASC
")->fetchAll();

$avatar_colors = [
    'bg-indigo-500','bg-violet-500','bg-sky-500',
    'bg-emerald-500','bg-amber-500','bg-rose-500',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Receiving — WMSU Document Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        crimson: {
                            950:'#4D0001',900:'#800002',800:'#AA0003',
                            700:'#D91619',600:'#FF3336',500:'#FF4D50',
                            400:'#FF666A',300:'#FF8083',200:'#FF999D',
                            100:'#FFB3B6',50:'#FFCCCE',
                        }
                    },
                    fontFamily: {
                        'main':      ['"Noto Nastaliq Urdu"','serif'],
                        'secondary': ['"IBM Plex Sans"','sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family:'IBM Plex Sans',sans-serif; }
        h1,h2,h3,h4,h5,h6 { font-family:'Noto Nastaliq Urdu',serif; }
        .receiver-row:has(input[type="checkbox"]:checked) {
            border-color:#D91619; background-color:#FFCCCE;
        }
    </style>
</head>
<body class="bg-gray-100">

    <?php $active_page = 'main'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">

        <!-- Top Bar -->
        <header class="bg-white shadow-sm sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="ml-12 lg:ml-0">
                        <h2 class="text-2xl font-bold text-gray-800 font-main">Receiving Department</h2>
                        <p class="text-sm text-gray-600 mt-1 font-secondary">Process and verify incoming documents</p>
                    </div>
                    <div class="flex items-center space-x-4">
                        <button class="relative p-2 text-gray-600 hover:text-crimson-700 transition duration-200">
                            <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 17h5l-1.405-1.405A2.032 2.032 0 0118 14.158V11a6.002 6.002 0 00-4-5.659V5a2 2 0 10-4 0v.341C7.67 6.165 6 8.388 6 11v3.159c0 .538-.214 1.055-.595 1.436L4 17h5m6 0v1a3 3 0 11-6 0v-1m6 0H9"/>
                            </svg>
                            <span class="absolute top-1 right-1 w-2 h-2 bg-crimson-600 rounded-full"></span>
                        </button>
                        <div class="flex items-center space-x-3">
                            <div class="hidden sm:block text-right">
                                <p class="text-sm font-semibold text-gray-800 font-secondary"><?= htmlspecialchars($user_email ?: 'Guest User') ?></p>
                                <p class="text-xs text-gray-600 font-secondary"><?= htmlspecialchars($user_role_display) ?></p>
                            </div>
                            <div class="w-10 h-10 bg-crimson-700 rounded-full flex items-center justify-center">
                                <span class="text-white font-semibold font-secondary"><?= htmlspecialchars($user_initials) ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1">
                <div>
                    <div class="bg-white rounded-2xl shadow p-6">
                        <div class="flex items-center justify-between mb-6">
                            <h3 class="text-xl font-bold text-gray-800 font-main">Receive New Document</h3>
                            <span class="px-3 py-1 bg-crimson-100 text-crimson-700 rounded-lg text-sm font-semibold font-secondary">New Entry</span>
                        </div>

                        <?php if ($success): ?>
                        <div class="mb-4 p-3 bg-green-50 border border-green-200 text-green-700 rounded-lg text-sm font-secondary flex justify-between items-center">
                            <?= htmlspecialchars($success) ?>
                            <button onclick="this.parentElement.remove()" class="font-bold text-green-500 hover:text-green-700 ml-4">&times;</button>
                        </div>
                        <?php endif; ?>

                        <?php if ($error): ?>
                        <div class="mb-4 p-3 bg-red-50 border border-red-200 text-red-700 rounded-lg text-sm font-secondary flex justify-between items-center">
                            <?= $error ?>
                            <button onclick="this.parentElement.remove()" class="font-bold text-red-500 hover:text-red-700 ml-4">&times;</button>
                        </div>
                        <?php endif; ?>

                        <form id="receiveForm" method="POST" action="" enctype="multipart/form-data" class="space-y-6">

                            <!-- Document Type -->
                            <div>
                                <label for="documentType" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                                    Document Type <span class="text-crimson-600">*</span>
                                </label>
                                <select id="documentType" name="documentType" required
                                    onchange="handleDocumentTypeChange(this.value)"
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary">
                                    <option value="">Select document type...</option>
                                    <option value="memorandum"    <?= ($_POST['documentType']??'') === 'memorandum'    ? 'selected':'' ?>>Memorandum Order</option>
                                    <option value="special_order" <?= ($_POST['documentType']??'') === 'special_order' ? 'selected':'' ?>>Special Order</option>
                                    <option value="travel_order"  <?= ($_POST['documentType']??'') === 'travel_order'  ? 'selected':'' ?>>Travel Order</option>
                                    <option value="other"         <?= ($_POST['documentType']??'') === 'other'         ? 'selected':'' ?>>Other</option>
                                </select>
                            </div>

                            <!-- File Upload -->
                            <div>
                                <label for="fileUpload" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                                    Upload Document <span class="text-crimson-600">*</span>
                                </label>
                                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-crimson-700 transition duration-200" id="dropZone">
                                    <input type="file" id="fileUpload" name="fileUpload[]" multiple accept=".pdf,.doc,.docx,.jpg,.jpeg,.png" class="hidden">
                                    <label for="fileUpload" class="cursor-pointer">
                                        <svg class="w-12 h-12 mx-auto text-gray-400 mb-3" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
                                        </svg>
                                        <p class="text-sm text-gray-600 mb-1 font-secondary">
                                            <span class="text-crimson-700 font-semibold">Click to upload</span> or drag and drop
                                        </p>
                                        <p class="text-xs text-gray-500 font-secondary">PDF, DOC, DOCX, JPG, PNG (Max 10MB)</p>
                                    </label>
                                </div>
                                <div id="fileList" class="mt-3 space-y-2"></div>
                            </div>

                            <!-- Receiver Selection -->
                            <div class="border border-gray-200 rounded-lg p-4">
                                <p class="text-sm font-semibold text-gray-500 mb-4 font-secondary">
                                    Receiver Selection
                                    <span class="ml-2 text-xs font-normal text-gray-400"><?= count($all_receivers) ?> receiver(s) available</span>
                                </p>

                                <div class="flex flex-col sm:flex-row gap-3 mb-4">
                                    <div class="flex-1 flex items-center gap-2">
                                        <div class="relative flex-1">
                                            <label for="receiverSearch" class="block text-xs font-semibold text-gray-600 mb-1 font-secondary">Search Name</label>
                                            <input type="text" id="receiverSearch" placeholder="Name / Id number"
                                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary text-sm">
                                        </div>
                                        <button type="button" class="mt-5 p-3 text-gray-400 hover:text-crimson-700 transition duration-200">
                                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M7 12h10M10 18h4"/>
                                            </svg>
                                        </button>
                                    </div>
                                    <div class="sm:w-44">
                                        <label for="filterRole" class="block text-xs font-semibold text-gray-600 mb-1 font-secondary">Filter by Role</label>
                                        <select id="filterRole" class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary text-sm text-gray-500">
                                            <option value="">Choose a role</option>
                                            <option value="FACULTY">Faculty</option>
                                            <option value="STAFF">Staff</option>
                                            <option value="STUDENT">Student</option>
                                            <option value="ADMIN">Admin</option>
                                        </select>
                                    </div>
                                    <div class="sm:w-52">
                                        <label for="filterDepartment" class="block text-xs font-semibold text-gray-600 mb-1 font-secondary">Filter by Department</label>
                                        <select id="filterDepartment" class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary text-sm text-gray-500">
                                            <option value="">Choose department</option>
                                            <option value="CA">College of Agriculture</option>
                                            <option value="CA">College of Architecture</option>
                                            <option value="CAIS">College of Asian &amp; Islamic Studies</option>
                                            <option value="CCS">College of Computing Studies</option>
                                            <option value="CCJE">College of Criminal Justice Education</option>
                                            <option value="COE">College of Engineering</option>
                                            <option value="CFES">College of Forestry &amp; Environmental Studies</option>
                                            <option value="CHE">College of Home Economics</option>
                                            <option value="COL">College of Law</option>
                                            <option value="CLA">College of Liberal Arts</option>
                                            <option value="CM">College of Medicine</option>
                                            <option value="CN">College of Nursing</option>
                                            <option value="CPADS">College of Public Administration &amp; Development Studies</option>
                                            <option value="CSM">College of Science and Mathematics</option>
                                            <option value="CSWCD">College of Social Work &amp; Community Development</option>
                                            <option value="CSSPE">College of Sports Science &amp; Physical Education</option>
                                            <option value="CTE">College of Teacher Education</option>
                                            <option value="PSMP">Professional Science Master's Program</option>
                                        </select>
                                    </div>
                                </div>

                                <div id="receiverList" class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                    <?php if (empty($all_receivers)): ?>
                                    <p class="text-sm text-gray-400 text-center py-6 font-secondary">No receivers found. Add users from the Dashboard first.</p>
                                    <?php endif; ?>

                                    <?php foreach ($all_receivers as $i => $r):
                                        $color    = $avatar_colors[$i % count($avatar_colors)];
                                        $words    = explode(' ', $r['name']);
                                        $initials = implode('', array_map(fn($w) => strtoupper($w[0] ?? ''), $words));
                                    ?>
                                    <label
                                        class="receiver-row flex items-center justify-between bg-white border-2 border-gray-200 rounded-lg px-4 py-3 cursor-pointer hover:border-crimson-400 hover:bg-crimson-50 transition duration-200"
                                        data-name="<?= htmlspecialchars($r['name']) ?>"
                                        data-dept="<?= htmlspecialchars($r['dept']) ?>"
                                        data-role="<?= htmlspecialchars($r['role']) ?>"
                                    >
                                        <div class="flex items-center gap-3">
                                            <input type="checkbox" name="receivers[]" value="<?= (int)$r['id'] ?>"
                                                class="w-4 h-4 text-crimson-700 border-gray-300 rounded focus:ring-crimson-500">
                                            <div class="w-9 h-9 <?= $color ?> rounded-full flex items-center justify-center shrink-0">
                                                <span class="text-white text-sm font-bold font-secondary"><?= htmlspecialchars(substr($initials,0,1)) ?></span>
                                            </div>
                                            <div>
                                                <span class="text-sm font-bold text-gray-800 font-secondary tracking-wide"><?= htmlspecialchars($r['name']) ?></span>
                                                <?php if (!empty($r['email'])): ?>
                                                <p class="text-xs text-gray-400 font-secondary"><?= htmlspecialchars($r['email']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-3 text-sm font-semibold text-gray-500 font-secondary">
                                            <span><?= htmlspecialchars($r['dept']) ?></span>
                                            <span><?= htmlspecialchars($r['role']) ?></span>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>

                                <p id="noResults" class="hidden text-sm text-gray-400 text-center py-4 font-secondary">No receivers found matching your search.</p>

                                <div id="selectedCount" class="mt-3 hidden">
                                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-crimson-100 text-crimson-700 rounded-full text-xs font-semibold font-secondary">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 00-1.414 0L8 12.586 4.707 9.293a1 1 0 00-1.414 1.414l4 4a1 1 0 001.414 0l8-8a1 1 0 000-1.414z" clip-rule="evenodd"/>
                                        </svg>
                                        <span id="selectedCountText">0 receiver(s) selected</span>
                                    </span>
                                </div>
                            </div>

                            <!-- Buttons -->
                            <div class="flex flex-col sm:flex-row gap-3">
                                <button type="submit" name="save_as_draft"
                                    class="flex-1 bg-gray-200 text-gray-700 font-bold py-3 px-6 rounded-lg hover:bg-gray-300 focus:outline-none focus:ring-4 focus:ring-gray-300 transition duration-200 font-secondary">
                                    Save as Draft
                                </button>
                                <button type="submit"
                                    class="flex-1 bg-crimson-700 text-white font-bold py-3 px-6 rounded-lg hover:bg-crimson-800 focus:outline-none focus:ring-4 focus:ring-crimson-300 transition duration-200 transform hover:scale-[1.02] active:scale-[0.98] font-secondary">
                                    Submit Document
                                </button>
                            </div>

                        </form>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <?php include __DIR__ . '/../document_type/memo.php'; ?>
    <?php include __DIR__ . '/../document_type/special_order.php'; ?>
    <?php include __DIR__ . '/../document_type/travel_order.php'; ?>

    <script>
        function handleDocumentTypeChange(value) {
            if      (value === 'memorandum')    openMemoForm();
            else if (value === 'special_order') openSpecialOrderForm();
            else if (value === 'travel_order')  openTravelOrderForm();
        }

        const fileUpload  = document.getElementById('fileUpload');
        const fileList    = document.getElementById('fileList');
        const dropZone    = document.getElementById('dropZone');
        let uploadedFiles = [];

        fileUpload.addEventListener('change', (e) => { uploadedFiles = [...uploadedFiles, ...Array.from(e.target.files)]; displayFiles(); });
        dropZone.addEventListener('dragover',  (e) => { e.preventDefault(); dropZone.classList.add('border-crimson-700','bg-crimson-50'); });
        dropZone.addEventListener('dragleave', ()  => { dropZone.classList.remove('border-crimson-700','bg-crimson-50'); });
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-crimson-700','bg-crimson-50');
            uploadedFiles = [...uploadedFiles, ...Array.from(e.dataTransfer.files)];
            displayFiles();
        });

        function displayFiles() {
            fileList.innerHTML = '';
            uploadedFiles.forEach((file, index) => {
                const item = document.createElement('div');
                item.className = 'flex items-center justify-between bg-gray-50 p-3 rounded-lg';
                item.innerHTML = `
                    <div class="flex items-center space-x-3">
                        <svg class="w-5 h-5 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-gray-800">${file.name}</p>
                            <p class="text-xs text-gray-500">${(file.size/1024).toFixed(2)} KB</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeFile(${index})" class="text-red-600 hover:text-red-800 transition duration-200">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>`;
                fileList.appendChild(item);
            });
        }

        function removeFile(index) { uploadedFiles.splice(index,1); displayFiles(); }

        const receiverSearch    = document.getElementById('receiverSearch');
        const filterRole        = document.getElementById('filterRole');
        const filterDepartment  = document.getElementById('filterDepartment');
        const receiverList      = document.getElementById('receiverList');
        const noResults         = document.getElementById('noResults');
        const selectedCount     = document.getElementById('selectedCount');
        const selectedCountText = document.getElementById('selectedCountText');

        function filterReceivers() {
            const query = receiverSearch.value.toLowerCase().trim();
            const role  = filterRole.value.toLowerCase();
            const dept  = filterDepartment.value.toLowerCase();
            const rows  = receiverList.querySelectorAll('label.receiver-row');
            let visible = 0;
            rows.forEach(row => {
                const matchName = !query || (row.dataset.name||'').toLowerCase().includes(query);
                const matchRole = !role  || (row.dataset.role||'').toLowerCase() === role;
                const matchDept = !dept  || (row.dataset.dept||'').toLowerCase() === dept;
                if (matchName && matchRole && matchDept) { row.classList.remove('hidden'); visible++; }
                else row.classList.add('hidden');
            });
            noResults.classList.toggle('hidden', visible > 0);
        }

        function updateSelectedCount() {
            const checked = receiverList.querySelectorAll('input[type="checkbox"]:checked').length;
            selectedCount.classList.toggle('hidden', checked === 0);
            selectedCountText.textContent = checked + ' receiver' + (checked > 1 ? 's' : '') + ' selected';
        }

        receiverSearch.addEventListener('input',    filterReceivers);
        filterRole.addEventListener('change',       filterReceivers);
        filterDepartment.addEventListener('change', filterReceivers);
        receiverList.addEventListener('change',     updateSelectedCount);
    </script>

    <!-- Shared Autocomplete -->
    <style>
        .ac-wrapper { position:relative; }
        .ac-dropdown {
            position:absolute; top:calc(100% + 4px); left:0; right:0;
            background:#fff; border:1.5px solid #800002; border-radius:6px;
            box-shadow:0 8px 24px rgba(0,0,0,.12); z-index:9999;
            max-height:220px; overflow-y:auto; display:none;
        }
        .ac-dropdown.is-open { display:block; }
        .ac-item { padding:.6rem .9rem; cursor:pointer; border-bottom:1px solid #E2E0DC; transition:background .15s; }
        .ac-item:last-child { border-bottom:none; }
        .ac-item:hover, .ac-item.is-active { background:#FDF2F4; }
        .ac-item-name { font-size:.83rem; font-weight:600; color:#1A1A1A; }
        .ac-item-meta { font-size:.72rem; color:#6B6B6B; margin-top:2px; }
        .ac-empty { padding:.75rem .9rem; font-size:.82rem; color:#6B6B6B; text-align:center; }
    </style>
    <script>
        let acDebounceTimer = null;
        function acSearch(inputEl, dropdownId, deptFieldId, officeFieldId) {
            clearTimeout(acDebounceTimer);
            const q    = inputEl.value.trim();
            const drop = document.getElementById(dropdownId);
            if (q.length === 0) { acClose(drop); return; }
            acDebounceTimer = setTimeout(() => {
                fetch(`/WMSU-Receive-System/api/autocomplete.php?q=${encodeURIComponent(q)}`)
                    .then(r => r.json())
                    .then(results => acRender(results, inputEl, drop, deptFieldId, officeFieldId))
                    .catch(() => acClose(drop));
            }, 200);
        }
        function acRender(results, inputEl, drop, deptFieldId, officeFieldId) {
            drop.innerHTML = '';
            if (results.length === 0) {
                drop.innerHTML = '<div class="ac-empty">No users found.</div>';
                drop.classList.add('is-open'); return;
            }
            results.forEach(r => {
                const item = document.createElement('div');
                item.className = 'ac-item';
                item.innerHTML = `<div class="ac-item-name">${escHtml(r.name)}</div><div class="ac-item-meta">${escHtml(r.department)} &middot; ${escHtml(r.role)}</div>`;
                item.addEventListener('mousedown', (e) => {
                    e.preventDefault();
                    inputEl.value = r.name;
                    if (deptFieldId)   { const el = document.getElementById(deptFieldId);   if (el) el.value = r.department||''; }
                    if (officeFieldId) { const el = document.getElementById(officeFieldId); if (el) el.value = r.department||''; }
                    acClose(drop);
                });
                drop.appendChild(item);
            });
            drop.classList.add('is-open');
        }
        function acClose(drop)  { if (drop) drop.classList.remove('is-open'); }
        function acCloseAll()   { document.querySelectorAll('.ac-dropdown').forEach(d => d.classList.remove('is-open')); }
        function escHtml(str)   { return String(str||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
        document.addEventListener('click', (e) => { if (!e.target.closest('.ac-wrapper')) acCloseAll(); });
    </script>

</body>
</html>
