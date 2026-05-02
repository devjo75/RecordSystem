<?php
session_start();

require_once '../auth-guard/Auth.php';
require_once '../config/db.php';
require_once '../config/mailer.php';

$pdo = getPDO();
$success = '';
$error = '';

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
    $parts = preg_split('/[._-]/', $namePart);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    if (empty($initials) && !empty($email)) $initials = strtoupper(substr($email, 0, 1));
    return $initials ?: 'U';
}

$user_email = $_SESSION['user_email'] ?? '';
$user_initials = getInitialsFromEmail($user_email);
$user_role = $_SESSION['user_role'] ?? 'user';
$user_role_display = ucfirst($user_role);
$user_id = $_SESSION['user_id'] ?? 1;

// Helper function to generate document number
function generateDocumentNumber($type, $pdo) {
    $year = date('Y');
    $prefix = '';
    $table = '';
    $number_field = '';
    
    switch($type) {
        case 'memorandum':
            $prefix = 'MO';
            $table = 'memorandum_orders';
            $number_field = 'mo_number';
            break;
        case 'special_order':
            $prefix = 'SO';
            $table = 'special_orders';
            $number_field = 'so_number';
            break;
        case 'travel_order':
            $prefix = 'TO';
            $table = 'travel_orders';
            $number_field = 'io_number';
            break;
        default:
            return $prefix . '-' . $year . '-001';
    }
    
    // Get the highest number for this year
    $stmt = $pdo->prepare("SELECT MAX(CAST(SUBSTRING_INDEX($number_field, '-', -1) AS UNSIGNED)) as max_num 
                           FROM $table 
                           WHERE $number_field LIKE ?");
    $stmt->execute([$prefix . '-' . $year . '-%']);
    $result = $stmt->fetch();
    $next_num = ($result['max_num'] ?? 0) + 1;
    
    return $prefix . '-' . $year . '-' . str_pad($next_num, 3, '0', STR_PAD_LEFT);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    $document_type = trim($_POST['documentType'] ?? '');
    $receiver_ids = $_POST['receivers'] ?? [];
    $is_draft = isset($_POST['save_as_draft']);
    $status = $is_draft ? 'Draft' : 'Released';
    
    // Validate based on document type
    $validation_errors = [];
    
    if (empty($document_type)) {
        $validation_errors[] = 'Please select a document type.';
    }
    
    if (!$is_draft && empty($_FILES['fileUpload']['name'][0])) {
        $validation_errors[] = 'Please upload at least one document.';
    }
    
    if (!$is_draft && empty($receiver_ids)) {
        $validation_errors[] = 'Please select at least one receiver.';
    }
    
    // Validate document type specific fields
    if ($document_type === 'memorandum') {
        if (empty($_POST['mo_number'])) $validation_errors[] = 'M.O. Number is required.';
        if (empty($_POST['concerned_faculty'])) $validation_errors[] = 'Concerned Faculty is required.';
        if (empty($_POST['subject'])) $validation_errors[] = 'Subject is required.';
    } elseif ($document_type === 'special_order') {
        if (empty($_POST['so_number'])) $validation_errors[] = 'S.O. Number is required.';
        if (empty($_POST['concerned_faculty'])) $validation_errors[] = 'Concerned Faculty is required.';
        if (empty($_POST['subject'])) $validation_errors[] = 'Subject is required.';
    } elseif ($document_type === 'travel_order') {
        if (empty($_POST['io_number'])) $validation_errors[] = 'T.O. Number is required.';
        if (empty($_POST['employee_name'])) $validation_errors[] = 'Employee Name is required.';
        if (empty($_POST['subject'])) $validation_errors[] = 'Subject is required.';
    }
    
    if (!empty($validation_errors)) {
        $error = implode('<br>', $validation_errors);
    } else {
        
        // Process file uploads
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
                
                $ext = pathinfo($_FILES['fileUpload']['name'][$i], PATHINFO_EXTENSION);
                $stored_name = bin2hex(random_bytes(16)) . '.' . strtolower($ext);
                $dest = UPLOAD_DIR . $stored_name;
                
                if (move_uploaded_file($_FILES['fileUpload']['tmp_name'][$i], $dest)) {
                    // Only attach ocr_text for the first image file (index 0)
                    $is_image = in_array($mime, ['image/jpeg', 'image/png', 'image/gif', 'image/webp']);
                    $saved_files[] = [
                        'original_name' => $_FILES['fileUpload']['name'][$i],
                        'stored_name' => $stored_name,
                        'path' => $dest,
                        'file_path' => 'uploads/documents/' . $stored_name,
                        'mime' => $mime,
                        'size' => $size,
                        'ocr_text' => ($is_image && $i === 0) ? (trim($_POST['ocr_text'] ?? '') ?: null) : null,
                    ];
                } else {
                    $file_errors[] = 'Failed to save: ' . $_FILES['fileUpload']['name'][$i];
                }
            }
        }
        
        if (!empty($file_errors)) {
            $error = implode('<br>', array_map('htmlspecialchars', $file_errors));
        } else {
            
            $document_id = null;
            $document_number = '';
            
            try {
                $pdo->beginTransaction();
                
                // Insert into appropriate document table
                if ($document_type === 'memorandum') {
                    $document_number = !empty($_POST['mo_number']) ? $_POST['mo_number'] : generateDocumentNumber('memorandum', $pdo);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO memorandum_orders (
                            mo_number, document_year, document_month, concerned_faculty, 
                            college_dept, subject, date_issued, destination_duration,
                            effectivity_start, effectivity_end, rf, source, 
                            no_partly, remarks, document_file, sender_email, status, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $document_number,
                        date('Y'),
                        date('F'),
                        $_POST['concerned_faculty'] ?? '',
                        $_POST['college_dept'] ?? '',
                        $_POST['subject'] ?? '',
                        $_POST['date_issued'] ?? date('Y-m-d'),
                        $_POST['destination_duration'] ?? null,
                        $_POST['effectivity_start'] ?? null,
                        $_POST['effectivity_end'] ?? null,
                        $_POST['rf'] ?? null,
                        $_POST['source'] ?? null,
                        $_POST['no_partly'] ?? null,
                        $_POST['remarks'] ?? null,
                        !empty($saved_files) ? $saved_files[0]['file_path'] : null,
                        $user_email,
                        $status,
                        $user_id
                    ]);
                    $document_id = $pdo->lastInsertId();
                    
                } elseif ($document_type === 'special_order') {
                    $document_number = !empty($_POST['so_number']) ? $_POST['so_number'] : generateDocumentNumber('special_order', $pdo);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO special_orders (
                            so_number, document_year, document_month, concerned_faculty,
                            subject, date_issued, effectivity, effectivity_date,
                            source_signatory, remarks, document_file, sender_email, status, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $document_number,
                        date('Y'),
                        date('F'),
                        $_POST['concerned_faculty'] ?? '',
                        $_POST['subject'] ?? '',
                        $_POST['date_issued'] ?? date('Y-m-d'),
                        $_POST['effectivity'] ?? null,
                        $_POST['effectivity_date'] ?? null,
                        $_POST['source_signatory'] ?? null,
                        $_POST['remarks'] ?? null,
                        !empty($saved_files) ? $saved_files[0]['file_path'] : null,
                        $user_email,
                        $status,
                        $user_id
                    ]);
                    $document_id = $pdo->lastInsertId();
                    
                } elseif ($document_type === 'travel_order') {
                    $document_number = !empty($_POST['io_number']) ? $_POST['io_number'] : generateDocumentNumber('travel_order', $pdo);
                    
                    $stmt = $pdo->prepare("
                        INSERT INTO travel_orders (
                            io_number, document_year, document_month, employee_name,
                            office, subject, date_issued, duration_and_destination,
                            travel_start_date, travel_end_date, destination, fund_assistance,
                            source, no_partly, remarks, document_file, sender_email, status, created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $document_number,
                        date('Y'),
                        date('F'),
                        $_POST['employee_name'] ?? '',
                        $_POST['office'] ?? '',
                        $_POST['subject'] ?? '',
                        $_POST['date_issued'] ?? date('Y-m-d'),
                        $_POST['duration_and_destination'] ?? null,
                        $_POST['travel_start_date'] ?? null,
                        $_POST['travel_end_date'] ?? null,
                        $_POST['destination'] ?? null,
                        $_POST['fund_assistance'] ?? 0,
                        $_POST['source'] ?? null,
                        $_POST['no_partly'] ?? null,
                        $_POST['remarks'] ?? null,
                        !empty($saved_files) ? $saved_files[0]['file_path'] : null,
                        $user_email,
                        $status,
                        $user_id
                    ]);
                    $document_id = $pdo->lastInsertId();
                }
                
                // Save files to document_files table
                // NOTE: document_files.document_type must use the lowercase_underscored form
                // that inbox.php's JOIN produces via LOWER(REPLACE('Memorandum Order',' ','_'))
                // i.e. 'memorandum_order', 'special_order', 'travel_order'
                $document_type_file_map = [
                    'memorandum'    => 'memorandum_order',
                    'special_order' => 'special_order',
                    'travel_order'  => 'travel_order',
                ];
                $document_type_for_files = $document_type_file_map[$document_type] ?? $document_type;
                if (!empty($saved_files)) {
                    $file_stmt = $pdo->prepare("
                        INSERT INTO document_files 
                            (document_type, document_id, original_name, stored_name, file_path, mime_type, file_size, ocr_text)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    foreach ($saved_files as $f) {
                        $file_stmt->execute([
                            $document_type_for_files,
                            $document_id,
                            $f['original_name'],
                            $f['stored_name'],
                            $f['file_path'],
                            $f['mime'],
                            $f['size'],
                            $f['ocr_text'] ?? null
                        ]);
                    }
                }
                
                // Map internal type key to the proper ENUM label used in the DB
                $document_label_map = [
                    'memorandum'    => 'Memorandum Order',
                    'special_order' => 'Special Order',
                    'travel_order'  => 'Travel Order',
                ];
                $document_label = $document_label_map[$document_type] ?? ucwords(str_replace('_', ' ', $document_type));

                // Add to document history
                $history_stmt = $pdo->prepare("
                    INSERT INTO document_history (document_type, document_id, document_number, action, action_by, action_details)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $action = $is_draft ? 'Created' : 'Released';
                $history_stmt->execute([
                    $document_label,
                    $document_id,
                    $document_number,
                    $action,
                    $user_id,
                    $is_draft ? 'Saved as draft' : 'Document released to recipients'
                ]);
                
                // Send emails if not draft
                $mail_errors = [];
                $mail_sent = 0;
                
                if (!$is_draft && !empty($receiver_ids)) {
                    // Fetch receivers from users table
                    $placeholders = implode(',', array_fill(0, count($receiver_ids), '?'));
                    $stmt = $pdo->prepare("SELECT id, full_name, email FROM users WHERE id IN ($placeholders) AND is_active = 1");
                    $stmt->execute(array_map('intval', $receiver_ids));
                    $receivers_data = $stmt->fetchAll();
                    
                    $sender_name = $_SESSION['full_name'] ?? $user_email;
                    $_app_root2 = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
                    $_doc_root2 = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
                    $_rel2 = str_replace($_doc_root2, '', $_app_root2);
                    $base_url = 'http://' . $_SERVER['HTTP_HOST'] . rtrim($_rel2, '/');
                    
                    foreach ($receivers_data as $receiver) {
                        if (empty($receiver['email'])) continue;
                        
                        // Generate unique token
                        $token = bin2hex(random_bytes(32));
                        
                        // Insert into document_recipients
// Insert into document_recipients
$recipient_stmt = $pdo->prepare("
    INSERT INTO document_recipients 
        (document_type, document_id, recipient_id, recipient_email, recipient_name, status, confirmation_token)
    VALUES (?, ?, ?, ?, ?, 'Pending', ?)
");
$recipient_stmt->execute([
    ucwords(str_replace('_', ' ', $document_type)),
    $document_id,
    $receiver['id'],
    $receiver['email'],
    $receiver['full_name'],
    $token
]);
                        
                        $confirm_url = $base_url . '/acknowledge.php?token=' . urlencode($token) . '&action=confirm';
                        $download_url = $base_url . '/acknowledge.php?token=' . urlencode($token) . '&action=download';
                        
                        // Build file list
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
                            $mail->addAddress($receiver['email'], $receiver['full_name']);
                            $mail->Subject = '[WMSU Records] New Document: ' . $document_label . ' - ' . $document_number;
                            
                            foreach ($saved_files as $f) {
                                if (file_exists($f['path'])) {
                                    $mail->addAttachment($f['path'], $f['original_name']);
                                }
                            }
                            
                            $mail->isHTML(true);
                            $mail->Body = '
                            <!DOCTYPE html>
                            <html>
                            <head><meta charset="UTF-8"></head>
                            <body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,sans-serif;">
                                <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
                                    <tr><td align="center">
                                    <table width="600" cellpadding="0" cellspacing="0"
                                        style="background:#ffffff;border-radius:12px;overflow:hidden;box-shadow:0 2px 8px rgba(0,0,0,.08);">
                                        <tr>
                                            <td style="background:#AA0003;padding:28px 32px;">
                                                <h1 style="margin:0;color:#ffffff;font-size:22px;">WMSU Document Management</h1>
                                                <p style="margin:4px 0 0;color:#ffb3b6;">Western Mindanao State University</p>
                                            </td>
                                        </tr>
                                        <tr>
                                            <td style="padding:32px;">
                                                <h2 style="margin:0 0 20px;color:#111827;">You have a new document</h2>
                                                
                                                <table width="100%" style="background:#f9fafb;border:1px solid #e5e7eb;border-radius:8px;margin-bottom:24px;">
                                                    <tr><td style="padding:20px;">
                                                        <p><strong>Document Type:</strong> ' . htmlspecialchars($document_label) . '</p>
                                                        <p><strong>Reference #:</strong> ' . htmlspecialchars($document_number) . '</p>
                                                        <p><strong>Recipient:</strong> ' . htmlspecialchars($receiver['full_name']) . '</p>
                                                        <p><strong>Sent By:</strong> ' . htmlspecialchars($sender_name) . '</p>
                                                        <p><strong>Date:</strong> ' . date('F d, Y \a\t h:i A') . '</p>
                                                    </td></tr>
                                                </table>
                                                
                                                <p><strong>Attached Files:</strong></p>
                                                <ul>' . $files_list . '</ul>
                                                
                                                <table width="100%" style="margin-top:24px;">
                                                    <tr>
                                                        <td align="center">
                                                            <a href="' . $confirm_url . '"
                                                                style="display:inline-block;background:#AA0003;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;margin:0 8px;">
                                                                ✓ Mark as Received
                                                            </a>
                                                            <a href="' . $download_url . '"
                                                                style="display:inline-block;background:#1D4ED8;color:#fff;padding:12px 24px;border-radius:8px;text-decoration:none;margin:0 8px;">
                                                                ↓ Download Document
                                                            </a>
                                                        </td>
                                                    </tr>
                                                </table>
                                             </td>
                                        </tr>
                                        <tr>
                                            <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:20px;text-align:center;">
                                                <p style="margin:0;color:#9CA3AF;font-size:11px;">Automated message from WMSU Document Management System</p>
                                            </td>
                                        </tr>
                                    </table>
                                    </td>
                                    </tr>
                                </table>
                            </body>
                            </html>';
                            
                            $mail->AltBody = "Hello {$receiver['full_name']},\n\n"
                                . "You have a new document: {$document_label} ({$document_number})\n"
                                . "Sent by: {$sender_name}\n"
                                . "Date: " . date('F d, Y \a\t h:i A') . "\n\n"
                                . "Mark as Received: {$confirm_url}\n"
                                . "Download Document: {$download_url}\n\n"
                                . "— WMSU Document Management System";
                                
                            $mail->send();
                            $mail_sent++;
                            
                            // Update recipient status
                            $update_stmt = $pdo->prepare("UPDATE document_recipients SET status = 'Sent', sent_at = NOW() WHERE confirmation_token = ?");
                            $update_stmt->execute([$token]);
                            
                            // Create notification for admin
                            $notif_stmt = $pdo->prepare("
                                INSERT INTO notifications (user_id, notification_type, title, message, document_type, document_id)
                                VALUES (?, 'Document Released', ?, ?, ?, ?)
                            ");
$notif_stmt->execute([
    $receiver['id'], // the actual recipient
    'Document Released: ' . $document_label,
    "Document {$document_number} has been released to you by {$sender_name}",
    $document_label_map[$document_type] ?? $document_label,
    $document_id
]);
                            
                        } catch (Exception $e) {
                            $mail_errors[] = "Could not send to {$receiver['full_name']}: " . $e->getMessage();
                        }
                    }
                }
                
                $pdo->commit();
                
                if ($is_draft) {
                    $success = 'Document saved as draft. Reference: ' . $document_number;
                } elseif (empty($mail_errors)) {
                    $success = "Document submitted successfully! Email" . ($mail_sent > 1 ? 's' : '') 
                        . " sent to {$mail_sent} recipient" . ($mail_sent > 1 ? 's' : '') 
                        . ". Reference: {$document_number}";
                } else {
                    $success = "Document submitted. {$mail_sent} email(s) sent.";
                    $error = implode('<br>', $mail_errors);
                }
                
                // Reset POST to clear form
                $_POST = [];
                
            } catch (PDOException $e) {
                $pdo->rollBack();
                $error = "Database error: " . $e->getMessage();
            }
        }
    }
}

// Load users from users table as potential recipients
$all_receivers = $pdo->query("
    SELECT id, full_name as name, department, role, email
    FROM users 
    WHERE is_active = 1 
    ORDER BY full_name ASC
")->fetchAll();

$avatar_colors = [
    'bg-indigo-500', 'bg-violet-500', 'bg-sky-500',
    'bg-emerald-500', 'bg-amber-500', 'bg-rose-500',
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
                        'main': ['"Noto Nastaliq Urdu"', 'serif'],
                        'secondary': ['"IBM Plex Sans"', 'sans-serif'],
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
        .modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 1000;
            justify-content: center;
            align-items: center;
        }
        .modal.active {
            display: flex;
        }
        .modal-content {
            background: white;
            border-radius: 16px;
            max-width: 600px;
            width: 90%;
            max-height: 80vh;
            overflow-y: auto;
        }
        .ac-wrapper { position: relative; }
        .ac-dropdown {
            display: none;
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            z-index: 50;
            background: #fff;
            border: 1px solid #e5e7eb;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0,0,0,.1);
            max-height: 200px;
            overflow-y: auto;
        }
        .ac-dropdown.is-open { display: block; }
        .ac-item {
            padding: 10px 14px;
            cursor: pointer;
            border-bottom: 1px solid #f3f4f6;
        }
        .ac-item:last-child { border-bottom: none; }
        .ac-item:hover { background: #FFCCCE; }
        .ac-item-name { font-size: 14px; font-weight: 600; color: #111827; }
        .ac-item-meta { font-size: 12px; color: #9CA3AF; margin-top: 2px; }
        .ac-empty { padding: 10px 14px; font-size: 13px; color: #9CA3AF; }
    </style>
</head>
<body class="bg-gray-100">

    <?php $active_page = 'receiving'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">
        <header class="bg-white shadow-sm sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3 min-w-0">
                        <button id="burgerBtn" class="lg:hidden flex flex-col justify-center items-center w-10 h-10 rounded-lg hover:bg-gray-100 transition-colors flex-shrink-0">
                            <span class="block w-5 h-0.5 bg-gray-700 mb-1 rounded"></span>
                            <span class="block w-5 h-0.5 bg-gray-700 mb-1 rounded"></span>
                            <span class="block w-5 h-0.5 bg-gray-700 rounded"></span>
                        </button>
                        <div class="min-w-0">
                            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 font-main truncate">Receiving Department</h2>
                            <p class="hidden sm:block text-sm text-gray-600 mt-1 font-secondary">Process and verify incoming documents</p>
                        </div>
                    </div>
                    <div class="flex items-center space-x-4">
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

        <div class="px-4 sm:px-6 lg:px-8 py-8">
            <div class="grid grid-cols-1">
                <div>
                    <div class="bg-white rounded-2xl shadow p-6">
                        <div class="flex items-center justify-between gap-3 mb-6">
                            <h3 class="text-xl font-bold text-gray-800 font-main">Receive New Document</h3>
                            <span class="flex-shrink-0 px-3 py-1 bg-crimson-100 text-crimson-700 rounded-lg text-sm font-semibold font-secondary">New Entry</span>
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
                            <div>
                                <label for="documentType" class="block text-sm font-semibold text-gray-700 mb-2 font-secondary">
                                    Document Type <span class="text-crimson-600">*</span>
                                </label>
                                <select id="documentType" name="documentType" required
                                    onchange="handleDocumentTypeChange(this.value)"
                                    class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary">
                                    <option value="">Select document type...</option>
                                    <option value="memorandum" <?= ($_POST['documentType']??'') === 'memorandum' ? 'selected':'' ?>>Memorandum Order</option>
                                    <option value="special_order" <?= ($_POST['documentType']??'') === 'special_order' ? 'selected':'' ?>>Special Order</option>
                                    <option value="travel_order" <?= ($_POST['documentType']??'') === 'travel_order' ? 'selected':'' ?>>Travel Order</option>
                                </select>
                            </div>

                            <!-- Dynamic Form Container -->
                            <div id="dynamicFormContainer"></div>

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

                                <!-- Hidden OCR text input submitted with form -->
                                <input type="hidden" id="ocr_text_input" name="ocr_text">

                                <!-- OCR Progress Bar (shown only for images) -->
                                <div id="ocrProgress" class="hidden mt-3 p-3 bg-blue-50 border border-blue-200 rounded-lg">
                                    <div class="flex items-center gap-2 mb-1">
                                        <svg class="w-4 h-4 text-blue-600 animate-spin" fill="none" viewBox="0 0 24 24">
                                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8v8z"></path>
                                        </svg>
                                        <span id="ocrStatusText" class="text-xs text-blue-700 font-secondary font-semibold">Extracting text from image...</span>
                                    </div>
                                    <div class="w-full bg-blue-200 rounded-full h-1.5">
                                        <div id="ocrProgressBar" class="bg-blue-600 h-1.5 rounded-full transition-all duration-300" style="width:0%"></div>
                                    </div>
                                </div>
                            </div>

                            <div class="border border-gray-200 rounded-lg p-4">
                                <p class="text-sm font-semibold text-gray-500 mb-4 font-secondary">
                                    Receiver Selection
                                    <span class="ml-2 text-xs font-normal text-gray-400"><?= count($all_receivers) ?> receiver(s) available</span>
                                </p>

                                <div class="flex flex-col gap-3 mb-4">
                                    <div class="flex items-center gap-2">
                                        <div class="relative flex-1">
                                            <label class="block text-xs font-semibold text-gray-600 mb-1 font-secondary">Search Name</label>
                                            <input type="text" id="receiverSearch" placeholder="Search by name..."
                                                class="w-full px-4 py-3 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary text-sm">
                                        </div>
                                    </div>
                                    <div class="grid grid-cols-2 gap-3">
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-600 mb-1 font-secondary">Filter by Role</label>
                                            <select id="filterRole" class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary text-sm">
                                                <option value="">All Roles</option>
                                                <option value="Admin">Admin</option>
                                                <option value="Director">Director</option>
                                                <option value="Dean">Dean</option>
                                                <option value="Staff">Staff</option>
                                                <option value="Faculty">Faculty</option>
                                                <option value="Employee">Employee</option>
                                                <option value="Student">Student</option>
                                            </select>
                                        </div>
                                        <div>
                                            <label class="block text-xs font-semibold text-gray-600 mb-1 font-secondary">Filter by Department</label>
                                            <select id="filterDepartment" class="w-full px-3 py-2.5 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary text-sm">
                                                <option value="">All Departments</option>
                                                <option value="IT Department">IT Department</option>
                                                <option value="CS Department">CS Department</option>
                                                <option value="Engineering">Engineering</option>
                                                <option value="Executive Office">Executive Office</option>
                                                <option value="Academic Affairs">Academic Affairs</option>
                                                <option value="Administration">Administration</option>
                                                <option value="Registrar">Registrar</option>
                                                <option value="Human Resources">Human Resources</option>
                                                <option value="Finance">Finance</option>
                                                <option value="Budget">Budget</option>
                                                <option value="Student Affairs">Student Affairs</option>
                                                <option value="Library">Library</option>
                                                <option value="Admission">Admission</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>

                                <div id="receiverList" class="space-y-2 max-h-64 overflow-y-auto pr-1">
                                    <?php if (empty($all_receivers)): ?>
                                    <p class="text-sm text-gray-400 text-center py-6 font-secondary">No recipients found. Add users from the Users page first.</p>
                                    <?php endif; ?>

                                    <?php foreach ($all_receivers as $i => $r):
                                        $color = $avatar_colors[$i % count($avatar_colors)];
                                        $initials = strtoupper(substr($r['name'], 0, 1));
                                    ?>
                                    <label
                                        class="receiver-row flex items-center justify-between bg-white border-2 border-gray-200 rounded-lg px-3 py-3 cursor-pointer hover:border-crimson-400 hover:bg-crimson-50 transition duration-200"
                                        data-name="<?= htmlspecialchars(strtolower($r['name'])) ?>"
                                        data-dept="<?= htmlspecialchars(strtolower($r['department'] ?? '')) ?>"
                                        data-role="<?= htmlspecialchars(strtolower($r['role'] ?? '')) ?>"
                                    >
                                        <div class="flex items-center gap-3 min-w-0 flex-1">
                                            <input type="checkbox" name="receivers[]" value="<?= (int)$r['id'] ?>"
                                                class="w-4 h-4 text-crimson-700 border-gray-300 rounded focus:ring-crimson-500 flex-shrink-0">
                                            <div class="w-9 h-9 <?= $color ?> rounded-full flex items-center justify-center shrink-0">
                                                <span class="text-white text-sm font-bold font-secondary"><?= htmlspecialchars($initials) ?></span>
                                            </div>
                                            <div class="min-w-0 flex-1">
                                                <span class="block text-sm font-bold text-gray-800 font-secondary tracking-wide truncate"><?= htmlspecialchars($r['name']) ?></span>
                                                <?php if (!empty($r['email'])): ?>
                                                <p class="text-xs text-gray-400 font-secondary truncate"><?= htmlspecialchars($r['email']) ?></p>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="flex items-center gap-2 ml-2 flex-shrink-0">
                                            <span class="hidden sm:inline text-xs text-gray-400 font-secondary"><?= htmlspecialchars($r['department'] ?? 'N/A') ?></span>
                                            <span class="text-xs font-semibold px-2 py-0.5 rounded-full bg-gray-100 text-gray-500 font-secondary"><?= htmlspecialchars($r['role'] ?? 'Staff') ?></span>
                                        </div>
                                    </label>
                                    <?php endforeach; ?>
                                </div>

                                <p id="noResults" class="hidden text-sm text-gray-400 text-center py-4 font-secondary">No recipients found matching your search.</p>

                                <div id="selectedCount" class="mt-3 hidden">
                                    <span class="inline-flex items-center gap-1 px-3 py-1 bg-crimson-100 text-crimson-700 rounded-full text-xs font-semibold font-secondary">
                                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20">
                                            <path fill-rule="evenodd" d="M16.707 5.293a1 1 0 00-1.414 0L8 12.586 4.707 9.293a1 1 0 00-1.414 1.414l4 4a1 1 0 001.414 0l8-8a1 1 0 000-1.414z" clip-rule="evenodd"/>
                                        </svg>
                                        <span id="selectedCountText">0 receiver(s) selected</span>
                                    </span>
                                </div>
                            </div>

                            <div class="flex flex-col sm:flex-row gap-3">
                                <button type="submit" name="save_as_draft" value="1"
                                    class="flex-1 bg-gray-200 text-gray-700 font-bold py-3 px-6 rounded-lg hover:bg-gray-300 focus:outline-none focus:ring-4 focus:ring-gray-300 transition duration-200 font-secondary">
                                    Save as Draft
                                </button>
                                <button type="submit" id="submitBtn"
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

    <script>
        // Form templates for each document type
        const formTemplates = {
            memorandum: `
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">M.O. Number <span class="text-red-500">*</span></label>
                            <input type="text" name="mo_number" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., MO-2024-001" value="<?= htmlspecialchars($_POST['mo_number'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date Issued</label>
                            <input type="date" name="date_issued" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" value="<?= htmlspecialchars($_POST['date_issued'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Concerned Faculty <span class="text-red-500">*</span></label>
                        <div class="ac-wrapper">
                            <input type="text" name="concerned_faculty" id="concernedFaculty" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Enter faculty name" value="<?= htmlspecialchars($_POST['concerned_faculty'] ?? '') ?>">
                            <div id="facultyDropdown" class="ac-dropdown"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">College/Department</label>
                        <input type="text" name="college_dept" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., College of Computing Studies" value="<?= htmlspecialchars($_POST['college_dept'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Subject <span class="text-red-500">*</span></label>
                        <textarea name="subject" rows="3" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Document subject"><?= htmlspecialchars($_POST['subject'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Destination &amp; Duration</label>
                        <input type="text" name="destination_duration" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., Manila, 3 days" value="<?= htmlspecialchars($_POST['destination_duration'] ?? '') ?>">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">R.F. (Request Form)</label>
                            <input type="text" name="rf" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., RF-2024-001" value="<?= htmlspecialchars($_POST['rf'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Number of Participants</label>
                            <input type="number" name="no_partly" min="0" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., 10" value="<?= htmlspecialchars($_POST['no_partly'] ?? '') ?>">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Source</label>
                        <input type="text" name="source" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., Pres-MCO" value="<?= htmlspecialchars($_POST['source'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Remarks</label>
                        <textarea name="remarks" rows="2" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Additional remarks"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            `,
            special_order: `
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">S.O. Number <span class="text-red-500">*</span></label>
                            <input type="text" name="so_number" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., SO-2024-001" value="<?= htmlspecialchars($_POST['so_number'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date Issued</label>
                            <input type="date" name="date_issued" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" value="<?= htmlspecialchars($_POST['date_issued'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Concerned Faculty <span class="text-red-500">*</span></label>
                        <div class="ac-wrapper">
                            <input type="text" name="concerned_faculty" id="concernedFaculty" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Enter faculty name" value="<?= htmlspecialchars($_POST['concerned_faculty'] ?? '') ?>">
                            <div id="facultyDropdown" class="ac-dropdown"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Subject <span class="text-red-500">*</span></label>
                        <textarea name="subject" rows="3" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Document subject"><?= htmlspecialchars($_POST['subject'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Effectivity</label>
                        <input type="text" name="effectivity" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., Effective immediately" value="<?= htmlspecialchars($_POST['effectivity'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Source Signatory</label>
                        <input type="text" name="source_signatory" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., Pres-MCO" value="<?= htmlspecialchars($_POST['source_signatory'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Remarks</label>
                        <textarea name="remarks" rows="2" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Additional remarks"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            `,
            travel_order: `
                <div class="space-y-4">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">T.O. Number <span class="text-red-500">*</span></label>
                            <input type="text" name="io_number" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., TO-2024-001" value="<?= htmlspecialchars($_POST['io_number'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Date Issued</label>
                            <input type="date" name="date_issued" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" value="<?= htmlspecialchars($_POST['date_issued'] ?? date('Y-m-d')) ?>">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Employee Name <span class="text-red-500">*</span></label>
                        <div class="ac-wrapper">
                            <input type="text" name="employee_name" id="employeeName" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Enter employee name" value="<?= htmlspecialchars($_POST['employee_name'] ?? '') ?>">
                            <div id="employeeDropdown" class="ac-dropdown"></div>
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Office/Department</label>
                        <input type="text" name="office" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., IT Department" value="<?= htmlspecialchars($_POST['office'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Subject <span class="text-red-500">*</span></label>
                        <textarea name="subject" rows="3" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Document subject"><?= htmlspecialchars($_POST['subject'] ?? '') ?></textarea>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Destination</label>
                        <input type="text" name="destination" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Travel destination" value="<?= htmlspecialchars($_POST['destination'] ?? '') ?>">
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Travel Start Date</label>
                            <input type="date" name="travel_start_date" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" value="<?= htmlspecialchars($_POST['travel_start_date'] ?? '') ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Travel End Date</label>
                            <input type="date" name="travel_end_date" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" value="<?= htmlspecialchars($_POST['travel_end_date'] ?? '') ?>">
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Fund Assistance (₱)</label>
                            <input type="number" name="fund_assistance" min="0" step="0.01" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., 5000.00" value="<?= htmlspecialchars($_POST['fund_assistance'] ?? '0') ?>">
                        </div>
                        <div>
                            <label class="block text-sm font-semibold text-gray-700 mb-2">Number of Participants</label>
                            <input type="number" name="no_partly" min="0" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., 5" value="<?= htmlspecialchars($_POST['no_partly'] ?? '') ?>">
                        </div>
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Source</label>
                        <input type="text" name="source" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="e.g., Pres-MCO, Budget" value="<?= htmlspecialchars($_POST['source'] ?? '') ?>">
                    </div>
                    <div>
                        <label class="block text-sm font-semibold text-gray-700 mb-2">Remarks</label>
                        <textarea name="remarks" rows="2" class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:border-crimson-700" placeholder="Additional remarks"><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            `
        };

        let debounceTimer;
        
        function setupAutocomplete(inputId, dropdownId) {
            const input = document.getElementById(inputId);
            if (!input) return;
            
            input.addEventListener('input', function() {
                clearTimeout(debounceTimer);
                const query = this.value.trim();
                const dropdown = document.getElementById(dropdownId);
                
                if (query.length < 2) {
                    dropdown.classList.remove('is-open');
                    return;
                }
                
                debounceTimer = setTimeout(() => {
                    fetch(`../api/autocomplete.php?q=${encodeURIComponent(query)}`)
                        .then(r => r.json())
                        .then(results => {
                            dropdown.innerHTML = '';
                            if (results.length === 0) {
                                dropdown.innerHTML = '<div class="ac-empty">No users found.</div>';
                                dropdown.classList.add('is-open');
                                return;
                            }
                            
                            results.forEach(r => {
                                const item = document.createElement('div');
                                item.className = 'ac-item';
                                item.innerHTML = `<div class="ac-item-name">${escapeHtml(r.name)}</div><div class="ac-item-meta">${escapeHtml(r.department)} &middot; ${escapeHtml(r.role)}</div>`;
                                item.addEventListener('mousedown', (e) => {
                                    e.preventDefault();
                                    input.value = r.name;
                                    dropdown.classList.remove('is-open');
                                });
                                dropdown.appendChild(item);
                            });
                            dropdown.classList.add('is-open');
                        })
                        .catch(() => dropdown.classList.remove('is-open'));
                }, 300);
            });
            
            document.addEventListener('click', (e) => {
                if (!e.target.closest('.ac-wrapper')) {
                    document.getElementById(dropdownId)?.classList.remove('is-open');
                }
            });
        }
        
        function escapeHtml(str) {
            return String(str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        
        function handleDocumentTypeChange(value) {
            const container = document.getElementById('dynamicFormContainer');
            if (value && formTemplates[value]) {
                container.innerHTML = formTemplates[value];
                if (value === 'memorandum' || value === 'special_order') {
                    setupAutocomplete('concernedFaculty', 'facultyDropdown');
                } else if (value === 'travel_order') {
                    setupAutocomplete('employeeName', 'employeeDropdown');
                }
            } else {
                container.innerHTML = '';
            }
        }
        
        // File upload handling
        const fileUpload = document.getElementById('fileUpload');
        const fileList = document.getElementById('fileList');
        const dropZone = document.getElementById('dropZone');
        let uploadedFiles = [];
        
        fileUpload.addEventListener('change', (e) => {
            uploadedFiles = [...uploadedFiles, ...Array.from(e.target.files)];
            displayFiles();
        });
        
        dropZone.addEventListener('dragover', (e) => {
            e.preventDefault();
            dropZone.classList.add('border-crimson-700', 'bg-crimson-50');
        });
        
        dropZone.addEventListener('dragleave', () => {
            dropZone.classList.remove('border-crimson-700', 'bg-crimson-50');
        });
        
        dropZone.addEventListener('drop', (e) => {
            e.preventDefault();
            dropZone.classList.remove('border-crimson-700', 'bg-crimson-50');
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
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <div>
                            <p class="text-sm font-semibold text-gray-800">${escapeHtml(file.name)}</p>
                            <p class="text-xs text-gray-500">${(file.size/1024).toFixed(2)} KB</p>
                        </div>
                    </div>
                    <button type="button" onclick="removeFile(${index})" class="text-red-600 hover:text-red-800">
                        <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                        </svg>
                    </button>
                `;
                fileList.appendChild(item);
            });
            
            // Update the file input with the current file list
            const dataTransfer = new DataTransfer();
            uploadedFiles.forEach(file => dataTransfer.items.add(file));
            fileUpload.files = dataTransfer.files;
        }
        
        window.removeFile = function(index) {
            uploadedFiles.splice(index, 1);
            displayFiles();
        };
        
        // Receiver filtering
        const receiverSearch = document.getElementById('receiverSearch');
        const filterRole = document.getElementById('filterRole');
        const filterDepartment = document.getElementById('filterDepartment');
        const receiverList = document.getElementById('receiverList');
        const noResults = document.getElementById('noResults');
        const selectedCount = document.getElementById('selectedCount');
        const selectedCountText = document.getElementById('selectedCountText');
        
        function filterReceivers() {
            const query = receiverSearch.value.toLowerCase().trim();
            const role = filterRole.value.toLowerCase();
            const dept = filterDepartment.value.toLowerCase();
            const rows = receiverList.querySelectorAll('label.receiver-row');
            let visible = 0;
            
            rows.forEach(row => {
                const matchName = !query || (row.dataset.name || '').toLowerCase().includes(query);
                const matchRole = !role || (row.dataset.role || '').toLowerCase() === role;
                const matchDept = !dept || (row.dataset.dept || '').toLowerCase().includes(dept);
                
                if (matchName && matchRole && matchDept) {
                    row.classList.remove('hidden');
                    visible++;
                } else {
                    row.classList.add('hidden');
                }
            });
            
            noResults.classList.toggle('hidden', visible > 0);
        }
        
        function updateSelectedCount() {
            const checked = receiverList.querySelectorAll('input[type="checkbox"]:checked').length;
            selectedCount.classList.toggle('hidden', checked === 0);
            selectedCountText.textContent = checked + ' receiver' + (checked > 1 ? 's' : '') + ' selected';
        }
        
        receiverSearch.addEventListener('input', filterReceivers);
        filterRole.addEventListener('change', filterReceivers);
        filterDepartment.addEventListener('change', filterReceivers);
        receiverList.addEventListener('change', updateSelectedCount);
        
        // Initialize if document type is pre-selected
        document.addEventListener('DOMContentLoaded', () => {
            const savedType = document.getElementById('documentType').value;
            if (savedType) handleDocumentTypeChange(savedType);
            updateSelectedCount();
        });
    </script>
    <script src="../js/sidebar.js"></script>

    <!-- Tesseract.js OCR -->
    <script src="https://cdn.jsdelivr.net/npm/tesseract.js@5/dist/tesseract.min.js"></script>
    <script>
        const ocrInput       = document.getElementById('ocr_text_input');
        const ocrProgress    = document.getElementById('ocrProgress');
        const ocrProgressBar = document.getElementById('ocrProgressBar');
        const ocrStatusText  = document.getElementById('ocrStatusText');
        const submitBtn      = document.getElementById('submitBtn');

        let ocrRunning = false;

        function isImageFile(file) {
            return file && file.type.startsWith('image/');
        }

        function resetOcrUI() {
            // Hide and fully reset the progress bar
            ocrProgress.classList.add('hidden');
            ocrProgress.classList.remove('bg-green-50', 'border-green-200');
            ocrProgress.classList.add('bg-blue-50', 'border-blue-200');
            ocrProgressBar.style.width = '0%';
            ocrStatusText.classList.remove('text-green-700');
            ocrStatusText.classList.add('text-blue-700');
            ocrStatusText.textContent = 'Extracting text from image...';
            ocrInput.value = '';
        }

        function setSubmitLocked(locked) {
            submitBtn.disabled = locked;
            if (locked) {
                submitBtn.classList.add('opacity-50', 'cursor-not-allowed');
                submitBtn.classList.remove('hover:bg-crimson-800', 'hover:scale-[1.02]', 'active:scale-[0.98]');
                submitBtn.title = 'Please wait — OCR is still extracting text from the image.';
            } else {
                submitBtn.classList.remove('opacity-50', 'cursor-not-allowed');
                submitBtn.classList.add('hover:bg-crimson-800', 'hover:scale-[1.02]', 'active:scale-[0.98]');
                submitBtn.title = '';
            }
        }

        async function runOCR(file) {
            ocrRunning = true;
            setSubmitLocked(true);
            ocrInput.value = '';
            ocrProgress.classList.remove('hidden');
            ocrProgressBar.style.width = '0%';
            ocrStatusText.textContent = 'Extracting text from image...';

            try {
                const result = await Tesseract.recognize(file, 'eng', {
                    logger: m => {
                        if (m.status === 'recognizing text') {
                            const pct = Math.round((m.progress || 0) * 100);
                            ocrProgressBar.style.width = pct + '%';
                            ocrStatusText.textContent = 'Extracting text... ' + pct + '%';
                        }
                    }
                });
                const extracted = result.data.text.trim();
                ocrInput.value = extracted;
                ocrProgressBar.style.width = '100%';
                ocrStatusText.textContent = extracted
                    ? '✓ Text extracted successfully (' + extracted.length + ' characters)'
                    : '⚠ No text found in image.';
                ocrProgress.classList.remove('bg-blue-50', 'border-blue-200');
                ocrProgress.classList.add('bg-green-50', 'border-green-200');
                ocrStatusText.classList.remove('text-blue-700');
                ocrStatusText.classList.add('text-green-700');
                // Hide the progress bar after 1 seconds
                setTimeout(() => resetOcrUI(), 1000);
            } catch (err) {
                ocrStatusText.textContent = '⚠ OCR failed — image will be saved without text.';
                ocrInput.value = '';
            } finally {
                ocrRunning = false;
                setSubmitLocked(false);
            }
        }

        // Override removeFile to also reset OCR if the removed file was an image
        const _origRemoveFile = window.removeFile;
        window.removeFile = function(index) {
            // Check if the file being removed is the first image (the one OCR ran on)
            const uploadedFilesSnap = document.getElementById('fileUpload').files;
            const removingFirst = (index === 0);
            _origRemoveFile(index);
            // After removal, if no image remains as first file, reset OCR UI
            const remaining = document.getElementById('fileUpload').files;
            if (removingFirst || remaining.length === 0 || !isImageFile(remaining[0])) {
                resetOcrUI();
                // Also unlock submit if OCR was somehow still running
                if (ocrRunning) {
                    ocrRunning = false;
                    setSubmitLocked(false);
                }
            }
        };

        // Hook into the existing file change event
        const fileUploadEl = document.getElementById('fileUpload');
        fileUploadEl.addEventListener('change', async () => {
            const files = fileUploadEl.files;
            if (files.length > 0 && isImageFile(files[0])) {
                await runOCR(files[0]);
            } else {
                resetOcrUI();
                setSubmitLocked(false);
            }
        });
    </script>
</body>
</html>