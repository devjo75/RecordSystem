<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Get current page and directory for active link detection
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

function navLink(string $href, string $icon, string $label, string $matchPage, string $matchDir = ''): string {
    global $current_page, $current_dir;
    $isActive = ($current_page === $matchPage) || ($matchDir && $current_dir === $matchDir);
    $classes  = $isActive
        ? 'bg-red-700 font-semibold'
        : 'hover:bg-red-800';
    return "
        <a href=\"{$href}\" class=\"flex items-center px-4 py-3 rounded-lg transition-colors {$classes}\">
            <span class=\"mr-3\"><i class=\"fa-solid {$icon}\"></i></span> {$label}
        </a>";
}
?>

<aside class="fixed top-0 left-0 h-full w-64 bg-red-900 text-white flex flex-col justify-between shadow-2xl z-40">

   <!-- Branding -->
<div class="bg-red-800 px-6 py-6 border-b border-red-700">
    <h1 class="text-2xl font-bold" style="font-family: 'Noto Nastaliq Urdu', serif;">WMSU</h1>
    <p class="text-xs text-red-300 mt-1" style="font-family: 'IBM Plex Sans', sans-serif;">Document Management</p>
</div>

    <!-- Navigation -->
    <nav class="px-4 py-6 flex-1">
        <ul class="space-y-2">
            <li><?= navLink('dashboard/dashboard.php', 'fa-house',         'Dashboard', 'dashboard.php', 'dashboard') ?></li>
            <li><?= navLink('/archive.php',             'fa-archive',       'Archive',   'archive.php') ?></li>
            <li><?= navLink('../main.php',                'fa-receipt',       'Receiving', 'main.php') ?></li>
            <li><?= navLink('#',                        'fa-boxes-stacked', 'Inventory', '') ?></li>
        </ul>

        <hr class="my-6 border-red-700">

        <!-- User Info -->
        <div class="px-4 py-3 mb-3 bg-red-800 rounded-lg">
            <p class="text-xs text-red-300 mb-1">Logged in as</p>
            <p class="text-sm font-semibold truncate">
                <?= htmlspecialchars($_SESSION['user_email'] ?? 'Unknown') ?>
            </p>
            <p class="text-xs text-red-300 capitalize">
                <?= htmlspecialchars($_SESSION['user_role'] ?? 'viewer') ?>
            </p>
        </div>

        <!-- Logout -->
        <a href="/logout.php" class="flex items-center px-4 py-3 rounded-lg hover:bg-red-800 transition-colors">
            <span class="mr-3"><i class="fa-solid fa-right-from-bracket"></i></span> Logout
        </a>
    </nav>

    <!-- Bottom email button -->
    <div class="px-4 py-6 flex justify-center">
        <button class="bg-red-700 w-12 h-12 rounded-full flex items-center justify-center shadow-lg hover:bg-red-600 transition-colors">
            <i class="fa-solid fa-envelope text-white"></i>
        </button>
    </div>
</aside>

<!-- ===== SESSION TIMEOUT WARNING MODAL ===== -->
<div id="sessionModal"
     class="fixed inset-0 bg-black bg-opacity-60 hidden items-center justify-center z-50">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-sm mx-4 overflow-hidden">

        <!-- Modal Header -->
        <div class="bg-red-700 px-6 py-5 text-white text-center">
            <div class="w-14 h-14 bg-white bg-opacity-20 rounded-full flex items-center justify-center mx-auto mb-3">
                <i class="fa-solid fa-clock text-2xl text-white"></i>
            </div>
            <h2 class="text-xl font-bold">Session Expiring</h2>
            <p class="text-red-200 text-xs mt-1">You've been inactive for a while</p>
        </div>

        <!-- Modal Body -->
        <div class="px-6 py-6 text-center">
            <p class="text-gray-500 text-sm mb-2">Your session will expire in</p>
            <p class="text-5xl font-bold text-red-700 my-3" id="countdownTimer">60</p>
            <p class="text-gray-400 text-xs mb-3">seconds</p>
            <p class="text-gray-500 text-sm">
                Click <strong class="text-gray-700">Stay Logged In</strong> to continue your session.
            </p>
        </div>

        <!-- Modal Actions -->
        <div class="px-6 pb-6 flex gap-3">
            <button onclick="logoutNow()"
                class="flex-1 py-2.5 rounded-lg border border-gray-300 text-gray-600
                       hover:bg-gray-100 text-sm font-semibold transition-colors">
                Logout Now
            </button>
            <button onclick="stayLoggedIn()"
                class="flex-1 py-2.5 rounded-lg bg-red-700 text-white
                       hover:bg-red-800 text-sm font-semibold transition-colors">
                Stay Logged In
            </button>
        </div>

    </div>
</div>

<!-- Fonts (loaded here so sidebar font is consistent on every page) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Nastaliq+Urdu:wght@400;700&family=IBM+Plex+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<script>
    const TIMEOUT_MINUTES = 30;       // Match SESSION_TIMEOUT in auth.php (1800s = 30min)
    const WARNING_SECONDS = 60;       // Show modal this many seconds before expiry
    const PING_URL        = '/auth-guard/ping.php';

    let idleTimer;
    let countdownInterval;

    function resetIdleTimer() {
        clearTimeout(idleTimer);
        const delay = (TIMEOUT_MINUTES * 60 - WARNING_SECONDS) * 1000;
        idleTimer = setTimeout(showWarningModal, delay);
    }

    function showWarningModal() {
        const modal = document.getElementById('sessionModal');
        modal.classList.remove('hidden');
        modal.classList.add('flex');

        let countdown = WARNING_SECONDS;
        document.getElementById('countdownTimer').textContent = countdown;

        countdownInterval = setInterval(() => {
            countdown--;
            document.getElementById('countdownTimer').textContent = countdown;
            if (countdown <= 0) {
                clearInterval(countdownInterval);
                logoutNow();
            }
        }, 1000);
    }

    function stayLoggedIn() {
        clearInterval(countdownInterval);

        const modal = document.getElementById('sessionModal');
        modal.classList.add('hidden');
        modal.classList.remove('flex');

        // Ping server to refresh $_SESSION['last_activity']
        fetch(PING_URL)
            .then(res => res.json())
            .then(data => {
                if (data.status === 'expired') logoutNow();
                else resetIdleTimer();
            })
            .catch(() => resetIdleTimer());
    }

    function logoutNow() {
        window.location.href = '/logout.php';
    }

    // Reset timer on any user activity
    ['mousemove', 'keydown', 'click', 'scroll', 'touchstart'].forEach(e => {
        document.addEventListener(e, resetIdleTimer, { passive: true });
    });

    // Start on page load
    resetIdleTimer();
</script>