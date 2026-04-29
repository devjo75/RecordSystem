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
    <title>Release Monitoring — WMSU Document Management</title>
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

    <?php $active_page = 'release'; include __DIR__ . '/../sidebar/sidebar.php'; ?>

    <main class="lg:ml-64 min-h-screen">

        <!-- Top Bar -->
        <header class="bg-white shadow-sm sticky top-0 z-20">
            <div class="px-4 sm:px-6 lg:px-8 py-4">
                <div class="flex items-center justify-between">
                    <div class="ml-12 lg:ml-0">
                        <h2 class="text-2xl font-bold text-gray-800 font-main">Release Monitoring</h2>
                        <p class="text-sm text-gray-600 mt-1 font-secondary">Track acknowledgement status per released document</p>
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
                        <h3 class="text-xl font-bold text-gray-800 font-main">Released Documents</h3>
                        <p class="text-sm text-gray-500 font-secondary mt-1">Click a document to view acknowledgement details</p>
                    </div>
                    <div class="flex items-center gap-3">
                        <input type="text" placeholder="Search document..."
                            class="px-4 py-2 border-2 border-gray-200 rounded-lg text-sm focus:outline-none focus:border-crimson-700 focus:ring-2 focus:ring-crimson-200 transition duration-200 font-secondary w-60">
                        <button class="p-2.5 rounded-lg border-2 border-gray-200 hover:border-crimson-300 hover:bg-crimson-50 transition duration-200">
                            <svg class="h-5 w-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 4h18M6 10h12M10 16h4"/>
                            </svg>
                        </button>
                    </div>
                </div>

                <!-- Card 1 -->
                <div onclick="openReleaseModal('request_travel.png','Jun 12, 2025','Requesting travel with 12 students on May 13 2025',['MARK CRUZ','FERNANDO'],['M','F'])"
                    class="border-2 border-gray-200 rounded-xl p-5 hover:border-crimson-300 hover:shadow-md transition cursor-pointer mb-4">
                    <div class="flex justify-between items-start">
                        <div>
                            <h4 class="font-bold text-gray-800 text-base font-main">Request Travel</h4>
                            <p class="text-sm text-gray-500 mt-1 font-secondary">Released by Mark &bull; Jun 12, 2025</p>
                            <div class="flex items-center mt-4 -space-x-2">
                                <div class="w-9 h-9 bg-indigo-500 text-white flex items-center justify-center rounded-full border-2 border-white font-bold font-secondary text-sm">M</div>
                                <div class="w-9 h-9 bg-emerald-500 text-white flex items-center justify-center rounded-full border-2 border-white font-bold font-secondary text-sm">F</div>
                            </div>
                        </div>
                        <span class="px-3 py-1 bg-amber-100 text-amber-700 rounded-full text-xs font-semibold font-secondary">Partial</span>
                    </div>
                </div>

                <!-- Card 2 -->
                <div onclick="openReleaseModal('memo.pdf','May 3, 2025','This is a memorandum for all staff.',['FERNANDO'],['F'])"
                    class="border-2 border-gray-200 rounded-xl p-5 hover:border-crimson-300 hover:shadow-md transition cursor-pointer">
                    <div class="flex justify-between items-start">
                        <div>
                            <h4 class="font-bold text-gray-800 text-base font-main">Memorandum Update</h4>
                            <p class="text-sm text-gray-500 mt-1 font-secondary">Released by Fernando &bull; May 3, 2025</p>
                            <div class="flex items-center mt-4 -space-x-2">
                                <div class="w-9 h-9 bg-emerald-500 text-white flex items-center justify-center rounded-full border-2 border-white font-bold font-secondary text-sm">F</div>
                            </div>
                        </div>
                        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-semibold font-secondary">Completed</span>
                    </div>
                </div>

            </div>
        </div>
    </main>

<!-- MODAL -->
<div id="releaseModal" class="fixed inset-0 bg-black/50 hidden items-center justify-center z-50">
    <div class="bg-white w-full max-w-2xl rounded-2xl shadow-2xl overflow-hidden relative">
        <div class="bg-crimson-700 text-white px-6 py-5 flex items-center justify-between">
            <div>
                <h2 class="font-bold text-lg font-main">Document Details</h2>
                <p class="text-crimson-100 text-sm font-secondary">Acknowledgement summary</p>
            </div>
            <button onclick="closeReleaseModal()" class="text-white hover:opacity-75 text-2xl leading-none">&times;</button>
        </div>
        <div class="p-6">
            <p class="text-sm text-gray-600 mb-1 font-secondary">
                <span class="font-semibold text-gray-700">Document File:</span> <span id="releaseFile"></span>
            </p>
            <p class="text-sm text-gray-500 mb-5 font-secondary">
                Received: <span id="releaseDate"></span>
            </p>
            <div class="mb-6">
                <label class="text-sm font-semibold text-gray-700 font-secondary">Message</label>
                <div id="releaseMessage" class="border-2 border-gray-200 rounded-lg p-4 mt-2 bg-gray-50 text-gray-700 font-secondary text-sm min-h-[60px]"></div>
            </div>
            <div>
                <p class="text-sm font-semibold text-gray-700 mb-3 font-secondary">Acknowledged by:</p>
                <div id="acknowledgedList" class="flex items-center gap-4 flex-wrap"></div>
            </div>
        </div>
    </div>
</div>

<script>
const avatarColors = ['bg-indigo-500','bg-emerald-500','bg-violet-500','bg-sky-500','bg-amber-500','bg-rose-500'];

function openReleaseModal(file, date, message, names, initials) {
    document.getElementById('releaseFile').innerText    = file;
    document.getElementById('releaseDate').innerText    = date;
    document.getElementById('releaseMessage').innerText = message;

    const container = document.getElementById('acknowledgedList');
    container.innerHTML = '';
    for (let i = 0; i < names.length; i++) {
        const color = avatarColors[i % avatarColors.length];
        container.innerHTML += `
            <div class="flex items-center gap-2">
                <div class="w-9 h-9 ${color} text-white flex items-center justify-center rounded-full font-bold font-secondary text-sm">${initials[i]}</div>
                <span class="text-sm text-gray-700 font-semibold font-secondary">${names[i]}</span>
            </div>`;
    }
    const modal = document.getElementById('releaseModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function closeReleaseModal() {
    document.getElementById('releaseModal').classList.add('hidden');
    document.getElementById('releaseModal').classList.remove('flex');
}
document.getElementById('releaseModal').addEventListener('click', function(e) {
    if (e.target === this) closeReleaseModal();
});
</script>
</body>
</html>
