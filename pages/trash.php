<?php
session_start();
require_once '../auth-guard/Auth.php';
require_once '../config/db.php';

$pdo = getPDO();

function getInitialsFromEmail($email) {
    if (empty($email)) return 'U';
    $namePart = explode('@', $email)[0];
    $parts    = preg_split('/[._-]/', $namePart);
    $initials = '';
    foreach ($parts as $part) {
        if (!empty($part)) $initials .= strtoupper(substr($part, 0, 1));
        if (strlen($initials) >= 2) break;
    }
    return $initials ?: strtoupper(substr($email, 0, 1)) ?: 'U';
}

$user_email        = $_SESSION['user_email'] ?? '';
$user_id           = $_SESSION['user_id']    ?? 0;
$user_initials     = getInitialsFromEmail($user_email);
$user_role         = $_SESSION['user_role']  ?? 'user';
$user_role_display = ucfirst($user_role);

// ── AJAX handlers ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    // Restore a single message
    if ($_POST['action'] === 'restore_message') {
        $id = (int)($_POST['recipient_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE document_recipients SET deleted_at = NULL WHERE id = ? AND recipient_email = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id, $user_email]);
        echo json_encode($stmt->rowCount() > 0
            ? ['success' => true,  'message' => 'Message restored to inbox.']
            : ['success' => false, 'message' => 'Message not found.']);
        exit;
    }

    // Permanently delete a single message
    if ($_POST['action'] === 'delete_forever') {
        $id = (int)($_POST['recipient_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM document_recipients WHERE id = ? AND recipient_email = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id, $user_email]);
        echo json_encode($stmt->rowCount() > 0
            ? ['success' => true,  'message' => 'Message permanently deleted.']
            : ['success' => false, 'message' => 'Message not found or not in trash.']);
        exit;
    }

    // Empty entire trash (delete all trashed for this user)
    if ($_POST['action'] === 'empty_trash') {
        $stmt = $pdo->prepare("DELETE FROM document_recipients WHERE recipient_email = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$user_email]);
        echo json_encode(['success' => true, 'deleted' => $stmt->rowCount()]);
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}

// ── Fetch trashed documents for this user ─────────────────────────────────────
$query = "
    SELECT 
        dr.id as recipient_id,
        dr.document_type,
        dr.document_id,
        dr.status,
        dr.feedback,
        dr.created_at  as received_date,
        dr.deleted_at,
        COALESCE(
            CASE 
                WHEN dr.document_type = 'Memorandum Order' THEN m.mo_number
                WHEN dr.document_type = 'Special Order'    THEN s.so_number
                WHEN dr.document_type = 'Travel Order'     THEN t.io_number
            END,
            m.mo_number
        ) as document_number,
        COALESCE(
            CASE 
                WHEN dr.document_type = 'Memorandum Order' THEN m.subject
                WHEN dr.document_type = 'Special Order'    THEN s.subject
                WHEN dr.document_type = 'Travel Order'     THEN t.subject
            END,
            m.subject
        ) as subject,
        COALESCE(
            CASE 
                WHEN dr.document_type = 'Memorandum Order' THEN m.sender_email
                WHEN dr.document_type = 'Special Order'    THEN s.sender_email
                WHEN dr.document_type = 'Travel Order'     THEN t.sender_email
            END,
            m.sender_email
        ) as sender_email,
        COALESCE(
            CASE 
                WHEN dr.document_type = 'Memorandum Order' THEN m.date_issued
                WHEN dr.document_type = 'Special Order'    THEN s.date_issued
                WHEN dr.document_type = 'Travel Order'     THEN t.date_issued
            END,
            m.date_issued
        ) as date_issued,
        COALESCE(
            CASE dr.document_type
                WHEN 'Memorandum Order' THEN 'Memorandum Order'
                WHEN 'Special Order'    THEN 'Special Order'
                WHEN 'Travel Order'     THEN 'Travel Order'
            END,
            CASE WHEN m.id IS NOT NULL THEN 'Memorandum Order'
                 WHEN s.id IS NOT NULL THEN 'Special Order'
                 WHEN t.id IS NOT NULL THEN 'Travel Order'
            END
        ) as resolved_type
    FROM document_recipients dr
    LEFT JOIN memorandum_orders m ON dr.document_id = m.id
        AND (dr.document_type = 'Memorandum Order' OR dr.document_type = '')
    LEFT JOIN special_orders s ON dr.document_id = s.id
        AND dr.document_type = 'Special Order'
    LEFT JOIN travel_orders t ON dr.document_id = t.id
        AND dr.document_type = 'Travel Order'
    WHERE dr.recipient_email = ?
      AND dr.deleted_at IS NOT NULL
    ORDER BY dr.deleted_at DESC
";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_email]);
$trashed = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Resolve empty document_type
foreach ($trashed as &$doc) {
    if (empty($doc['document_type'])) {
        $doc['document_type'] = $doc['resolved_type'] ?? 'Memorandum Order';
    }
}
unset($doc);

// Inbox unread count for sidebar badge
$unreadStmt = $pdo->prepare("SELECT COUNT(*) FROM document_recipients WHERE recipient_email = ? AND status IN ('Pending','Sent') AND deleted_at IS NULL");
$unreadStmt->execute([$user_email]);
$inbox_unread = (int)$unreadStmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trash — WMSU Document Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
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
        body { font-family: 'IBM Plex Sans', sans-serif; }
        h1,h2,h3,h4,h5,h6 { font-family: 'Noto Nastaliq Urdu', serif; }
        .trash-row { transition: all 0.2s ease; }
        .trash-row:hover { transform: translateY(-2px); }
        /* Action buttons hidden until hover */
        .trash-row .row-actions { opacity: 0; transition: opacity 0.15s ease; }
        .trash-row:hover .row-actions { opacity: 1; }
    </style>
</head>
<body class="bg-gray-100">

    <?php $active_page = 'inbox'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">

        <!-- Top Bar -->
        <header class="bg-white shadow-sm sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3 min-w-0">
                        <button id="burgerBtn" class="lg:hidden flex flex-col justify-center items-center w-10 h-10 rounded-lg hover:bg-gray-100 transition-colors flex-shrink-0" aria-label="Toggle menu">
                            <span class="block w-5 h-0.5 bg-gray-700 mb-1 rounded"></span>
                            <span class="block w-5 h-0.5 bg-gray-700 mb-1 rounded"></span>
                            <span class="block w-5 h-0.5 bg-gray-700 rounded"></span>
                        </button>
                        <div class="min-w-0">
                            <div class="flex items-center gap-2">
                                <!-- Breadcrumb: Inbox → Trash -->
                                <a href="inbox.php" class="text-sm text-gray-400 hover:text-crimson-700 font-secondary transition flex items-center gap-1">
                                    <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    Inbox
                                </a>
                                <svg class="w-3 h-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                </svg>
                                <h2 class="text-xl sm:text-2xl font-bold text-gray-800 font-main">Trash</h2>
                            </div>
                            <p class="hidden sm:block text-sm text-gray-500 mt-0.5 font-secondary">
                                Deleted messages — restore or permanently delete them here
                            </p>
                        </div>
                    </div>

                    <div class="flex items-center space-x-3">
                        <!-- Empty Trash button — only shown if there's something to delete -->
                        <?php if (!empty($trashed)): ?>
                        <button id="emptyTrashBtn"
                            class="flex items-center gap-2 px-4 py-2 bg-crimson-50 text-crimson-700 border border-crimson-200 rounded-lg text-sm font-semibold font-secondary hover:bg-crimson-100 transition">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                            </svg>
                            Empty Trash
                        </button>
                        <?php endif; ?>

                        <div class="hidden sm:block text-right">
                            <p class="text-sm font-semibold text-gray-800 font-secondary"><?= htmlspecialchars($user_email) ?></p>
                            <p class="text-xs text-gray-500 font-secondary"><?= htmlspecialchars($user_role_display) ?></p>
                        </div>
                        <div class="w-10 h-10 bg-crimson-700 rounded-full flex items-center justify-center">
                            <span class="text-white font-semibold font-secondary"><?= htmlspecialchars($user_initials) ?></span>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <!-- Page Content -->
        <div class="px-4 sm:px-6 lg:px-8 py-8">

            <?php if (!empty($trashed)): ?>
            <!-- Info banner -->
            <div class="mb-4 flex items-start gap-3 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3 text-sm text-amber-700 font-secondary">
                <svg class="w-5 h-5 flex-shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18A9 9 0 0012 3z"/>
                </svg>
                <span>Messages in Trash are <strong>not permanently deleted</strong> until you choose to delete them forever. You can restore any message back to your Inbox.</span>
            </div>
            <?php endif; ?>

            <div class="bg-white rounded-2xl shadow p-6">

                <!-- Controls Bar -->
                <div class="flex flex-wrap items-center gap-2 mb-6">
                    <div class="flex-1 min-w-0">
                        <h3 class="text-xl font-bold text-gray-800 font-main">Trashed Messages</h3>
                        <p class="text-sm text-gray-500 font-secondary mt-1">
                            <?= count($trashed) ?> message<?= count($trashed) !== 1 ? 's' : '' ?> in trash
                        </p>
                    </div>

                    <?php if (!empty($trashed)): ?>
                    <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                        <div class="relative flex-1 sm:flex-none sm:w-60 min-w-[150px]">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
                            </svg>
                            <input id="trashSearch" type="text" placeholder="Search..."
                                class="w-full pl-9 pr-4 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary">
                        </div>
                        <select id="trashTypeFilter" class="px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary bg-white text-gray-700">
                            <option value="">All Types</option>
                            <option value="Memorandum Order">Memorandum Order</option>
                            <option value="Special Order">Special Order</option>
                            <option value="Travel Order">Travel Order</option>
                        </select>
                        <span id="trashCount" class="text-xs text-gray-400 font-secondary whitespace-nowrap"></span>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- Table Header -->
                <?php if (!empty($trashed)): ?>
                <div class="overflow-x-auto">
                    <div class="min-w-[700px]">
                        <div class="grid grid-cols-12 text-xs text-gray-400 uppercase px-4 py-2 border-b font-secondary tracking-wider">
                            <div class="col-span-3">Sender</div>
                            <div class="col-span-3">Subject</div>
                            <div class="col-span-2">Type</div>
                            <div class="col-span-2">Deleted On</div>
                            <div class="col-span-2 text-right">Actions</div>
                        </div>

                        <div id="trashRows">
                            <?php foreach ($trashed as $doc):
                                $initials    = getInitialsFromEmail($doc['sender_email'] ?? 'Unknown');
                                $deletedDate = $doc['deleted_at'] ? date('M d, Y', strtotime($doc['deleted_at'])) : 'Unknown';
                                $typeColor   = match($doc['document_type']) {
                                    'Special Order'    => 'bg-purple-100 text-purple-700',
                                    'Travel Order'     => 'bg-teal-100 text-teal-700',
                                    default            => 'bg-gray-100 text-gray-600'
                                };
                            ?>
                            <div class="trash-row grid grid-cols-12 items-center rounded-lg p-4 mt-3 border-2 border-transparent bg-gray-50 hover:shadow-md hover:border-gray-200 transition"
                                 data-id="<?= $doc['recipient_id'] ?>"
                                 data-sender="<?= htmlspecialchars($doc['sender_email'] ?? '') ?>"
                                 data-subject="<?= htmlspecialchars($doc['subject'] ?? '') ?>"
                                 data-type="<?= htmlspecialchars($doc['document_type'] ?? '') ?>">

                                <!-- Sender -->
                                <div class="col-span-3 flex items-center gap-3">
                                    <div class="w-10 h-10 rounded-full bg-gray-400 text-white flex items-center justify-center font-bold font-secondary text-sm flex-shrink-0">
                                        <?= htmlspecialchars(substr($initials, 0, 2)) ?>
                                    </div>
                                    <span class="text-gray-600 font-secondary text-sm truncate">
                                        <?= htmlspecialchars($doc['sender_email'] ?? 'Unknown') ?>
                                    </span>
                                </div>

                                <!-- Subject -->
                                <div class="col-span-3 text-gray-500 font-secondary text-sm truncate pr-2 line-through decoration-gray-300">
                                    <?= htmlspecialchars(substr($doc['subject'] ?? 'No Subject', 0, 55)) ?>
                                </div>

                                <!-- Type -->
                                <div class="col-span-2">
                                    <span class="inline-flex items-center px-2 py-1 <?= $typeColor ?> rounded text-xs font-semibold">
                                        <?= htmlspecialchars($doc['document_type'] ?? 'Unknown') ?>
                                    </span>
                                </div>

                                <!-- Deleted On -->
                                <div class="col-span-2">
                                    <span class="text-xs text-gray-400 font-secondary"><?= $deletedDate ?></span>
                                </div>

                                <!-- Actions -->
                                <div class="col-span-2 flex items-center justify-end gap-2 row-actions">
                                    <!-- Restore -->
                                    <button onclick="restoreMessage(<?= $doc['recipient_id'] ?>, this)"
                                        title="Restore to Inbox"
                                        class="flex items-center gap-1 px-2.5 py-1.5 bg-green-50 text-green-700 border border-green-200 rounded-lg text-xs font-semibold font-secondary hover:bg-green-100 transition whitespace-nowrap">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 10h10a8 8 0 018 8v2M3 10l6 6m-6-6l6-6"/>
                                        </svg>
                                        Restore
                                    </button>
                                    <!-- Delete Forever -->
                                    <button onclick="deleteForever(<?= $doc['recipient_id'] ?>, this)"
                                        title="Delete Permanently"
                                        class="flex items-center gap-1 px-2.5 py-1.5 bg-crimson-50 text-crimson-700 border border-crimson-200 rounded-lg text-xs font-semibold font-secondary hover:bg-crimson-100 transition whitespace-nowrap">
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                        </svg>
                                        Delete Forever
                                    </button>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Filter empty state -->
                        <div id="trashEmpty" class="hidden text-center py-10 text-gray-400 font-secondary text-sm">
                            No messages match your search.
                        </div>
                    </div>
                </div>

                <?php else: ?>
                <!-- Completely empty trash -->
                <div class="text-center py-20 text-gray-300">
                    <svg class="w-16 h-16 mx-auto mb-4 text-gray-200" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                    <p class="text-lg font-semibold text-gray-400 font-main mb-1">Trash is empty</p>
                    <p class="text-sm text-gray-400 font-secondary mb-4">No deleted messages here.</p>
                    <a href="inbox.php" class="inline-flex items-center gap-2 px-4 py-2 bg-crimson-700 text-white text-sm font-semibold rounded-lg hover:bg-crimson-800 transition font-secondary">
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                        </svg>
                        Go to Inbox
                    </a>
                </div>
                <?php endif; ?>

            </div>
        </div>
    </main>

    <script>
        // ── Restore message ───────────────────────────────────────────────────
        async function restoreMessage(id, btn) {
            const result = await Swal.fire({
                title: 'Restore Message?',
                text: 'This message will be moved back to your Inbox.',
                icon: 'question',
                showCancelButton: true,
                confirmButtonColor: '#16a34a',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Yes, restore it'
            });
            if (!result.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'restore_message');
            fd.append('recipient_id', id);

            try {
                const res  = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    animateRemove(btn.closest('.trash-row'));
                    Swal.fire({ icon: 'success', title: 'Restored!', text: 'Message moved back to your Inbox.', timer: 1800, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, confirmButtonColor: '#AA0003' });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Could not restore message.', confirmButtonColor: '#AA0003' });
            }
        }

        // ── Delete forever ────────────────────────────────────────────────────
        async function deleteForever(id, btn) {
            const result = await Swal.fire({
                title: 'Delete Permanently?',
                html: '<p class="text-sm text-gray-600">This action <strong>cannot be undone</strong>.<br>The message will be gone forever.</p>',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#AA0003',
                cancelButtonColor: '#6b7280',
                confirmButtonText: '🗑 Yes, delete forever',
                cancelButtonText: 'Cancel',
                focusCancel: true   // safety: focus Cancel by default
            });
            if (!result.isConfirmed) return;

            // Second confirmation for extra safety
            const confirm2 = await Swal.fire({
                title: 'Are you absolutely sure?',
                text: 'This message and all its data will be permanently removed.',
                icon: 'error',
                showCancelButton: true,
                confirmButtonColor: '#AA0003',
                cancelButtonColor: '#6b7280',
                confirmButtonText: 'Delete Forever',
                focusCancel: true
            });
            if (!confirm2.isConfirmed) return;

            const fd = new FormData();
            fd.append('action', 'delete_forever');
            fd.append('recipient_id', id);

            try {
                const res  = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    animateRemove(btn.closest('.trash-row'));
                    Swal.fire({ icon: 'success', title: 'Deleted', text: 'Message permanently deleted.', timer: 1600, showConfirmButton: false });
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, confirmButtonColor: '#AA0003' });
                }
            } catch (e) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Could not delete message.', confirmButtonColor: '#AA0003' });
            }
        }

        // ── Empty entire trash ────────────────────────────────────────────────
        const emptyTrashBtn = document.getElementById('emptyTrashBtn');
        if (emptyTrashBtn) {
            emptyTrashBtn.addEventListener('click', async () => {
                const result = await Swal.fire({
                    title: 'Empty Trash?',
                    html: '<p class="text-sm text-gray-600">All messages in Trash will be <strong>permanently deleted</strong>.<br>This cannot be undone.</p>',
                    icon: 'warning',
                    showCancelButton: true,
                    confirmButtonColor: '#AA0003',
                    cancelButtonColor: '#6b7280',
                    confirmButtonText: 'Empty Trash',
                    focusCancel: true
                });
                if (!result.isConfirmed) return;

                const fd = new FormData();
                fd.append('action', 'empty_trash');

                try {
                    const res  = await fetch('', { method: 'POST', body: fd });
                    const data = await res.json();

                    if (data.success) {
                        Swal.fire({ icon: 'success', title: 'Trash Emptied', text: `${data.deleted} message(s) permanently deleted.`, timer: 1800, showConfirmButton: false })
                            .then(() => location.reload());
                    }
                } catch (e) {
                    Swal.fire({ icon: 'error', title: 'Error', text: 'Could not empty trash.', confirmButtonColor: '#AA0003' });
                }
            });
        }

        // ── Row slide-out animation ───────────────────────────────────────────
        function animateRemove(row) {
            if (!row) return;
            row.style.transition = 'opacity 0.3s ease, transform 0.3s ease, max-height 0.35s ease';
            row.style.opacity    = '0';
            row.style.transform  = 'translateX(-40px)';
            setTimeout(() => {
                row.remove();
                updateTrashCount();
            }, 330);
        }

        function updateTrashCount() {
            const remaining = document.querySelectorAll('.trash-row').length;
            const countEl   = document.querySelector('p.text-sm.text-gray-500.font-secondary');
            if (countEl) countEl.textContent = remaining + ' message' + (remaining !== 1 ? 's' : '') + ' in trash';
            if (remaining === 0) location.reload(); // show empty-trash UI
        }

        // ── Search / type filter ──────────────────────────────────────────────
        (function() {
            const searchEl   = document.getElementById('trashSearch');
            const typeEl     = document.getElementById('trashTypeFilter');
            const countEl    = document.getElementById('trashCount');
            const emptyEl    = document.getElementById('trashEmpty');
            const container  = document.getElementById('trashRows');

            if (!searchEl || !container) return;

            function filter() {
                const q  = searchEl.value.toLowerCase().trim();
                const tf = typeEl ? typeEl.value : '';

                const rows    = Array.from(container.querySelectorAll('.trash-row'));
                const visible = [];

                rows.forEach(row => {
                    const matchQ  = !q  || row.dataset.sender.toLowerCase().includes(q)
                                         || row.dataset.subject.toLowerCase().includes(q);
                    const matchTF = !tf || row.dataset.type === tf;
                    const show    = matchQ && matchTF;
                    row.style.display = show ? '' : 'none';
                    if (show) visible.push(row);
                });

                if (countEl) countEl.textContent = visible.length + ' message' + (visible.length !== 1 ? 's' : '');
                if (emptyEl) emptyEl.classList.toggle('hidden', visible.length > 0);
            }

            searchEl.addEventListener('input', filter);
            if (typeEl) typeEl.addEventListener('change', filter);
            filter();
        })();
    </script>
    <script src="../js/sidebar.js"></script>
</body>
</html>