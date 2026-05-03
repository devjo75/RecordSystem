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

    if ($_POST['action'] === 'restore_message') {
        $id = (int)($_POST['recipient_id'] ?? 0);
        $stmt = $pdo->prepare("UPDATE document_recipients SET deleted_at = NULL WHERE id = ? AND recipient_email = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id, $user_email]);
        echo json_encode($stmt->rowCount() > 0 ? ['success' => true] : ['success' => false]);
        exit;
    }

    if ($_POST['action'] === 'delete_forever') {
        $id = (int)($_POST['recipient_id'] ?? 0);
        $stmt = $pdo->prepare("DELETE FROM document_recipients WHERE id = ? AND recipient_email = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$id, $user_email]);
        echo json_encode($stmt->rowCount() > 0 ? ['success' => true] : ['success' => false]);
        exit;
    }

    if ($_POST['action'] === 'restore_release') {
        $doc_id   = (int)($_POST['document_id']   ?? 0);
        $doc_type = $_POST['document_type'] ?? '';
        $tableMap = ['Memorandum Order' => 'memorandum_orders', 'Special Order' => 'special_orders', 'Travel Order' => 'travel_orders'];
        if (!$doc_id || !isset($tableMap[$doc_type])) { echo json_encode(['success' => false]); exit; }
        $table = $tableMap[$doc_type];
        $stmt  = $pdo->prepare("UPDATE `$table` SET deleted_at = NULL WHERE id = ? AND created_by = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$doc_id, $user_id]);
        echo json_encode($stmt->rowCount() > 0 ? ['success' => true] : ['success' => false]);
        exit;
    }

    if ($_POST['action'] === 'delete_release_forever') {
        $doc_id   = (int)($_POST['document_id']   ?? 0);
        $doc_type = $_POST['document_type'] ?? '';
        $tableMap = ['Memorandum Order' => 'memorandum_orders', 'Special Order' => 'special_orders', 'Travel Order' => 'travel_orders'];
        if (!$doc_id || !isset($tableMap[$doc_type])) { echo json_encode(['success' => false]); exit; }
        $table = $tableMap[$doc_type];
        $stmt  = $pdo->prepare("DELETE FROM `$table` WHERE id = ? AND created_by = ? AND deleted_at IS NOT NULL");
        $stmt->execute([$doc_id, $user_id]);
        echo json_encode($stmt->rowCount() > 0 ? ['success' => true] : ['success' => false]);
        exit;
    }

    if ($_POST['action'] === 'empty_trash') {
        // Clear inbox trash
        $pdo->prepare("DELETE FROM document_recipients WHERE recipient_email = ? AND deleted_at IS NOT NULL")->execute([$user_email]);
        // Clear released trash (all 3 tables)
        $pdo->prepare("DELETE FROM memorandum_orders WHERE created_by = ? AND deleted_at IS NOT NULL")->execute([$user_id]);
        $pdo->prepare("DELETE FROM special_orders WHERE created_by = ? AND deleted_at IS NOT NULL")->execute([$user_id]);
        $pdo->prepare("DELETE FROM travel_orders WHERE created_by = ? AND deleted_at IS NOT NULL")->execute([$user_id]);
        echo json_encode(['success' => true]);
        exit;
    }
}

// ── Fetch trashed documents ───────────────────────────────────────────────────
$query = "SELECT dr.id as recipient_id, dr.document_type, dr.document_id, dr.created_at as received_date, dr.deleted_at,
          COALESCE(m.mo_number, s.so_number, t.io_number) as document_number,
          COALESCE(m.subject, s.subject, t.subject) as subject,
          COALESCE(m.sender_email, s.sender_email, t.sender_email) as sender_email
          FROM document_recipients dr
          LEFT JOIN memorandum_orders m ON dr.document_id = m.id AND (dr.document_type = 'Memorandum Order' OR dr.document_type = '')
          LEFT JOIN special_orders s ON dr.document_id = s.id AND dr.document_type = 'Special Order'
          LEFT JOIN travel_orders t ON dr.document_id = t.id AND dr.document_type = 'Travel Order'
          WHERE dr.recipient_email = ? AND dr.deleted_at IS NOT NULL ORDER BY dr.deleted_at DESC";
$stmt = $pdo->prepare($query);
$stmt->execute([$user_email]);
$trashed = $stmt->fetchAll(PDO::FETCH_ASSOC);

$releaseTrashQuery = "SELECT 'Memorandum Order' as document_type, id as document_id, mo_number as document_number, subject, deleted_at 
                      FROM memorandum_orders WHERE created_by = ? AND deleted_at IS NOT NULL
                      UNION ALL
                      SELECT 'Special Order', id, so_number, subject, deleted_at FROM special_orders WHERE created_by = ? AND deleted_at IS NOT NULL
                      UNION ALL
                      SELECT 'Travel Order', id, io_number, subject, deleted_at FROM travel_orders WHERE created_by = ? AND deleted_at IS NOT NULL
                      ORDER BY deleted_at DESC";
$relStmt = $pdo->prepare($releaseTrashQuery);
$relStmt->execute([$user_id, $user_id, $user_id]);
$trashed_releases = $relStmt->fetchAll(PDO::FETCH_ASSOC);
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
            theme: { extend: {
                colors: { crimson: { 950:'#4D0001',900:'#800002',800:'#AA0003',700:'#D91619',600:'#FF3336',500:'#FF4D50',400:'#FF666A',300:'#FF8083',200:'#FF999D',100:'#FFB3B6',50:'#FFCCCE' } },
                fontFamily: { main: ['"Noto Nastaliq Urdu"','serif'], secondary: ['"IBM Plex Sans"','sans-serif'] }
            }}
        }
    </script>
    <style>
        body { font-family: 'IBM Plex Sans', sans-serif; }
        h1,h2,h3,h4,h5,h6 { font-family: 'Noto Nastaliq Urdu', serif; }
        .trash-row { transition: all 0.2s ease; }
        .trash-row:hover { transform: translateY(-2px); }
    </style>
</head>
<body class="bg-gray-100">

    <?php $active_page = 'trash'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">

        <header class="bg-white shadow-sm sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="flex items-center gap-3">
                        <button id="burgerBtn" class="lg:hidden flex flex-col justify-center items-center w-10 h-10 rounded-lg hover:bg-gray-100 transition-colors flex-shrink-0" aria-label="Toggle menu">
                            <span class="block w-5 h-0.5 bg-gray-700 mb-1 rounded"></span>
                            <span class="block w-5 h-0.5 bg-gray-700 mb-1 rounded"></span>
                            <span class="block w-5 h-0.5 bg-gray-700 rounded"></span>
                        </button>
                        <div>
                            <h2 class="text-2xl font-bold text-gray-800 font-main mb-2">Trash</h2>
                            <p class="text-sm text-gray-600 font-secondary">Deleted messages and released documents</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <?php if (!empty($trashed) || !empty($trashed_releases)): ?>
                        <button id="emptyTrashBtn" class="px-4 py-2 bg-crimson-50 text-crimson-700 rounded-lg text-sm font-semibold hover:bg-crimson-100 transition font-secondary">
                            Empty All Trash
                        </button>
                        <?php endif; ?>
                        <div class="hidden sm:block text-right">
                            <p class="font-semibold"><?= htmlspecialchars($user_email ?: 'Guest User') ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($user_role_display) ?></p>
                        </div>
                        <div class="w-10 h-10 bg-crimson-700 rounded-full flex items-center justify-center text-white font-bold">
                            <?= htmlspecialchars($user_initials) ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="px-4 sm:px-6 lg:px-8 py-8 space-y-8">
            
            <!-- SECTION 1: TRASHED INBOX -->
            <section class="bg-white rounded-2xl shadow p-6">
                <div class="mb-6">
                    <h3 class="text-xl font-bold text-gray-800 font-main">Trashed Inbox Messages</h3>
                    <p class="text-sm text-gray-500 font-secondary">Messages received that you moved to trash.</p>
                </div>

                <?php if (!empty($trashed)): ?>
                <div class="overflow-x-auto">
                    <div class="grid grid-cols-12 text-xs text-gray-400 uppercase px-4 py-2 border-b font-secondary">
                        <div class="col-span-3">Sender</div>
                        <div class="col-span-5">Subject</div>
                        <div class="col-span-2">Deleted On</div>
                        <div class="col-span-2 text-right">Actions</div>
                    </div>
                    <?php foreach ($trashed as $doc): ?>
                    <div class="trash-row grid grid-cols-12 items-center p-4 mt-2 bg-gray-50 rounded-lg border border-transparent hover:border-gray-200 transition">
                        <div class="col-span-3 text-sm truncate font-secondary"><?= htmlspecialchars($doc['sender_email']) ?></div>
                        <div class="col-span-5 text-sm truncate text-gray-500 line-through decoration-gray-300 font-secondary"><?= htmlspecialchars($doc['subject']) ?></div>
                        <div class="col-span-2 text-xs text-gray-400 font-secondary"><?= date('M d, Y', strtotime($doc['deleted_at'])) ?></div>
                        <div class="col-span-2 flex justify-end gap-2">
                            <button onclick="restoreMessage(<?= $doc['recipient_id'] ?>, this)" class="px-3 py-1.5 text-xs font-semibold bg-green-50 text-green-700 hover:bg-green-100 rounded-lg transition font-secondary">Restore</button>
                            <button onclick="deleteForever(<?= $doc['recipient_id'] ?>, this)" class="px-3 py-1.5 text-xs font-semibold bg-crimson-50 text-crimson-700 hover:bg-crimson-100 rounded-lg transition font-secondary">Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="py-10 text-center text-gray-400 border-2 border-dashed border-gray-100 rounded-xl font-secondary">No trashed inbox messages.</div>
                <?php endif; ?>
            </section>

            <!-- SECTION 2: TRASHED RELEASES -->
            <section class="bg-white rounded-2xl shadow p-6">
                <div class="mb-6">
                    <h3 class="text-xl font-bold text-gray-800 font-main">Trashed Released Documents</h3>
                    <p class="text-sm text-gray-500 font-secondary">Documents you authored/released that were deleted.</p>
                </div>

                <?php if (!empty($trashed_releases)): ?>
                <div class="overflow-x-auto">
                    <div class="grid grid-cols-12 text-xs text-gray-400 uppercase px-4 py-2 border-b font-secondary">
                        <div class="col-span-3">Type</div>
                        <div class="col-span-5">Subject</div>
                        <div class="col-span-2">Deleted On</div>
                        <div class="col-span-2 text-right">Actions</div>
                    </div>
                    <?php foreach ($trashed_releases as $rdoc): ?>
                    <div class="trash-row grid grid-cols-12 items-center p-4 mt-2 bg-gray-50 rounded-lg border border-transparent hover:border-gray-200 transition">
                        <div class="col-span-3 text-xs font-bold text-crimson-700 font-secondary"><?= $rdoc['document_type'] ?></div>
                        <div class="col-span-5 text-sm truncate text-gray-500 line-through decoration-gray-300 font-secondary"><?= htmlspecialchars($rdoc['subject']) ?></div>
                        <div class="col-span-2 text-xs text-gray-400 font-secondary"><?= date('M d, Y', strtotime($rdoc['deleted_at'])) ?></div>
                        <div class="col-span-2 flex justify-end gap-2">
                            <button onclick="restoreRelease(<?= $rdoc['document_id'] ?>, '<?= addslashes($rdoc['document_type']) ?>', this)" class="px-3 py-1.5 text-xs font-semibold bg-green-50 text-green-700 hover:bg-green-100 rounded-lg transition font-secondary">Restore</button>
                            <button onclick="deleteReleaseForever(<?= $rdoc['document_id'] ?>, '<?= addslashes($rdoc['document_type']) ?>', this)" class="px-3 py-1.5 text-xs font-semibold bg-crimson-50 text-crimson-700 hover:bg-crimson-100 rounded-lg transition font-secondary">Delete</button>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="py-10 text-center text-gray-400 border-2 border-dashed border-gray-100 rounded-xl font-secondary">No trashed released documents.</div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <script>
        async function apiPost(data) {
            const fd = new FormData();
            for (const k in data) fd.append(k, data[k]);
            const res = await fetch('', { method: 'POST', body: fd });
            return res.json();
        }

        function removeRow(btn) {
            const row = btn.closest('.trash-row');
            row.style.opacity = '0';
            setTimeout(() => { 
                row.remove();
                if(document.querySelectorAll('.trash-row').length === 0) location.reload();
            }, 300);
        }

        async function restoreMessage(id, btn) {
            const res = await apiPost({ action: 'restore_message', recipient_id: id });
            if (res.success) removeRow(btn);
        }

        async function deleteForever(id, btn) {
            if (!confirm('Permanently delete this message?')) return;
            const res = await apiPost({ action: 'delete_forever', recipient_id: id });
            if (res.success) removeRow(btn);
        }

        async function restoreRelease(id, type, btn) {
            const res = await apiPost({ action: 'restore_release', document_id: id, document_type: type });
            if (res.success) removeRow(btn);
        }

        async function deleteReleaseForever(id, type, btn) {
            if (!confirm('Permanently delete this document?')) return;
            const res = await apiPost({ action: 'delete_release_forever', document_id: id, document_type: type });
            if (res.success) removeRow(btn);
        }

        document.getElementById('emptyTrashBtn')?.addEventListener('click', async () => {
            if (!confirm('Empty all trash items forever?')) return;
            const res = await apiPost({ action: 'empty_trash' });
            if (res.success) location.reload();
        });
    </script>
    <script src="../js/sidebar.js"></script>
</body>
</html>