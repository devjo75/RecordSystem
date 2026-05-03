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

$user_email = $_SESSION['user_email'] ?? '';
$user_id = $_SESSION['user_id'] ?? 0;
$user_initials = getInitialsFromEmail($user_email);
$user_role = $_SESSION['user_role'] ?? 'user';
$user_role_display = ucfirst($user_role);
$user_full_name = $_SESSION['full_name'] ?? $user_email;

// ── Ensure deleted_at column exists (run once, safe to repeat) ───────────────
try {
    $pdo->exec("ALTER TABLE document_recipients ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");
} catch (PDOException $e) { /* column may already exist */ }

$query = "
    SELECT 
        dr.id as recipient_id,
        dr.document_type,
        dr.document_id,
        dr.recipient_email,
        dr.recipient_name,
        dr.status,
        dr.confirmation_token,
        dr.feedback,
        dr.created_at as received_date,
        dr.sent_at,
        dr.received_at,
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
                WHEN dr.document_type = 'Memorandum Order' THEN m.concerned_faculty
                WHEN dr.document_type = 'Special Order'    THEN s.concerned_faculty
                WHEN dr.document_type = 'Travel Order'     THEN t.employee_name
            END,
            m.concerned_faculty
        ) as concerned_person,
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
        ) as resolved_type,
        df.file_path,
        df.original_name as file_name
    FROM document_recipients dr
    LEFT JOIN memorandum_orders m ON dr.document_id = m.id
        AND (dr.document_type = 'Memorandum Order' OR dr.document_type = '')
    LEFT JOIN special_orders s ON dr.document_id = s.id
        AND dr.document_type = 'Special Order'
    LEFT JOIN travel_orders t ON dr.document_id = t.id
        AND dr.document_type = 'Travel Order'
    LEFT JOIN document_files df
        ON df.document_id = dr.document_id
        AND df.document_type = LOWER(
            COALESCE(
                NULLIF(dr.document_type, ''),
                CASE WHEN m.id IS NOT NULL THEN 'Memorandum Order'
                     WHEN s.id IS NOT NULL THEN 'Special Order'
                     WHEN t.id IS NOT NULL THEN 'Travel Order'
                END
            )
        )
    WHERE dr.recipient_email = ?
      AND (dr.deleted_at IS NULL)
    ORDER BY dr.created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute([$user_email]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group documents by recipient_id
$grouped_docs = [];
foreach ($documents as $doc) {
    $key = $doc['recipient_id'];
    if (!isset($grouped_docs[$key])) {
        if (empty($doc['document_type'])) {
            $doc['document_type'] = $doc['resolved_type'] ?? 'Memorandum Order';
        }
        $grouped_docs[$key] = $doc;
        $grouped_docs[$key]['files'] = [];
    }
    if ($doc['file_path']) {
        $grouped_docs[$key]['files'][] = [
            'name'     => $doc['file_name'],
            'path'     => $doc['file_path'],
        ];
    }
}
$documents = array_values($grouped_docs);

$unread_count = count(array_filter($documents, fn($d) => in_array($d['status'], ['Pending', 'Sent'])));

// Count trashed messages for sidebar badge
$trashStmt = $pdo->prepare("SELECT COUNT(*) FROM document_recipients WHERE recipient_email = ? AND deleted_at IS NOT NULL");
$trashStmt->execute([$user_email]);
$trash_count = (int)$trashStmt->fetchColumn();

// ── AJAX: mark received ───────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {

    header('Content-Type: application/json');

    // ── Move to trash ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'trash_message') {
        $recipient_id = (int)($_POST['recipient_id'] ?? 0);
        if (!$recipient_id) { echo json_encode(['success'=>false,'message'=>'Invalid ID']); exit; }

        $stmt = $pdo->prepare("
            UPDATE document_recipients
               SET deleted_at = NOW()
             WHERE id = ? AND recipient_email = ? AND deleted_at IS NULL
        ");
        $stmt->execute([$recipient_id, $user_email]);

        echo json_encode($stmt->rowCount() > 0
            ? ['success'=>true,  'message'=>'Message moved to trash.']
            : ['success'=>false, 'message'=>'Message not found or already trashed.']
        );
        exit;
    }

    // ── Mark received ─────────────────────────────────────────────────────────
    if ($_POST['action'] === 'mark_received') {
        $recipient_id = $_POST['recipient_id'] ?? 0;
        $token        = $_POST['token']        ?? '';
        $feedback     = $_POST['feedback']     ?? '';

        try {
            $pdo->beginTransaction();
            $recipient_id_resolved = (int)$recipient_id;

            if ($token) {
                $stmt = $pdo->prepare("
                    UPDATE document_recipients 
                       SET status = 'Received', received_at = NOW(), feedback = ?
                     WHERE confirmation_token = ? AND status IN ('Pending', 'Sent')
                ");
                $stmt->execute([$feedback, $token]);

                if ($stmt->rowCount() > 0) {
                    $id_row = $pdo->prepare("SELECT id FROM document_recipients WHERE confirmation_token = ? LIMIT 1");
                    $id_row->execute([$token]);
                    $recipient_id_resolved = (int)($id_row->fetchColumn() ?: 0);
                }
            } else {
                $stmt = $pdo->prepare("
                    UPDATE document_recipients 
                       SET status = 'Received', received_at = NOW(), feedback = ?
                     WHERE id = ? AND recipient_email = ? AND status IN ('Pending', 'Sent')
                ");
                $stmt->execute([$feedback, $recipient_id, $user_email]);
            }

            if ($stmt->rowCount() > 0) {
                if ($recipient_id_resolved > 0) {
                    $pdo->prepare("
                        INSERT INTO document_history (document_type, document_id, document_number, action, action_by, action_details)
                        SELECT dr.document_type, dr.document_id, 
                               CASE 
                                   WHEN dr.document_type = 'Memorandum Order' THEN m.mo_number
                                   WHEN dr.document_type = 'Special Order'    THEN s.so_number
                                   WHEN dr.document_type = 'Travel Order'     THEN t.io_number
                               END,
                               'Received', ?, CONCAT('Document received by ', ?, IF(? != '', CONCAT(' with feedback: ', ?), ''))
                        FROM document_recipients dr
                        LEFT JOIN memorandum_orders m ON dr.document_type = 'Memorandum Order' AND dr.document_id = m.id
                        LEFT JOIN special_orders    s ON dr.document_type = 'Special Order'    AND dr.document_id = s.id
                        LEFT JOIN travel_orders     t ON dr.document_type = 'Travel Order'     AND dr.document_id = t.id
                        WHERE dr.id = ?
                    ")->execute([$user_id, $user_full_name, $feedback, $feedback, $recipient_id_resolved]);
                }

                $pdo->commit();
                echo json_encode(['success'=>true, 'message'=>'Document marked as received with acknowledgment']);
            } else {
                $pdo->rollBack();
                echo json_encode(['success'=>false, 'message'=>'Document already received or not found']);
            }
        } catch (PDOException $e) {
            $pdo->rollBack();
            echo json_encode(['success'=>false, 'message'=>'Database error: '.$e->getMessage()]);
        }
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Inbox — WMSU Document Management</title>
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
                        'main': ['"Noto Nastaliq Urdu"','serif'],
                        'secondary': ['"IBM Plex Sans"','sans-serif'],
                    }
                }
            }
        }
    </script>
    <style>
        body { font-family:'IBM Plex Sans',sans-serif; }
        h1,h2,h3,h4,h5,h6 { font-family:'Noto Nastaliq Urdu',serif; }
        .inbox-row { transition: all 0.2s ease; }
        .inbox-row:hover { transform: translateY(-2px); }
        .status-badge { transition: all 0.2s ease; }

    </style>
</head>
<body class="bg-gray-100">

    <?php $active_page = 'inbox'; $inbox_unread = $unread_count; include __DIR__ . '/../sidebar/sidebar.php'; ?>

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
                            <h2 class="text-xl sm:text-2xl font-bold text-gray-800 font-main truncate">Inbox</h2>
                            <p class="hidden sm:block text-sm text-gray-600 mt-1 font-secondary">Manage incoming document notifications</p>
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

        <!-- Page Content -->
        <div class="px-4 sm:px-6 lg:px-8 py-8">
            <div class="bg-white rounded-2xl shadow p-6">

                <!-- Controls Bar -->
                <div class="flex flex-wrap items-center gap-2 mb-6">
                    <div class="flex-1 min-w-0">
                        <h3 class="text-xl font-bold text-gray-800 font-main">All Messages</h3>
                        <p class="text-sm text-gray-500 font-secondary mt-1">Your document inbox</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                        <div class="relative flex-1 sm:flex-none sm:w-64 min-w-[160px]">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
                            </svg>
                            <input id="inboxSearch" type="text" placeholder="Search sender, subject, type..."
                                class="w-full pl-9 pr-4 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary">
                        </div>

                        <select id="statusFilter" class="px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary bg-white text-gray-700">
                            <option value="">All Status</option>
                            <option value="Pending">Pending</option>
                            <option value="Sent">Sent (Unread)</option>
                            <option value="Received">Received</option>
                        </select>

                        <select id="typeFilter" class="px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary bg-white text-gray-700">
                            <option value="">All Types</option>
                            <option value="Memorandum Order">Memorandum Order</option>
                            <option value="Special Order">Special Order</option>
                            <option value="Travel Order">Travel Order</option>
                        </select>

                        <select id="sortSelect" class="px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary bg-white text-gray-700">
                            <option value="date-desc">Newest First</option>
                            <option value="date-asc">Oldest First</option>
                            <option value="sender-asc">Sender A–Z</option>
                            <option value="sender-desc">Sender Z–A</option>
                        </select>

                        <span id="rowCount" class="text-xs text-gray-400 font-secondary whitespace-nowrap"></span>
                        <button id="resetFilters" class="text-xs text-crimson-700 hover:text-crimson-900 font-secondary underline hidden">Reset</button>
                    </div>
                </div>

                <!-- Table Header -->
                <div class="overflow-x-auto">
                    <div class="min-w-[800px]">
                        <div class="grid grid-cols-12 text-xs text-gray-400 uppercase px-4 py-2 border-b font-secondary tracking-wider">
                            <div class="col-span-3">Sender</div>
                            <div class="col-span-3">Document / Subject</div>
                            <div class="col-span-2">Type</div>
                            <div class="col-span-2">Document #</div>
                            <div class="col-span-2 text-right">Received</div>
                        </div>

                        <!-- Rows Container -->
                        <div id="inboxRows">
                            <?php if (empty($documents)): ?>
                                <div class="text-center py-12 text-gray-400 font-secondary text-sm" id="emptyState">
                                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                                    </svg>
                                    No documents found in your inbox.
                                </div>
                            <?php else: ?>
                                <?php foreach ($documents as $doc): 
                                    $initials = getInitialsFromEmail($doc['sender_email'] ?? 'Unknown');
                                    $isUnread = in_array($doc['status'], ['Pending', 'Sent']);
                                    $statusColor = $doc['status'] === 'Pending' ? 'bg-amber-100 text-amber-700' 
                                                 : ($doc['status'] === 'Sent'    ? 'bg-blue-100 text-blue-700' 
                                                                                 : 'bg-green-100 text-green-700');
                                    $statusIcon = $doc['status'] === 'Received' ? '✓' : '●';
                                    $rowClass = $isUnread ? 'border-crimson-200 bg-crimson-50' : 'bg-gray-50 border-transparent';
                                ?>
                                <div class="inbox-row grid grid-cols-12 items-center rounded-lg p-4 mt-3 hover:shadow-md transition cursor-pointer border-2 <?= $rowClass ?> hover:border-crimson-300"
                                     data-id="<?= $doc['recipient_id'] ?>"
                                     data-token="<?= htmlspecialchars($doc['confirmation_token'] ?? '') ?>"
                                     data-sender="<?= htmlspecialchars($doc['sender_email'] ?? 'Unknown') ?>"
                                     data-subject="<?= htmlspecialchars($doc['subject'] ?? 'No Subject') ?>"
                                     data-type="<?= htmlspecialchars($doc['document_type'] ?? 'Unknown') ?>"
                                     data-status="<?= htmlspecialchars($doc['status']) ?>"
                                     data-date="<?= htmlspecialchars($doc['received_date'] ?? '') ?>"
                                     data-number="<?= htmlspecialchars($doc['document_number'] ?? 'N/A') ?>"
                                     data-concerned="<?= htmlspecialchars($doc['concerned_person'] ?? 'N/A') ?>"
                                     data-issued="<?= htmlspecialchars($doc['date_issued'] ?? '') ?>"
                                     data-feedback="<?= htmlspecialchars($doc['feedback'] ?? '') ?>"
                                     data-files="<?= htmlspecialchars(json_encode($doc['files'])) ?>"
                                     onclick="viewDocument(this)">
                                    
                                    <div class="col-span-3 flex items-center gap-3">
                                        <input type="checkbox" 
                                            class="doc-checkbox w-4 h-4 text-crimson-700 border-gray-300 rounded pointer-events-none"
                                            <?= $doc['status'] === 'Received' ? 'checked' : '' ?>
                                            tabindex="-1"
                                            aria-label="Acknowledgment status">
                                        <div class="relative">
                                            <div class="w-10 h-10 rounded-full bg-crimson-500 text-white flex items-center justify-center font-bold font-secondary text-sm">
                                                <?= htmlspecialchars(substr($initials, 0, 2)) ?>
                                            </div>
                                            <?php if ($isUnread): ?>
                                            <span class="absolute -top-0.5 -right-0.5 w-3 h-3 bg-crimson-700 rounded-full border-2 border-white"></span>
                                            <?php endif; ?>
                                        </div>
                                        <span class="<?= $isUnread ? 'font-bold' : 'font-semibold' ?> text-gray-800 font-secondary truncate">
                                            <?= htmlspecialchars($doc['sender_email'] ?? 'Unknown') ?>
                                        </span>
                                    </div>
                                    
                                    <div class="col-span-3 <?= $isUnread ? 'font-bold' : 'font-semibold' ?> text-gray-700 font-secondary truncate pr-2">
                                        <?= htmlspecialchars(substr($doc['subject'] ?? 'No Subject', 0, 60)) ?>
                                    </div>
                                    
                                    <div class="col-span-2">
                                        <span class="inline-flex items-center gap-1 px-2 py-1 bg-gray-100 text-gray-600 rounded text-xs font-semibold">
                                            <?= htmlspecialchars($doc['document_type'] ?? 'Unknown') ?>
                                        </span>
                                    </div>
                                    
                                    <div class="col-span-2">
                                        <span class="text-xs text-gray-500 font-mono">
                                            <?= htmlspecialchars($doc['document_number'] ?? 'N/A') ?>
                                        </span>
                                    </div>
                                    
                                    <div class="col-span-2 flex items-center justify-end gap-2">
                                        <span class="status-badge <?= $statusColor ?> px-2 py-1 rounded-full text-xs font-semibold inline-flex items-center gap-1">
                                            <span><?= $statusIcon ?></span>
                                            <span><?= htmlspecialchars($doc['status']) ?></span>
                                        </span>
                                        <span class="text-xs text-gray-400 font-secondary">
                                            <?= date('M d, Y', strtotime($doc['received_date'] ?? 'now')) ?>
                                        </span>
                                        <!-- Delete button: only available after message is acknowledged -->
                                        <?php if ($doc['status'] === 'Received'): ?>
                                            <button onclick="openDeleteModal(event, <?= $doc['recipient_id'] ?>, '<?= htmlspecialchars(addslashes($doc['sender_email'] ?? 'Unknown'), ENT_QUOTES) ?>')"
                                                    class="px-3 py-1.5 text-xs font-semibold bg-crimson-50 text-crimson-700 hover:bg-crimson-100 rounded-lg transition font-secondary">Delete</button>
                                        <?php else: ?>
                                            <button disabled title="Acknowledge the document first before deleting"
                                                    class="px-3 py-1.5 text-xs font-semibold bg-gray-100 text-gray-300 rounded-lg font-secondary cursor-not-allowed">Delete</button>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Empty State for filters -->
                        <div id="inboxEmpty" class="hidden text-center py-12 text-gray-400 font-secondary text-sm">
                            <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                            </svg>
                            No messages match your filters.
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </main>

    <!-- Delete (Trash) Confirmation Modal -->
    <div id="deleteModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
        <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
            <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
                <h3 class="text-lg font-bold text-gray-800 font-main">Move to Trash</h3>
                <button onclick="closeDeleteModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
            </div>
            <div class="px-6 py-6">
                <div class="flex items-start gap-4">
                    <div class="w-11 h-11 rounded-full bg-crimson-50 flex items-center justify-center flex-shrink-0">
                        <svg class="w-5 h-5 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                        </svg>
                    </div>
                    <div>
                        <p class="text-sm text-gray-700 font-secondary">Are you sure you want to delete the message from</p>
                        <p id="deleteModalSender" class="text-sm font-bold text-gray-900 font-secondary mt-0.5 truncate"></p>
                        <p class="text-xs text-gray-400 font-secondary mt-2">This message will be moved to Trash. You can restore or permanently delete it there.</p>
                    </div>
                </div>
            </div>
            <div class="px-6 py-4 bg-gray-50 flex items-center justify-end gap-3">
                <button onclick="closeDeleteModal()"
                    class="px-4 py-2 text-sm font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-100 transition font-secondary">
                    Cancel
                </button>
                <button id="deleteModalConfirmBtn"
                    class="px-4 py-2 text-sm font-semibold bg-crimson-700 text-white rounded-lg hover:bg-crimson-800 transition font-secondary">
                    Move to Trash
                </button>
            </div>
        </div>
    </div>

    <!-- Document Modal (unchanged) -->
    <div id="documentModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
        <div class="bg-white w-full max-w-3xl rounded-2xl shadow-2xl overflow-hidden relative max-h-[90vh] flex flex-col">
            <div class="bg-crimson-700 text-white px-6 py-5 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-3">
                    <div id="modalAvatar" class="w-12 h-12 rounded-full bg-white bg-opacity-20 text-white flex items-center justify-center font-bold font-secondary text-xl">S</div>
                    <div>
                        <h2 id="modalSender" class="font-bold text-lg font-main">Sender</h2>
                        <p id="modalDocumentNumber" class="text-sm opacity-90 font-secondary"></p>
                    </div>
                </div>
                <button onclick="closeModal()" class="text-white hover:opacity-75 text-2xl leading-none">&times;</button>
            </div>
            
            <div class="p-6 overflow-y-auto flex-1">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-6">
                    <div>
                        <p class="text-xs text-gray-500 font-secondary">Document Type</p>
                        <p id="modalType" class="text-sm font-semibold text-gray-800">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-secondary">Date Issued</p>
                        <p id="modalDateIssued" class="text-sm font-semibold text-gray-800">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-secondary">Concerned Person</p>
                        <p id="modalConcerned" class="text-sm font-semibold text-gray-800">-</p>
                    </div>
                    <div>
                        <p class="text-xs text-gray-500 font-secondary">Received On</p>
                        <p id="modalReceivedDate" class="text-sm font-semibold text-gray-800">-</p>
                    </div>
                </div>
                
                <div class="mb-6">
                    <p class="text-xs text-gray-500 font-secondary mb-1">Subject / Message</p>
                    <div id="modalSubject" class="border-2 border-gray-200 rounded-lg p-4 bg-gray-50 text-gray-700 font-secondary text-sm min-h-[80px]"></div>
                </div>
                
                <div class="mb-6">
                    <p class="text-xs text-gray-500 font-secondary mb-2">Acknowledgement / Feedback</p>
                    <textarea id="modalFeedback" rows="4" placeholder="Optional: Add your acknowledgment notes or feedback about this document..." 
                        class="w-full px-4 py-2 border-2 border-gray-200 rounded-lg focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary text-sm resize-none"></textarea>
                    <p class="text-xs text-gray-400 mt-1 font-secondary">Your feedback will be recorded along with your acknowledgment.</p>
                </div>
                
                <div class="mb-6" id="filesSection">
                    <p class="text-xs text-gray-500 font-secondary mb-2">Attached Files</p>
                    <div id="modalFiles" class="space-y-2"></div>
                    <p id="noFilesMsg" class="text-xs text-gray-400 hidden font-secondary">No files attached.</p>
                </div>
            </div>
            
            <div class="p-6 border-t border-gray-200 bg-gray-50 flex-shrink-0">
                <button id="markReceivedBtn" class="w-full bg-crimson-700 text-white py-3 rounded-lg hover:bg-crimson-800 font-bold font-secondary transition duration-200 transform hover:scale-[1.01] active:scale-[0.99]">
                    ✓ Mark as Received
                </button>
            </div>
        </div>
    </div>

    <script>
        const appBase = <?php
            $_ib_root = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
            $_ib_doc  = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
            echo json_encode(str_replace($_ib_doc, '', $_ib_root) . '/');
        ?>;
        let currentDocument = null;

        // ── Delete modal ──────────────────────────────────────────────────────
        let _deleteTargetId  = null;

        function openDeleteModal(event, recipientId, senderEmail) {
            event.stopPropagation(); // don't open the document modal
            _deleteTargetId = recipientId;
            document.getElementById('deleteModalSender').textContent = senderEmail;
            const modal = document.getElementById('deleteModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }

        function closeDeleteModal() {
            const modal = document.getElementById('deleteModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            _deleteTargetId = null;
        }

        document.getElementById('deleteModal').addEventListener('click', function(e) {
            if (e.target === this) closeDeleteModal();
        });

        document.getElementById('deleteModalConfirmBtn').addEventListener('click', async function() {
            if (!_deleteTargetId) return;

            this.disabled    = true;
            this.textContent = 'Moving…';

            // Capture ID now — closeDeleteModal() will null it
            const targetId = _deleteTargetId;

            try {
                const fd = new FormData();
                fd.append('action', 'trash_message');
                fd.append('recipient_id', targetId);

                const res  = await fetch('', { method: 'POST', body: fd });
                const data = await res.json();

                if (data.success) {
                    closeDeleteModal();

                    // Animate row out immediately
                    const row = document.querySelector(`.inbox-row[data-id="${targetId}"]`);
                    if (row) {
                        row.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                        row.style.opacity    = '0';
                        row.style.transform  = 'translateX(40px)';
                        setTimeout(() => {
                            row.remove();
                            filterAndSortFn();

                            // Bump trash badge
                            const badge = document.querySelector('a[href="trash.php"] span.bg-gray-400');
                            if (badge) {
                                badge.textContent = parseInt(badge.textContent || '0') + 1;
                            } else {
                                const trashLink = document.querySelector('a[href="trash.php"]');
                                if (trashLink) {
                                    const span = document.createElement('span');
                                    span.className = 'absolute -top-1 -right-1 w-5 h-5 bg-gray-400 text-white text-xs rounded-full flex items-center justify-center font-bold';
                                    span.textContent = '1';
                                    trashLink.style.position = 'relative';
                                    trashLink.appendChild(span);
                                }
                            }
                        }, 320);
                    }
                } else {
                    closeDeleteModal();
                    Swal.fire({ icon: 'error', title: 'Error', text: data.message, confirmButtonColor: '#AA0003' });
                }
            } catch (e) {
                closeDeleteModal();
                Swal.fire({ icon: 'error', title: 'Error', text: 'Could not move message to trash.', confirmButtonColor: '#AA0003' });
            }

            this.disabled    = false;
            this.textContent = 'Move to Trash';
        });

        // ── View document modal ───────────────────────────────────────────────
        function viewDocument(element) {
            currentDocument = {
                id:        element.dataset.id,
                token:     element.dataset.token,
                sender:    element.dataset.sender,
                subject:   element.dataset.subject,
                type:      element.dataset.type,
                status:    element.dataset.status,
                date:      element.dataset.date,
                number:    element.dataset.number,
                concerned: element.dataset.concerned,
                issued:    element.dataset.issued,
                feedback:  element.dataset.feedback || '',
                files:     JSON.parse(element.dataset.files || '[]')
            };
            
            document.getElementById('modalSender').innerText         = currentDocument.sender;
            document.getElementById('modalDocumentNumber').innerText = currentDocument.number;
            document.getElementById('modalType').innerText           = currentDocument.type;
            document.getElementById('modalSubject').innerText        = currentDocument.subject;
            document.getElementById('modalConcerned').innerText      = currentDocument.concerned;
            document.getElementById('modalDateIssued').innerText     = currentDocument.issued ? new Date(currentDocument.issued).toLocaleDateString() : 'N/A';
            document.getElementById('modalReceivedDate').innerText   = currentDocument.date   ? new Date(currentDocument.date).toLocaleDateString()   : 'N/A';
            
            const feedbackTextarea = document.getElementById('modalFeedback');
            if (currentDocument.status === 'Received' && currentDocument.feedback) {
                feedbackTextarea.value    = currentDocument.feedback;
                feedbackTextarea.disabled = true;
                feedbackTextarea.classList.add('bg-gray-100');
            } else {
                feedbackTextarea.value    = '';
                feedbackTextarea.disabled = false;
                feedbackTextarea.classList.remove('bg-gray-100');
            }
            
            const filesContainer = document.getElementById('modalFiles');
            const noFilesMsg     = document.getElementById('noFilesMsg');
            filesContainer.innerHTML = '';


            if (currentDocument.files && currentDocument.files.length > 0) {
                noFilesMsg.classList.add('hidden');
                currentDocument.files.forEach(f => {
                    const a = document.createElement('a');
                    let filePath = f.path.replace(/^\/+/, '');
                    a.href   = appBase + filePath;
                    a.target = '_blank';
                    a.className = 'flex items-center gap-3 p-3 bg-gray-50 border border-gray-200 rounded-lg hover:bg-crimson-50 hover:border-crimson-200 transition';
                    a.innerHTML = `
                        <svg class="w-5 h-5 text-crimson-700 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                        </svg>
                        <span class="text-sm font-secondary text-gray-700 truncate">${f.name || 'Attached file'}</span>
                        <svg class="w-4 h-4 text-gray-400 ml-auto flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 6H6a2 2 0 00-2 2v10a2 2 0 002 2h10a2 2 0 002-2v-4M14 4h6m0 0v6m0-6L10 14"/>
                        </svg>`;
                    filesContainer.appendChild(a);
                });
            } else {
                noFilesMsg.classList.remove('hidden');
            }
            
            let senderName = currentDocument.sender.split('@')[0];
            document.getElementById('modalAvatar').innerText = senderName.substring(0, 2).toUpperCase();
            
            const markBtn = document.getElementById('markReceivedBtn');
            if (currentDocument.status === 'Received') {
                markBtn.innerHTML = '✓ Already Received';
                markBtn.disabled  = true;
                markBtn.classList.remove('bg-crimson-700', 'hover:bg-crimson-800');
                markBtn.classList.add('bg-gray-400', 'cursor-not-allowed');
            } else {
                markBtn.innerHTML = '✓ Mark as Received';
                markBtn.disabled  = false;
                markBtn.classList.add('bg-crimson-700', 'hover:bg-crimson-800');
                markBtn.classList.remove('bg-gray-400', 'cursor-not-allowed');
            }
            
            const modal = document.getElementById('documentModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        
        document.getElementById('markReceivedBtn').addEventListener('click', async function() {
            if (!currentDocument || currentDocument.status === 'Received') return;
            const feedback = document.getElementById('modalFeedback').value;
            try {
                const formData = new FormData();
                formData.append('action', 'mark_received');
                formData.append('recipient_id', currentDocument.id);
                formData.append('token', currentDocument.token);
                formData.append('feedback', feedback);
                
                const response = await fetch('', { method: 'POST', body: formData });
                const result   = await response.json();
                
                if (result.success) {
                    const activeRow = document.querySelector(`.inbox-row[data-id="${currentDocument.id}"]`);
                    if (activeRow) {
                        const cb = activeRow.querySelector('.doc-checkbox');
                        if (cb) cb.checked = true;
                    }
                    Swal.fire({ icon: 'success', title: 'Document Received', text: feedback ? 'Document marked as received with your feedback.' : 'Document marked as received.', confirmButtonColor: '#AA0003' })
                        .then(() => location.reload());
                } else {
                    Swal.fire({ icon: 'error', title: 'Error', text: result.message, confirmButtonColor: '#AA0003' });
                }
            } catch (error) {
                Swal.fire({ icon: 'error', title: 'Error', text: 'Failed to mark document as received', confirmButtonColor: '#AA0003' });
            }
        });
        
        function closeModal() {
            const modal = document.getElementById('documentModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            currentDocument = null;
        }
        
        document.getElementById('documentModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
        
        // ── Search, Filter & Sort ─────────────────────────────────────────────
        let filterAndSortFn = () => {};

        (function() {
            const searchEl = document.getElementById('inboxSearch');
            const statusEl = document.getElementById('statusFilter');
            const typeEl   = document.getElementById('typeFilter');
            const sortEl   = document.getElementById('sortSelect');
            const countEl  = document.getElementById('rowCount');
            const emptyEl  = document.getElementById('inboxEmpty');
            const resetBtn = document.getElementById('resetFilters');
            const container = document.getElementById('inboxRows');
            const originalEmptyState = document.getElementById('emptyState');
            
            function filterAndSort() {
                const q  = searchEl.value.toLowerCase().trim();
                const sf = statusEl.value;
                const tf = typeEl.value;
                const so = sortEl.value;
                
                const rows = Array.from(container.querySelectorAll('.inbox-row'));
                if (rows.length === 0) { countEl.textContent = '0 messages'; return; }
                
                rows.forEach(row => {
                    const matchQ  = !q  || row.dataset.sender.toLowerCase().includes(q)
                                         || row.dataset.subject.toLowerCase().includes(q)
                                         || row.dataset.type.toLowerCase().includes(q)
                                         || (row.dataset.number && row.dataset.number.toLowerCase().includes(q));
                    const matchSF = !sf || row.dataset.status === sf;
                    const matchTF = !tf || row.dataset.type   === tf;
                    row.style.display = (matchQ && matchSF && matchTF) ? '' : 'none';
                });
                
                const visible = rows.filter(r => r.style.display !== 'none');
                visible.sort((a, b) => {
                    if (so === 'date-desc')   return new Date(b.dataset.date) - new Date(a.dataset.date);
                    if (so === 'date-asc')    return new Date(a.dataset.date) - new Date(b.dataset.date);
                    if (so === 'sender-asc')  return a.dataset.sender.localeCompare(b.dataset.sender);
                    if (so === 'sender-desc') return b.dataset.sender.localeCompare(a.dataset.sender);
                    return 0;
                });
                visible.forEach(r => container.appendChild(r));
                
                countEl.textContent = visible.length + ' message' + (visible.length !== 1 ? 's' : '');
                if (visible.length === 0) {
                    emptyEl.classList.remove('hidden');
                    if (originalEmptyState) originalEmptyState.classList.add('hidden');
                } else {
                    emptyEl.classList.add('hidden');
                    if (originalEmptyState) originalEmptyState.classList.remove('hidden');
                }
                resetBtn.classList.toggle('hidden', !(q || sf || tf || so !== 'date-desc'));
            }

            filterAndSortFn = filterAndSort; // expose for trash button

            function resetAll() {
                searchEl.value = ''; statusEl.value = ''; typeEl.value = ''; sortEl.value = 'date-desc';
                filterAndSort();
            }
            
            if (searchEl && statusEl && typeEl && sortEl) {
                searchEl.addEventListener('input',  filterAndSort);
                statusEl.addEventListener('change', filterAndSort);
                typeEl.addEventListener('change',   filterAndSort);
                sortEl.addEventListener('change',   filterAndSort);
                if (resetBtn) resetBtn.addEventListener('click', resetAll);
                filterAndSort();
            }
        })();
    </script>
    <script src="../js/sidebar.js"></script>

</body>
</html>