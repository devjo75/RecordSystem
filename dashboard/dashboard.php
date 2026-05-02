<?php
session_start();
require_once '../auth-guard/Auth.php';
require_once '../config/db.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../auth-guard/login.php');
    exit();
}

$pdo = getPDO();

// ── Filters ───────────────────────────────────────────────────
$selected_month = $_GET['month'] ?? '';
$selected_year  = $_GET['year']  ?? '';
$filter_type    = $_GET['filter_type'] ?? 'month'; // day, month, year
$selected_day   = $_GET['day'] ?? '';

// Build WHERE clause based on filters
function buildWhere($selected_month, $selected_year, $selected_day, $filter_type) {
    $conditions = [];
    
    if ($filter_type === 'day' && $selected_day && $selected_month && $selected_year) {
        $conditions[] = "DATE(created_at) = '" . date('Y-m-d', strtotime("$selected_year-$selected_month-$selected_day")) . "'";
    } elseif ($filter_type === 'month' && $selected_month && $selected_year) {
        $conditions[] = "MONTH(created_at) = " . intval($selected_month);
        $conditions[] = "YEAR(created_at) = " . intval($selected_year);
    } elseif ($filter_type === 'year' && $selected_year) {
        $conditions[] = "YEAR(created_at) = " . intval($selected_year);
    }
    
    return empty($conditions) ? '' : 'AND ' . implode(' AND ', $conditions);
}

$where = buildWhere($selected_month, $selected_year, $selected_day, $filter_type);

// ── Stats: Total per document type per status ─────────────────
try {
    $stmt = $pdo->prepare("
        SELECT
            document_type,
            status,
            COUNT(*) AS total
        FROM document_recipients
        WHERE 1=1 {$where}
        GROUP BY document_type, status
        ORDER BY document_type ASC
    ");
    $stmt->execute();
    $stats = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $stats = [];
}

// Organize into structured array
$doc_types = [
    'Memorandum Order' => 'Memorandum Order', 
    'Special Order' => 'Special Order', 
    'Travel Order' => 'Travel Order'
];
$data = [];
foreach ($doc_types as $key => $label) {
    $data[$key] = ['label' => $label, 'pending' => 0, 'received' => 0, 'sent' => 0, 'total' => 0];
}
foreach ($stats as $row) {
    if (isset($data[$row['document_type']])) {
        $status_key = strtolower($row['status']);
        if ($status_key === 'pending') {
            $data[$row['document_type']]['pending'] = (int) $row['total'];
        } elseif ($status_key === 'received') {
            $data[$row['document_type']]['received'] = (int) $row['total'];
        } elseif ($status_key === 'sent') {
            $data[$row['document_type']]['sent'] = (int) $row['total'];
        }
        $data[$row['document_type']]['total'] += (int) $row['total'];
    }
}

// ── Overall totals ────────────────────────────────────────────
$total_pending  = array_sum(array_column($data, 'pending'));
$total_received = array_sum(array_column($data, 'received'));
$total_sent     = array_sum(array_column($data, 'sent'));
$total_all      = $total_pending + $total_received + $total_sent;

// ── Trend data based on filter type ───────────────────────────
$trend_months = [];
$trend_pending = [];
$trend_received = [];

if ($filter_type === 'day') {
    // Get last 30 days trend
    $trend = $pdo->query("
        SELECT
            DATE(created_at) AS date_label,
            DATE_FORMAT(created_at, '%Y-%m-%d') AS date_key,
            status,
            COUNT(*) AS total
        FROM document_recipients
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY date_key, date_label, status
        ORDER BY date_key ASC
    ")->fetchAll();
    
    $trend_map = [];
    foreach ($trend as $row) {
        if (!isset($trend_map[$row['date_key']])) {
            $trend_map[$row['date_key']] = ['label' => $row['date_label'], 'pending' => 0, 'received' => 0];
        }
        $status_key = strtolower($row['status']);
        if ($status_key === 'pending') {
            $trend_map[$row['date_key']]['pending'] = (int) $row['total'];
        } elseif ($status_key === 'received') {
            $trend_map[$row['date_key']]['received'] = (int) $row['total'];
        }
    }
    foreach ($trend_map as $entry) {
        $trend_months[]   = $entry['label'];
        $trend_pending[]  = $entry['pending'];
        $trend_received[] = $entry['received'];
    }
} elseif ($filter_type === 'month') {
    // Get last 12 months trend
    $trend = $pdo->query("
        SELECT
            DATE_FORMAT(created_at, '%b %Y') AS month_label,
            DATE_FORMAT(created_at, '%Y-%m') AS month_key,
            status,
            COUNT(*) AS total
        FROM document_recipients
        WHERE created_at >= DATE_SUB(NOW(), INTERVAL 12 MONTH)
        GROUP BY month_key, month_label, status
        ORDER BY month_key ASC
    ")->fetchAll();
    
    $trend_map = [];
    foreach ($trend as $row) {
        if (!isset($trend_map[$row['month_key']])) {
            $trend_map[$row['month_key']] = ['label' => $row['month_label'], 'pending' => 0, 'received' => 0];
        }
        $status_key = strtolower($row['status']);
        if ($status_key === 'pending') {
            $trend_map[$row['month_key']]['pending'] = (int) $row['total'];
        } elseif ($status_key === 'received') {
            $trend_map[$row['month_key']]['received'] = (int) $row['total'];
        }
    }
    foreach ($trend_map as $entry) {
        $trend_months[]   = $entry['label'];
        $trend_pending[]  = $entry['pending'];
        $trend_received[] = $entry['received'];
    }
} else {
    // Get last 5 years trend
    $trend = $pdo->query("
        SELECT
            YEAR(created_at) AS year_label,
            YEAR(created_at) AS year_key,
            status,
            COUNT(*) AS total
        FROM document_recipients
        GROUP BY year_key, year_label, status
        ORDER BY year_key ASC
        LIMIT 5
    ")->fetchAll();
    
    $trend_map = [];
    foreach ($trend as $row) {
        if (!isset($trend_map[$row['year_key']])) {
            $trend_map[$row['year_key']] = ['label' => $row['year_label'], 'pending' => 0, 'received' => 0];
        }
        $status_key = strtolower($row['status']);
        if ($status_key === 'pending') {
            $trend_map[$row['year_key']]['pending'] = (int) $row['total'];
        } elseif ($status_key === 'received') {
            $trend_map[$row['year_key']]['received'] = (int) $row['total'];
        }
    }
    foreach ($trend_map as $entry) {
        $trend_months[]   = $entry['label'];
        $trend_pending[]  = $entry['pending'];
        $trend_received[] = $entry['received'];
    }
}

// ── Available years for filter ────────────────────────────────
$years = $pdo->query("
    SELECT DISTINCT YEAR(created_at) AS yr
    FROM document_recipients
    ORDER BY yr DESC
")->fetchAll(PDO::FETCH_COLUMN);
if (empty($years)) $years = [date('Y')];

$months_list = [
    1=>'January',2=>'February',3=>'March',4=>'April',
    5=>'May',6=>'June',7=>'July',8=>'August',
    9=>'September',10=>'October',11=>'November',12=>'December',
];

// Days in month
$days_in_month = ($selected_month && $selected_year) ? cal_days_in_month(CAL_GREGORIAN, $selected_month, $selected_year) : 31;

// Most received document type
$most_received = '';
$max_received  = -1;
foreach ($data as $key => $d) {
    if ($d['received'] > $max_received) {
        $max_received  = $d['received'];
        $most_received = $d['label'];
    }
}

// Get recent document activity
$recent_activity = $pdo->query("
    SELECT 
        dr.document_type,
        dr.recipient_name,
        dr.recipient_email,
        dr.status,
        dr.received_at,
        dr.created_at
    FROM document_recipients dr
    ORDER BY dr.created_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reports — WMSU Document Management</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <link href="https://fonts.googleapis.com/css2?family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'IBM Plex Sans', sans-serif; }
    </style>
</head>
<body class="bg-gray-100">
<div class="flex">

    <?php
        $active_page = 'reports';
        include __DIR__ . '/../sidebar/sidebar.php';
    ?>

    <main class="flex-1 lg:ml-64 min-h-screen p-6">

        <!-- Header -->
        <div class="mb-6">
            <h1 class="text-2xl font-bold text-gray-800">Document Reports</h1>
            <p class="text-gray-500 text-sm mt-1">Overview of document activity by type and status</p>
        </div>

        <!-- Filters -->
        <form method="GET" class="bg-white rounded-xl shadow p-4 mb-6">
            <div class="flex flex-wrap items-end gap-4">
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Filter By</label>
                    <select name="filter_type" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400" onchange="this.form.submit()">
                        <option value="month" <?= $filter_type == 'month' ? 'selected' : '' ?>>Monthly</option>
                        <option value="year" <?= $filter_type == 'year' ? 'selected' : '' ?>>Yearly</option>
                        <option value="day" <?= $filter_type == 'day' ? 'selected' : '' ?>>Daily</option>
                    </select>
                </div>
                
                <?php if ($filter_type == 'day'): ?>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Day</label>
                    <select name="day" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400">
                        <option value="">Select Day</option>
                        <?php for($d = 1; $d <= $days_in_month; $d++): ?>
                        <option value="<?= $d ?>" <?= $selected_day == $d ? 'selected' : '' ?>>
                            <?= $d ?>
                        </option>
                        <?php endfor; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <?php if ($filter_type != 'year'): ?>
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Month</label>
                    <select name="month" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400">
                        <option value="">All Months</option>
                        <?php foreach ($months_list as $num => $name): ?>
                        <option value="<?= $num ?>" <?= $selected_month == $num ? 'selected' : '' ?>>
                            <?= $name ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                
                <div>
                    <label class="block text-xs font-semibold text-gray-600 mb-1">Year</label>
                    <select name="year" class="border border-gray-300 rounded-lg px-3 py-2 text-sm focus:outline-none focus:ring-2 focus:ring-red-400">
                        <option value="">All Years</option>
                        <?php foreach ($years as $yr): ?>
                        <option value="<?= $yr ?>" <?= $selected_year == $yr ? 'selected' : '' ?>>
                            <?= $yr ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <button type="submit"
                    class="px-4 py-2 bg-red-700 text-white rounded-lg text-sm font-medium hover:bg-red-800 transition">
                    Apply Filter
                </button>
                <?php if ($selected_month || $selected_year || $selected_day): ?>
                <a href="reports.php"
                    class="px-4 py-2 bg-gray-100 text-gray-600 rounded-lg text-sm font-medium hover:bg-gray-200 transition">
                    Clear
                </a>
                <?php endif; ?>
            </div>
        </form>

        <!-- Summary Cards -->
        <div class="grid grid-cols-1 sm:grid-cols-4 gap-4 mb-6">
            <div class="bg-white rounded-xl shadow p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-blue-100 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-blue-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium">Total Documents</p>
                    <p class="text-2xl font-bold text-gray-800"><?= $total_all ?></p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-yellow-100 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-yellow-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium">Pending</p>
                    <p class="text-2xl font-bold text-yellow-600"><?= $total_pending ?></p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-purple-100 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-purple-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium">Sent</p>
                    <p class="text-2xl font-bold text-purple-600"><?= $total_sent ?></p>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow p-5 flex items-center gap-4">
                <div class="w-12 h-12 rounded-full bg-green-100 flex items-center justify-center shrink-0">
                    <svg class="w-6 h-6 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"/>
                    </svg>
                </div>
                <div>
                    <p class="text-xs text-gray-400 font-medium">Received</p>
                    <p class="text-2xl font-bold text-green-600"><?= $total_received ?></p>
                </div>
            </div>
        </div>

        <!-- Most Received Badge -->
        <?php if ($most_received && $max_received > 0): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl px-5 py-3 mb-6 flex items-center gap-3">
            <svg class="w-5 h-5 text-green-600 shrink-0" fill="currentColor" viewBox="0 0 20 20">
                <path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>
            </svg>
            <p class="text-sm text-green-700">
                <span class="font-semibold">Most Received Document:</span>
                <?= htmlspecialchars($most_received) ?> with <?= $max_received ?> received document(s)
            </p>
        </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
            <!-- Bar Chart: Per Document Type -->
            <div class="bg-white rounded-xl shadow p-6">
                <h2 class="font-semibold text-gray-700 mb-1">Documents by Type</h2>
                <p class="text-xs text-gray-400 mb-4">Document status distribution</p>
                <canvas id="typeChart" height="220"></canvas>
            </div>

            <!-- Bar Chart: Trend -->
            <div class="bg-white rounded-xl shadow p-6">
                <h2 class="font-semibold text-gray-700 mb-1">
                    <?= $filter_type == 'day' ? 'Daily' : ($filter_type == 'year' ? 'Yearly' : 'Monthly') ?> Trend
                </h2>
                <p class="text-xs text-gray-400 mb-4">Pending vs Received over time</p>
                <canvas id="trendChart" height="220"></canvas>
            </div>
        </div>

        <!-- Summary Table -->
        <div class="bg-white rounded-xl shadow p-6 mb-6">
            <h2 class="font-semibold text-gray-700 mb-4">Detailed Breakdown</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-400 uppercase border-b">
                            <th class="py-3 text-left">Document Type</th>
                            <th class="py-3 text-center">Pending</th>
                            <th class="py-3 text-center">Sent</th>
                            <th class="py-3 text-center">Received</th>
                            <th class="py-3 text-center">Total</th>
                            <th class="py-3 text-left">Receive Rate</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($data as $key => $d):
                            $rate = $d['total'] > 0 ? round(($d['received'] / $d['total']) * 100) : 0;
                        ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-3 font-medium text-gray-800"><?= htmlspecialchars($d['label']) ?></td>
                            <td class="py-3 text-center">
                                <span class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-semibold">
                                    <?= $d['pending'] ?>
                                </span>
                            </td>
                            <td class="py-3 text-center">
                                <span class="px-2 py-1 bg-purple-100 text-purple-700 rounded-full text-xs font-semibold">
                                    <?= $d['sent'] ?>
                                </span>
                            </td>
                            <td class="py-3 text-center">
                                <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold">
                                    <?= $d['received'] ?>
                                </span>
                            </td>
                            <td class="py-3 text-center font-semibold text-gray-700"><?= $d['total'] ?></td>
                            <td class="py-3">
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                                        <div class="bg-green-500 h-2 rounded-full transition-all"
                                             style="width: <?= $rate ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 w-10 text-right"><?= $rate ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>

                        <!-- Totals row -->
                        <tr class="bg-gray-50 font-semibold">
                            <td class="py-3 text-gray-700">Total</td>
                            <td class="py-3 text-center text-yellow-700"><?= $total_pending ?></td>
                            <td class="py-3 text-center text-purple-700"><?= $total_sent ?></td>
                            <td class="py-3 text-center text-green-700"><?= $total_received ?></td>
                            <td class="py-3 text-center text-gray-800"><?= $total_all ?></td>
                            <td class="py-3">
                                <?php $overall_rate = $total_all > 0 ? round(($total_received / $total_all) * 100) : 0; ?>
                                <div class="flex items-center gap-2">
                                    <div class="flex-1 bg-gray-200 rounded-full h-2">
                                        <div class="bg-green-500 h-2 rounded-full" style="width: <?= $overall_rate ?>%"></div>
                                    </div>
                                    <span class="text-xs text-gray-500 w-10 text-right"><?= $overall_rate ?>%</span>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Recent Activity -->
        <div class="bg-white rounded-xl shadow p-6">
            <h2 class="font-semibold text-gray-700 mb-4">Recent Document Activity</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-xs text-gray-400 uppercase border-b">
                            <th class="py-3 text-left">Document Type</th>
                            <th class="py-3 text-left">Recipient</th>
                            <th class="py-3 text-left">Email</th>
                            <th class="py-3 text-center">Status</th>
                            <th class="py-3 text-left">Date</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if (empty($recent_activity)): ?>
                        <tr>
                            <td colspan="5" class="py-4 text-center text-gray-500">No activity found</td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($recent_activity as $activity): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="py-3 text-gray-700"><?= htmlspecialchars($activity['document_type']) ?></td>
                            <td class="py-3 text-gray-700"><?= htmlspecialchars($activity['recipient_name']) ?></td>
                            <td class="py-3 text-gray-500 text-xs"><?= htmlspecialchars($activity['recipient_email']) ?></td>
                            <td class="py-3 text-center">
                                <?php
                                $status_color = [
                                    'pending' => 'yellow',
                                    'sent' => 'purple',
                                    'received' => 'green',
                                    'failed' => 'red'
                                ];
                                $color = $status_color[strtolower($activity['status'])] ?? 'gray';
                                ?>
                                <span class="px-2 py-1 bg-<?= $color ?>-100 text-<?= $color ?>-700 rounded-full text-xs font-semibold">
                                    <?= htmlspecialchars($activity['status']) ?>
                                </span>
                            </td>
                            <td class="py-3 text-gray-500 text-xs">
                                <?= date('M d, Y H:i', strtotime($activity['created_at'])) ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

    </main>
</div>

<script>
// ── Chart.js defaults ─────────────────────────────────────────
Chart.defaults.font.family = "'IBM Plex Sans', sans-serif";
Chart.defaults.color = '#6B7280';

// ── Bar Chart: Per Document Type ──────────────────────────────
const typeCtx = document.getElementById('typeChart').getContext('2d');
new Chart(typeCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode(array_column($data, 'label')) ?>,
        datasets: [
            {
                label: 'Pending',
                data: <?= json_encode(array_column($data, 'pending')) ?>,
                backgroundColor: 'rgba(251, 191, 36, 0.8)',
                borderColor: 'rgba(251, 191, 36, 1)',
                borderWidth: 1,
                borderRadius: 6,
            },
            {
                label: 'Sent',
                data: <?= json_encode(array_column($data, 'sent')) ?>,
                backgroundColor: 'rgba(139, 92, 246, 0.8)',
                borderColor: 'rgba(139, 92, 246, 1)',
                borderWidth: 1,
                borderRadius: 6,
            },
            {
                label: 'Received',
                data: <?= json_encode(array_column($data, 'received')) ?>,
                backgroundColor: 'rgba(34, 197, 94, 0.8)',
                borderColor: 'rgba(34, 197, 94, 1)',
                borderWidth: 1,
                borderRadius: 6,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top' },
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, precision: 0 },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});

// ── Bar Chart: Trend ──────────────────────────────────
const trendCtx = document.getElementById('trendChart').getContext('2d');
new Chart(trendCtx, {
    type: 'bar',
    data: {
        labels: <?= json_encode($trend_months) ?>,
        datasets: [
            {
                label: 'Pending',
                data: <?= json_encode($trend_pending) ?>,
                backgroundColor: 'rgba(251, 191, 36, 0.8)',
                borderColor: 'rgba(251, 191, 36, 1)',
                borderWidth: 1,
                borderRadius: 6,
            },
            {
                label: 'Received',
                data: <?= json_encode($trend_received) ?>,
                backgroundColor: 'rgba(34, 197, 94, 0.8)',
                borderColor: 'rgba(34, 197, 94, 1)',
                borderWidth: 1,
                borderRadius: 6,
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: true,
        plugins: {
            legend: { position: 'top' },
        },
        scales: {
            y: {
                beginAtZero: true,
                ticks: { stepSize: 1, precision: 0 },
                grid: { color: 'rgba(0,0,0,0.05)' }
            },
            x: {
                grid: { display: false }
            }
        }
    }
});
</script>

</body>
</html>