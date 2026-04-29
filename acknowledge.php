<?php
// ============================================================
// acknowledge.php
// Place at: /WMSU-Receive-System/acknowledge.php
// ============================================================
require_once __DIR__ . '/config/db.php';

$pdo    = getPDO();
$token  = trim($_GET['token']  ?? '');
$action = trim($_GET['action'] ?? 'confirm');
$state  = 'invalid';
$info   = [];

if ($token) {
    $stmt = $pdo->prepare("
        SELECT dr.id, dr.status, dr.document_type, dr.document_ref, dr.document_id,
               r.name AS receiver_name, r.email AS receiver_email
        FROM document_recipients dr
        JOIN receivers r ON r.id = dr.receiver_id
        WHERE dr.token = :token LIMIT 1
    ");
    $stmt->execute([':token' => $token]);
    $info = $stmt->fetch();

    if (!$info) {
        $state = 'invalid';
    } else {
        if ($info['status'] !== 'released') {
            $pdo->prepare("UPDATE document_recipients SET status='released', released_at=NOW() WHERE token=:token")
                ->execute([':token' => $token]);
            $state = 'success';
        } else {
            $state = 'already';
        }

        if ($action === 'download') {
            $file_stmt = $pdo->prepare("
                SELECT original_name, stored_name, file_path, mime_type
                FROM document_files
                WHERE document_type=:document_type AND document_id=:document_id
                ORDER BY uploaded_at ASC LIMIT 1
            ");
            $file_stmt->execute([':document_type'=>$info['document_type'],':document_id'=>$info['document_id']]);
            $file = $file_stmt->fetch();

            if ($file) {
                $full_path = __DIR__ . '/' . $file['file_path'];
                if (file_exists($full_path)) {
                    header('Content-Description: File Transfer');
                    header('Content-Type: ' . ($file['mime_type'] ?: 'application/octet-stream'));
                    header('Content-Disposition: attachment; filename="' . addslashes($file['original_name']) . '"');
                    header('Content-Length: ' . filesize($full_path));
                    header('Cache-Control: must-revalidate');
                    header('Pragma: public');
                    ob_clean(); flush();
                    readfile($full_path);
                    exit;
                }
            }
        }
    }
}

$doc_label = ucwords(str_replace('_', ' ', $info['document_type'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Document Acknowledgement — WMSU</title>
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
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-6">

    <div class="bg-white rounded-2xl shadow-lg p-10 w-full max-w-md text-center">

        <!-- Brand -->
        <div class="w-14 h-14 bg-crimson-800 rounded-full flex items-center justify-center mx-auto mb-3">
            <span class="text-white font-black text-xl font-secondary">W</span>
        </div>
        <p class="text-xs text-gray-400 mb-8 uppercase tracking-widest font-secondary">WMSU Document Management</p>

        <?php if ($state === 'success'): ?>
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2.5" d="M5 13l4 4L19 7"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-2 font-main">Document Received!</h1>
            <p class="text-gray-500 text-sm mb-6 font-secondary leading-relaxed">
                Thank you, <strong class="text-gray-800"><?= htmlspecialchars($info['receiver_name']) ?></strong>.<br>
                You have successfully acknowledged receipt of:
            </p>
            <div class="bg-gray-50 border-2 border-gray-200 rounded-xl px-5 py-4 mb-6 text-left">
                <p class="text-xs text-gray-400 uppercase tracking-wider mb-1 font-secondary">Document Type</p>
                <p class="font-bold text-gray-800 font-secondary"><?= htmlspecialchars($doc_label) ?></p>
                <?php if (!empty($info['document_ref'])): ?>
                <p class="text-sm text-gray-500 mt-1 font-secondary"><?= htmlspecialchars($info['document_ref']) ?></p>
                <?php endif; ?>
            </div>
            <p class="text-xs text-gray-400 font-secondary">
                This document has been marked as
                <span class="text-green-600 font-semibold">Released</span>
                in the records system.
            </p>

        <?php elseif ($state === 'already'): ?>
            <div class="w-16 h-16 bg-blue-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-blue-500" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M13 16h-1v-4h-1m1-4h.01M12 2a10 10 0 100 20A10 10 0 0012 2z"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-2 font-main">Already Acknowledged</h1>
            <p class="text-gray-500 text-sm mb-6 font-secondary leading-relaxed">
                <strong class="text-gray-800"><?= htmlspecialchars($info['receiver_name']) ?></strong>,
                you have already confirmed receipt of this document.
            </p>
            <div class="bg-gray-50 border-2 border-gray-200 rounded-xl px-5 py-4 text-left">
                <p class="text-xs text-gray-400 uppercase tracking-wider mb-1 font-secondary">Document Type</p>
                <p class="font-bold text-gray-800 font-secondary"><?= htmlspecialchars($doc_label) ?></p>
                <?php if (!empty($info['document_ref'])): ?>
                <p class="text-sm text-gray-500 mt-1 font-secondary"><?= htmlspecialchars($info['document_ref']) ?></p>
                <?php endif; ?>
            </div>

        <?php else: ?>
            <div class="w-16 h-16 bg-crimson-100 rounded-full flex items-center justify-center mx-auto mb-5">
                <svg class="w-8 h-8 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
                </svg>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-2 font-main">Invalid Link</h1>
            <p class="text-gray-500 text-sm font-secondary leading-relaxed">
                This acknowledgement link is invalid or has expired.<br>
                Please contact the Records Office if you need assistance.
            </p>
        <?php endif; ?>

    </div>

</body>
</html>
