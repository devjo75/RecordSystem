<?php
session_start();
require_once '../auth-guard/Auth.php';

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
$user_initials     = getInitialsFromEmail($user_email);
$user_role         = $_SESSION['user_role']  ?? 'user';
$user_role_display = ucfirst($user_role);
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
<body class="bg-gray-100">

    <?php $active_page = 'inbox'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">

        <!-- Top Bar -->
        <header class="bg-white shadow-sm sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="ml-12 lg:ml-0">
                        <h2 class="text-2xl font-bold text-gray-800 font-main">Inbox</h2>
                        <p class="text-sm text-gray-600 mt-1 font-secondary">Manage incoming document notifications</p>
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
            <div class="bg-white rounded-2xl shadow p-6">

                <!-- Top Bar -->
                <div class="flex items-center justify-between mb-6">
                    <div>
                        <h3 class="text-xl font-bold text-gray-800 font-main">All Messages</h3>
                        <p class="text-sm text-gray-500 font-secondary mt-1">Your document inbox</p>
                    </div>
                    <input type="text" placeholder="Search messages..."
                        class="px-4 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary w-56">
                </div>

                <!-- Table Header -->
                <div class="grid grid-cols-12 text-xs text-gray-400 uppercase px-4 py-2 border-b font-secondary tracking-wider">
                    <div class="col-span-3">Sender</div>
                    <div class="col-span-3">Subject / Document</div>
                    <div class="col-span-2">Type</div>
                    <div class="col-span-2">Status</div>
                    <div class="col-span-2 text-right">Date</div>
                </div>

                <!-- Message Row 1 -->
                <div onclick="openModal('MARK CRUZ','M','request_travel.png','Jun 12, 2025','Requesting travel with 12 student on May 13 2025')"
                    class="grid grid-cols-12 items-center bg-gray-50 rounded-lg p-4 mt-3 hover:shadow-md transition cursor-pointer border-2 border-transparent hover:border-crimson-100">
                    <div class="col-span-3 flex items-center gap-3">
                        <input type="checkbox" onclick="event.stopPropagation()" class="w-4 h-4 text-crimson-700 border-gray-300 rounded focus:ring-crimson-500">
                        <div class="w-10 h-10 rounded-full bg-indigo-500 text-white flex items-center justify-center font-bold font-secondary">M</div>
                        <span class="font-semibold text-gray-800 font-secondary">MARK CRUZ</span>
                    </div>
                    <div class="col-span-3 text-gray-700 font-semibold font-secondary">Travel Request</div>
                    <div class="col-span-2 text-gray-500 font-secondary text-sm">Transcript</div>
                    <div class="col-span-2">
                        <span class="bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-xs font-semibold font-secondary">Pending</span>
                    </div>
                    <div class="col-span-2 text-right text-gray-500 text-sm font-secondary">Jun 12, 2025</div>
                </div>

                <!-- Message Row 2 -->
                <div onclick="openModal('FERNANDO','F','memo.pdf','May 3, 2025','Memorandum for all staff regarding new policies.')"
                    class="grid grid-cols-12 items-center bg-gray-50 rounded-lg p-4 mt-3 hover:shadow-md transition cursor-pointer border-2 border-transparent hover:border-crimson-100">
                    <div class="col-span-3 flex items-center gap-3">
                        <input type="checkbox" onclick="event.stopPropagation()" class="w-4 h-4 text-crimson-700 border-gray-300 rounded focus:ring-crimson-500">
                        <div class="w-10 h-10 rounded-full bg-emerald-500 text-white flex items-center justify-center font-bold font-secondary">F</div>
                        <span class="font-semibold text-gray-800 font-secondary">FERNANDO</span>
                    </div>
                    <div class="col-span-3 text-gray-700 font-semibold font-secondary">Memorandum</div>
                    <div class="col-span-2 text-gray-500 font-secondary text-sm">Memorandum</div>
                    <div class="col-span-2">
                        <span class="bg-amber-100 text-amber-700 px-3 py-1 rounded-full text-xs font-semibold font-secondary">Pending</span>
                    </div>
                    <div class="col-span-2 text-right text-gray-500 text-sm font-secondary">May 3, 2025</div>
                </div>

            </div>
        </div>
    </main>

<!-- MODAL -->
<div id="messageModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden relative">
        <div class="bg-crimson-700 text-white px-6 py-5 flex items-center justify-between">
            <div class="flex items-center gap-3">
                <div id="modalAvatar" class="w-10 h-10 rounded-full bg-white bg-opacity-20 text-white flex items-center justify-center font-bold font-secondary text-lg">M</div>
                <h2 id="modalName" class="font-bold text-lg font-main">NAME</h2>
            </div>
            <button onclick="closeModal()" class="text-white hover:opacity-75 text-2xl leading-none">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 mb-1 font-secondary">
                <span class="font-semibold text-gray-700">Document File:</span> <span id="modalFile"></span>
            </p>
            <p class="text-sm text-gray-500 mb-5 font-secondary">
                Received: <span id="modalDate"></span>
            </p>
            <div class="mb-6">
                <label class="text-sm font-semibold text-gray-700 font-secondary">Message</label>
                <div id="modalMessage" class="border-2 border-gray-200 rounded-lg p-4 mt-2 text-gray-700 bg-gray-50 font-secondary text-sm min-h-[60px]"></div>
            </div>
            <button class="w-full bg-crimson-700 text-white py-3 rounded-lg hover:bg-crimson-800 font-bold font-secondary transition duration-200 transform hover:scale-[1.01] active:scale-[0.99]">
                Mark as Received
            </button>
        </div>
    </div>
</div>

<script>
function openModal(name, initial, file, date, message) {
    document.getElementById('modalName').innerText    = name;
    document.getElementById('modalAvatar').innerText  = initial;
    document.getElementById('modalFile').innerText    = file;
    document.getElementById('modalDate').innerText    = date;
    document.getElementById('modalMessage').innerText = message;
    const modal = document.getElementById('messageModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeModal() {
    document.getElementById('messageModal').classList.add('hidden');
    document.getElementById('messageModal').classList.remove('flex');
}
document.getElementById('messageModal').addEventListener('click', function(e) {
    if (e.target === this) closeModal();
});
</script>
</body>
</html>
