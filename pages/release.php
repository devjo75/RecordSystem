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
$user_id           = $_SESSION['user_id'] ?? 0;
$user_initials     = getInitialsFromEmail($user_email);
$user_role         = $_SESSION['user_role']  ?? 'user';
$user_role_display = ucfirst($user_role);
$user_full_name    = $_SESSION['full_name'] ?? $user_email;

// Fetch all released documents by current user (or all if Admin)
$isAdmin = ($user_role === 'Admin');

$query = "
    SELECT 
        'Memorandum Order' as document_type,
        m.id as document_id,
        m.mo_number as document_number,
        m.subject,
        m.concerned_faculty as concerned_person,
        m.sender_email,
        m.date_issued,
        m.created_at,
        m.status,
        COUNT(dr.id) as total_recipients,
        SUM(CASE WHEN dr.status = 'Received' THEN 1 ELSE 0 END) as received_count,
        GROUP_CONCAT(DISTINCT dr.recipient_name) as recipients
    FROM memorandum_orders m
    LEFT JOIN document_recipients dr ON dr.document_id = m.id 
        AND dr.document_type = 'Memorandum Order'
    " . ($isAdmin ? "WHERE m.deleted_at IS NULL" : "WHERE m.created_by = ? AND m.deleted_at IS NULL") . "
    GROUP BY m.id

    UNION ALL

    SELECT 
        'Special Order' as document_type,
        s.id as document_id,
        s.so_number as document_number,
        s.subject,
        s.concerned_faculty as concerned_person,
        s.sender_email,
        s.date_issued,
        s.created_at,
        s.status,
        COUNT(dr.id) as total_recipients,
        SUM(CASE WHEN dr.status = 'Received' THEN 1 ELSE 0 END) as received_count,
        GROUP_CONCAT(DISTINCT dr.recipient_name) as recipients
    FROM special_orders s
    LEFT JOIN document_recipients dr ON dr.document_id = s.id 
        AND dr.document_type = 'Special Order'
    " . ($isAdmin ? "WHERE s.deleted_at IS NULL" : "WHERE s.created_by = ? AND s.deleted_at IS NULL") . "
    GROUP BY s.id

    UNION ALL

    SELECT 
        'Travel Order' as document_type,
        t.id as document_id,
        t.io_number as document_number,
        t.subject,
        t.employee_name as concerned_person,
        t.sender_email,
        t.date_issued,
        t.created_at,
        t.status,
        COUNT(dr.id) as total_recipients,
        SUM(CASE WHEN dr.status = 'Received' THEN 1 ELSE 0 END) as received_count,
        GROUP_CONCAT(DISTINCT dr.recipient_name) as recipients
    FROM travel_orders t
    LEFT JOIN document_recipients dr ON dr.document_id = t.id 
        AND dr.document_type = 'Travel Order'
    " . ($isAdmin ? "WHERE t.deleted_at IS NULL" : "WHERE t.created_by = ? AND t.deleted_at IS NULL") . "
    GROUP BY t.id

    ORDER BY created_at DESC
";

$stmt = $pdo->prepare($query);
$stmt->execute($isAdmin ? [] : [$user_id, $user_id, $user_id]);
$documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Function to get recipients with feedback for a specific document
function getRecipientsWithFeedback($pdo, $document_type, $document_id) {
    $stmt = $pdo->prepare("
        SELECT 
            dr.recipient_name,
            dr.recipient_email,
            dr.status,
            dr.feedback,
            dr.received_at,
            DATE_FORMAT(dr.received_at, '%M %d, %Y at %h:%i %p') as formatted_received_at
        FROM document_recipients dr
        WHERE dr.document_type = ? 
            AND dr.document_id = ?
        ORDER BY 
            CASE WHEN dr.status = 'Received' THEN 0 ELSE 1 END,
            dr.received_at DESC
    ");
    $stmt->execute([$document_type, $document_id]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Ensure deleted_at columns exist (run once)
try {
    $pdo->exec("ALTER TABLE memorandum_orders ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE special_orders ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");
    $pdo->exec("ALTER TABLE travel_orders ADD COLUMN IF NOT EXISTS deleted_at DATETIME DEFAULT NULL");
} catch (PDOException $e) { /* ignore, column may exist */ }

// ── AJAX: trash a released document (only sender/creator may do this) ────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');

    if ($_POST['action'] === 'trash_release') {
        $doc_type = $_POST['document_type'] ?? '';
        $doc_id   = (int)($_POST['document_id'] ?? 0);

        if (!$doc_id || !in_array($doc_type, ['Memorandum Order', 'Special Order', 'Travel Order'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid request.']);
            exit;
        }

        // Map type → table and creator column
        $tableMap = [
            'Memorandum Order' => ['memorandum_orders', 'created_by'],
            'Special Order'    => ['special_orders',    'created_by'],
            'Travel Order'     => ['travel_orders',     'created_by'],
        ];
        [$table, $creatorCol] = $tableMap[$doc_type];

        // Verify the current user is the creator
        $check = $pdo->prepare("SELECT id FROM `$table` WHERE id = ? AND $creatorCol = ? AND deleted_at IS NULL");
        $check->execute([$doc_id, $user_id]);

        if (!$check->fetch()) {
            echo json_encode(['success' => false, 'message' => 'Document not found or you are not the sender.']);
            exit;
        }

        $stmt = $pdo->prepare("UPDATE `$table` SET deleted_at = NOW() WHERE id = ? AND $creatorCol = ? AND deleted_at IS NULL");
        $stmt->execute([$doc_id, $user_id]);

        echo json_encode($stmt->rowCount() > 0
            ? ['success' => true,  'message' => 'Document moved to trash.']
            : ['success' => false, 'message' => 'Could not move document to trash.']
        );
        exit;
    }

    echo json_encode(['success' => false, 'message' => 'Unknown action.']);
    exit;
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Release Monitoring — WMSU Document Management</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;500;600;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script>
        tailwind.config = {
            theme: { extend: {
                colors: { crimson: { 950:'#4D0001',900:'#800002',800:'#AA0003',700:'#D91619',600:'#FF3336',500:'#FF4D50' } },
                fontFamily: { main: ['"Noto Nastaliq Urdu"','serif'], secondary: ['"IBM Plex Sans"','sans-serif'] }
            }}
        }
    </script>
    <style>
        body { font-family:'IBM Plex Sans',sans-serif; }
        h1,h2,h3,h4,h5,h6 { font-family:'Noto Nastaliq Urdu',serif; }
        .release-card {
            transition: all 0.2s ease;
        }
        .release-card:hover {
            transform: translateY(-2px);
        }
    </style>
</head>
<body class="bg-gray-100">

    <?php $active_page = 'release'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

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
                            <h2 class="text-2xl font-bold text-gray-800 font-main mb-1">Release Monitoring</h2>
                            <p class="text-sm text-gray-600 font-secondary">Track who has acknowledged your documents</p>
                        </div>
                    </div>
                    <div class="flex items-center gap-3">
                        <div class="hidden sm:block text-right">
                            <p class="font-semibold"><?= htmlspecialchars($user_email) ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($user_role_display) ?></p>
                        </div>
                        <div class="w-10 h-10 bg-crimson-700 rounded-full flex items-center justify-center text-white font-bold">
                            <?= htmlspecialchars($user_initials) ?>
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="px-4 sm:px-6 lg:px-8 py-8">
            <div class="bg-white rounded-2xl shadow p-6">

                <!-- Controls Bar with Filters -->
                <div class="flex flex-wrap items-center gap-2 mb-6">
                    <div class="flex-1 min-w-0">
                        <h3 class="text-xl font-bold text-gray-800 font-main">Released Documents</h3>
                        <p class="text-sm text-gray-500 font-secondary mt-1">Monitor document acknowledgment status</p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 w-full sm:w-auto">
                        <div class="relative flex-1 sm:flex-none sm:w-64 min-w-[160px]">
                            <svg class="absolute left-3 top-1/2 -translate-y-1/2 w-4 h-4 text-gray-400" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-4.35-4.35M17 11A6 6 0 115 11a6 6 0 0112 0z"/>
                            </svg>
                            <input id="releaseSearch" type="text" placeholder="Search number, subject, person..."
                                class="w-full pl-9 pr-4 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary">
                        </div>

                        <select id="typeFilter" class="px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary bg-white text-gray-700">
                            <option value="">All Types</option>
                            <option value="Memorandum Order">Memorandum Order</option>
                            <option value="Special Order">Special Order</option>
                            <option value="Travel Order">Travel Order</option>
                        </select>

                        <select id="statusFilter" class="px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary bg-white text-gray-700">
                            <option value="">All Status</option>
                            <option value="complete">Complete (100% Received)</option>
                            <option value="partial">Partial (Some Received)</option>
                            <option value="none">None (0% Received)</option>
                        </select>

                        <select id="sortSelect" class="px-3 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 font-secondary bg-white text-gray-700">
                            <option value="date-desc">Newest First</option>
                            <option value="date-asc">Oldest First</option>
                            <option value="progress-desc">Highest Acknowledgment</option>
                            <option value="progress-asc">Lowest Acknowledgment</option>
                            <option value="type-asc">Document Type A-Z</option>
                            <option value="number-asc">Document # A-Z</option>
                        </select>

                        <span id="rowCount" class="text-xs text-gray-400 font-secondary whitespace-nowrap"></span>
                        <button id="resetFilters" class="text-xs text-crimson-700 hover:text-crimson-900 font-secondary underline hidden">Reset</button>
                    </div>
                </div>

                <!-- Documents List -->
                <div id="releaseList" class="space-y-3">
                    <?php if (empty($documents)): ?>
                        <div class="text-center py-16 text-gray-400" id="emptyState">
                            <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                            </svg>
                            <p class="text-lg">No documents released yet.</p>
                            <p class="text-sm mt-1">Documents you release will appear here for monitoring.</p>
                        </div>
                    <?php else: ?>
                        <?php foreach ($documents as $doc): 
                            $progress = $doc['total_recipients'] > 0 
                                ? round(($doc['received_count'] / $doc['total_recipients']) * 100) 
                                : 0;
                            $statusClass = $progress == 100 ? 'bg-green-100 text-green-700' : ($progress > 0 ? 'bg-amber-100 text-amber-700' : 'bg-red-100 text-red-700');
                            $progressColor = $progress == 100 ? 'bg-green-500' : ($progress > 0 ? 'bg-crimson-600' : 'bg-gray-300');
                        ?>
                        <div class="release-card border-2 border-gray-200 rounded-xl p-5 hover:border-crimson-300 hover:shadow-md transition cursor-pointer"
                             data-document='<?= htmlspecialchars(json_encode($doc)) ?>'
                             data-type="<?= htmlspecialchars($doc['document_type']) ?>"
                             data-number="<?= htmlspecialchars($doc['document_number']) ?>"
                             data-subject="<?= htmlspecialchars(strtolower($doc['subject'])) ?>"
                             data-person="<?= htmlspecialchars(strtolower($doc['concerned_person'])) ?>"
                             data-progress="<?= $progress ?>"
                             data-received="<?= $doc['received_count'] ?>"
                             data-total="<?= $doc['total_recipients'] ?>"
                             data-date="<?= $doc['date_issued'] ?>"
                             onclick="viewRelease(this)">
                            <div class="flex justify-between items-start">
                                <div class="flex-1">
                                    <div class="flex items-center gap-3 flex-wrap">
                                        <span class="inline-flex px-3 py-1 text-xs font-semibold rounded-full bg-gray-100">
                                            <?= htmlspecialchars($doc['document_type']) ?>
                                        </span>
                                        <span class="font-mono text-sm text-gray-500"><?= htmlspecialchars($doc['document_number']) ?></span>
                                        <span class="<?= $statusClass ?> px-2 py-1 rounded-full text-xs font-semibold">
                                            <?= $doc['received_count'] ?>/<?= $doc['total_recipients'] ?> Received (<?= $progress ?>%)
                                        </span>
                                    </div>
                                    <h4 class="font-bold text-gray-800 mt-2"><?= htmlspecialchars($doc['subject']) ?></h4>
                                    <p class="text-sm text-gray-500 mt-1">
                                        Concerned: <span class="font-medium"><?= htmlspecialchars($doc['concerned_person']) ?></span>
                                    </p>
                                    <p class="text-xs text-gray-400 mt-1">
                                        Issued: <?= date('M d, Y', strtotime($doc['date_issued'])) ?>
                                    </p>
                                </div>
                                <?php if ($doc['sender_email'] === $user_email): ?>
                                <button onclick="openReleaseDeleteModal(event, <?= $doc['document_id'] ?>, '<?= htmlspecialchars(addslashes($doc['document_type'])) ?>', '<?= htmlspecialchars(addslashes($doc['subject'])) ?>')"
                                        class="ml-4 flex-shrink-0 flex items-center gap-1.5 px-3 py-1.5 text-xs font-semibold bg-crimson-50 text-crimson-700 hover:bg-crimson-100 border border-crimson-200 rounded-lg transition font-secondary">
                                    <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                                    </svg>
                                    Delete
                                </button>
                                <?php endif; ?>
                                  
                            </div>

                            <!-- Progress Bar -->
                            <div class="mt-4">
                                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                                    <div class="h-full <?= $progressColor ?> transition-all duration-300" style="width: <?= $progress ?>%"></div>
                                </div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>

                <!-- Empty State for filters -->
                <div id="releaseEmpty" class="hidden text-center py-16 text-gray-400">
                    <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                    <p class="text-lg">No documents match your filters.</p>
                    <p class="text-sm mt-1">Try adjusting your search or filter criteria.</p>
                </div>
            </div>
        </div>
    </main>

    <!-- Release Detail Modal -->
    <div id="releaseModal" class="fixed inset-0 bg-black/60 hidden items-center justify-center z-50 p-4">
        <div class="bg-white w-full max-w-3xl rounded-2xl shadow-2xl max-h-[90vh] flex flex-col">
            <div class="bg-crimson-700 text-white px-6 py-5 flex justify-between items-center rounded-t-2xl flex-shrink-0">
                <div>
                    <h2 id="modalDocType" class="font-bold text-lg"></h2>
                    <p id="modalDocNumber" class="text-sm opacity-90"></p>
                </div>
                <button onclick="closeModal()" class="text-3xl leading-none hover:opacity-75">&times;</button>
            </div>

            <div class="p-6 overflow-y-auto flex-1">
                <div class="grid grid-cols-2 gap-4 mb-6 text-sm">
                    <div>
                        <p class="text-gray-500">Subject</p>
                        <p id="modalSubject" class="font-medium"></p>
                    </div>
                    <div>
                        <p class="text-gray-500">Date Issued</p>
                        <p id="modalDate" class="font-medium"></p>
                    </div>
                </div>

                <div class="mb-6">
                    <p class="font-semibold mb-2">Acknowledgement Status</p>
                    <div class="flex justify-between text-xs mb-1">
                        <span class="text-gray-500">Progress</span>
                        <span id="modalProgressPercent" class="font-semibold"></span>
                    </div>
                    <div id="modalProgress" class="h-2 bg-gray-100 rounded-full mb-2 overflow-hidden">
                        <div class="h-full bg-crimson-600 rounded-full transition-all" style="width: 0%"></div>
                    </div>
                    <div class="flex justify-between text-xs">
                        <span id="modalReceivedCount"></span>
                        <span id="modalTotalRecipients"></span>
                    </div>
                </div>

                <div>
                    <p class="font-semibold mb-3">Recipients & Feedback:</p>
                    <div id="acknowledgedList" class="space-y-4"></div>
                </div>
            </div>
        </div>
    </div>

    <script>
        const BASE_PATH = <?php
            $_rl_root = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
            $_rl_doc  = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
            echo json_encode(str_replace($_rl_doc, '', $_rl_root));
        ?>;

        async function viewRelease(element) {
            // Get the stored document data
            let doc;
            try {
                doc = JSON.parse(element.getAttribute('data-document'));
            } catch(e) {
                console.error('Error parsing document data', e);
                return;
            }
            
            document.getElementById('modalDocType').textContent = doc.document_type;
            document.getElementById('modalDocNumber').textContent = doc.document_number;
            document.getElementById('modalSubject').textContent = doc.subject;
            document.getElementById('modalDate').textContent = new Date(doc.date_issued).toLocaleDateString('en-US', { year:'numeric', month:'long', day:'numeric' });

            const received = parseInt(doc.received_count) || 0;
            const total = parseInt(doc.total_recipients) || 0;
            const percent = total > 0 ? Math.round((received / total) * 100) : 0;

            document.getElementById('modalReceivedCount').innerHTML = 
                `<span class="text-green-600 font-semibold">${received}</span> Received`;
            document.getElementById('modalTotalRecipients').textContent = `${total} Total`;
            document.getElementById('modalProgressPercent').textContent = `${percent}%`;
            
            const progressBar = document.getElementById('modalProgress');
            progressBar.innerHTML = `<div class="h-full bg-crimson-600 rounded-full transition-all" style="width:${percent}%"></div>`;

            // Fetch recipients with feedback via AJAX
            try {
                const response = await fetch(`get_recipients.php?document_type=${encodeURIComponent(doc.document_type)}&document_id=${doc.document_id}`);
                const recipients = await response.json();
                
                const list = document.getElementById('acknowledgedList');
                list.innerHTML = '';
                
                if (recipients.length === 0) {
                    list.innerHTML = '<p class="text-gray-500 text-sm">No recipients found.</p>';
                } else {
                    recipients.forEach(recipient => {
                        const isReceived = recipient.status === 'Received';
                        const div = document.createElement('div');
                        div.className = 'border border-gray-200 rounded-lg p-4 hover:border-crimson-200 transition';
                        
                        div.innerHTML = `
                            <div class="flex justify-between items-start mb-2">
                                <div>
                                    <p class="font-semibold text-gray-800">${escapeHtml(recipient.recipient_name)}</p>
                                    <p class="text-xs text-gray-500">${escapeHtml(recipient.recipient_email)}</p>
                                </div>
                                <span class="px-2 py-1 rounded-full text-xs font-semibold ${isReceived ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'}">
                                    ${isReceived ? '✓ Received' : '⏳ Pending'}
                                </span>
                            </div>
                            ${isReceived && recipient.received_at ? `
                                <p class="text-xs text-gray-400 mb-2">Received on: ${recipient.formatted_received_at || new Date(recipient.received_at).toLocaleString()}</p>
                            ` : ''}
                            ${recipient.feedback ? `
                                <div class="mt-2 bg-gray-50 rounded-lg p-3 border-l-4 border-crimson-200">
                                    <p class="text-xs text-gray-500 mb-1">Feedback / Acknowledgment:</p>
                                    <p class="text-sm text-gray-700 italic">"${escapeHtml(recipient.feedback)}"</p>
                                </div>
                            ` : (isReceived ? `
                                <div class="mt-2 bg-gray-50 rounded-lg p-3">
                                    <p class="text-xs text-gray-400">No feedback provided.</p>
                                </div>
                            ` : '')}
                        `;
                        list.appendChild(div);
                    });
                }
            } catch (error) {
                console.error('Error fetching recipients:', error);
                document.getElementById('acknowledgedList').innerHTML = '<p class="text-red-500 text-sm">Error loading recipient data.</p>';
            }

            document.getElementById('releaseModal').classList.remove('hidden');
            document.getElementById('releaseModal').classList.add('flex');
        }

        function escapeHtml(text) {
            if (!text) return '';
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }

        function closeModal() {
            const modal = document.getElementById('releaseModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }

        // Search, Filter & Sort functionality
        (function() {
            const searchEl = document.getElementById('releaseSearch');
            const typeFilterEl = document.getElementById('typeFilter');
            const statusFilterEl = document.getElementById('statusFilter');
            const sortEl = document.getElementById('sortSelect');
            const countEl = document.getElementById('rowCount');
            const emptyEl = document.getElementById('releaseEmpty');
            const resetBtn = document.getElementById('resetFilters');
            const container = document.getElementById('releaseList');
            const originalEmptyState = document.getElementById('emptyState');
            
            function filterAndSort() {
                const searchTerm = searchEl.value.toLowerCase().trim();
                const typeFilter = typeFilterEl.value;
                const statusFilter = statusFilterEl.value;
                const sortOption = sortEl.value;
                
                const cards = Array.from(container.querySelectorAll('.release-card'));
                
                if (cards.length === 0) return;
                
                // Apply filters
                cards.forEach(card => {
                    let show = true;
                    
                    // Search filter
                    if (searchTerm) {
                        const number = card.dataset.number?.toLowerCase() || '';
                        const subject = card.dataset.subject?.toLowerCase() || '';
                        const person = card.dataset.person?.toLowerCase() || '';
                        if (!number.includes(searchTerm) && !subject.includes(searchTerm) && !person.includes(searchTerm)) {
                            show = false;
                        }
                    }
                    
                    // Type filter
                    if (typeFilter && card.dataset.type !== typeFilter) {
                        show = false;
                    }
                    
                    // Status filter (by acknowledgment percentage)
                    if (statusFilter && show) {
                        const progress = parseInt(card.dataset.progress) || 0;
                        if (statusFilter === 'complete' && progress !== 100) show = false;
                        else if (statusFilter === 'partial' && (progress === 0 || progress === 100)) show = false;
                        else if (statusFilter === 'none' && progress !== 0) show = false;
                    }
                    
                    card.style.display = show ? '' : 'none';
                });
                
                // Collect visible cards
                const visible = cards.filter(c => c.style.display !== 'none');
                
                // Sort visible cards
                visible.sort((a, b) => {
                    if (sortOption === 'date-desc') {
                        return new Date(b.dataset.date) - new Date(a.dataset.date);
                    }
                    if (sortOption === 'date-asc') {
                        return new Date(a.dataset.date) - new Date(b.dataset.date);
                    }
                    if (sortOption === 'progress-desc') {
                        return parseInt(b.dataset.progress) - parseInt(a.dataset.progress);
                    }
                    if (sortOption === 'progress-asc') {
                        return parseInt(a.dataset.progress) - parseInt(b.dataset.progress);
                    }
                    if (sortOption === 'type-asc') {
                        return (a.dataset.type || '').localeCompare(b.dataset.type || '');
                    }
                    if (sortOption === 'number-asc') {
                        return (a.dataset.number || '').localeCompare(b.dataset.number || '');
                    }
                    return 0;
                });
                
                // Re-append in sorted order
                visible.forEach(card => container.appendChild(card));
                
                // Update count
                const count = visible.length;
                countEl.textContent = count + ' document' + (count !== 1 ? 's' : '');
                
                // Show/hide empty state
                if (visible.length === 0) {
                    emptyEl.classList.remove('hidden');
                    if (originalEmptyState) originalEmptyState.classList.add('hidden');
                } else {
                    emptyEl.classList.add('hidden');
                    if (originalEmptyState) originalEmptyState.classList.remove('hidden');
                }
                
                // Show reset button if any filter is active
                const isFiltered = searchTerm || typeFilter || statusFilter || sortOption !== 'date-desc';
                resetBtn.classList.toggle('hidden', !isFiltered);
            }
            
            function resetAll() {
                searchEl.value = '';
                typeFilterEl.value = '';
                statusFilterEl.value = '';
                sortEl.value = 'date-desc';
                filterAndSort();
            }
            
            // Add event listeners
            if (searchEl) searchEl.addEventListener('input', filterAndSort);
            if (typeFilterEl) typeFilterEl.addEventListener('change', filterAndSort);
            if (statusFilterEl) statusFilterEl.addEventListener('change', filterAndSort);
            if (sortEl) sortEl.addEventListener('change', filterAndSort);
            if (resetBtn) resetBtn.addEventListener('click', resetAll);
            
            // Initial filter
            filterAndSort();
        })();

        // Close modal when clicking outside
        document.getElementById('releaseModal').addEventListener('click', function(e) {
            if (e.target === this) closeModal();
        });
    </script>

    <script src="../js/sidebar.js"></script>
</body>
</html>

<!-- Release Delete Confirmation Modal -->
<div id="releaseDeleteModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50 p-4">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
        <div class="px-6 py-5 border-b border-gray-100 flex items-center justify-between">
            <h3 class="text-lg font-bold text-gray-800 font-main">Move to Trash</h3>
            <button onclick="closeReleaseDeleteModal()" class="text-gray-400 hover:text-gray-600 text-2xl leading-none">&times;</button>
        </div>
        <div class="px-6 py-6">
            <div class="flex items-start gap-4">
                <div class="w-11 h-11 rounded-full bg-crimson-50 flex items-center justify-center flex-shrink-0">
                    <svg class="w-5 h-5 text-crimson-700" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
                    </svg>
                </div>
                <div>
                    <p class="text-sm text-gray-700 font-secondary">Are you sure you want to move this document to Trash?</p>
                    <p id="releaseDeleteModalSubject" class="text-sm font-bold text-gray-900 font-secondary mt-0.5 truncate"></p>
                    <p class="text-xs text-gray-400 font-secondary mt-2">The document will be hidden from Release Monitoring. You can restore or permanently delete it in Trash.</p>
                </div>
            </div>
        </div>
        <div class="px-6 py-4 bg-gray-50 flex items-center justify-end gap-3">
            <button onclick="closeReleaseDeleteModal()"
                class="px-4 py-2 text-sm font-semibold text-gray-600 bg-white border border-gray-200 rounded-lg hover:bg-gray-100 transition font-secondary">
                Cancel
            </button>
            <button id="releaseDeleteConfirmBtn"
                class="px-4 py-2 text-sm font-semibold bg-crimson-700 text-white rounded-lg hover:bg-crimson-800 transition font-secondary">
                Move to Trash
            </button>
        </div>
    </div>
</div>

<script>
    let _releaseDeleteDocId   = null;
    let _releaseDeleteDocType = null;

    function openReleaseDeleteModal(event, docId, docType, subject) {
        event.stopPropagation();
        _releaseDeleteDocId   = docId;
        _releaseDeleteDocType = docType;
        document.getElementById('releaseDeleteModalSubject').textContent = subject;
        const modal = document.getElementById('releaseDeleteModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeReleaseDeleteModal() {
        const modal = document.getElementById('releaseDeleteModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        _releaseDeleteDocId   = null;
        _releaseDeleteDocType = null;
    }

    document.getElementById('releaseDeleteModal').addEventListener('click', function(e) {
        if (e.target === this) closeReleaseDeleteModal();
    });

    document.getElementById('releaseDeleteConfirmBtn').addEventListener('click', async function() {
        if (!_releaseDeleteDocId || !_releaseDeleteDocType) return;

        this.disabled    = true;
        this.textContent = 'Moving…';

        // Capture before closeReleaseDeleteModal() nulls them
        const targetId   = parseInt(_releaseDeleteDocId);
        const targetType = _releaseDeleteDocType;

        try {
            const fd = new FormData();
            fd.append('action',        'trash_release');
            fd.append('document_id',   targetId);
            fd.append('document_type', targetType);

            const res  = await fetch('', { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                closeReleaseDeleteModal();

                // Animate the matching card out immediately
                document.querySelectorAll('.release-card').forEach(card => {
                    try {
                        const doc = JSON.parse(card.getAttribute('data-document'));
                        if (parseInt(doc.document_id) === targetId && doc.document_type === targetType) {
                            card.style.transition = 'opacity 0.3s ease, transform 0.3s ease';
                            card.style.opacity    = '0';
                            card.style.transform  = 'translateX(40px)';
                            setTimeout(() => card.remove(), 320);
                        }
                    } catch(e) {}
                });

                Swal.fire({ icon: 'success', title: 'Moved to Trash', text: 'Document has been moved to Trash.', timer: 1800, showConfirmButton: false });
            } else {
                closeReleaseDeleteModal();
                Swal.fire({ icon: 'error', title: 'Error', text: data.message, confirmButtonColor: '#AA0003' });
            }
        } catch (e) {
            closeReleaseDeleteModal();
            Swal.fire({ icon: 'error', title: 'Error', text: 'Could not move document to trash.', confirmButtonColor: '#AA0003' });
        }

        this.disabled    = false;
        this.textContent = 'Move to Trash';
    });
</script>