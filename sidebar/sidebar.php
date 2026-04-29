<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// BASE PATH (IMPORTANT)
$base = '/WMSU-Receive-System/';

// Detect current page + folder
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

// NAV LINK FUNCTION
function navLink($href, $icon, $label, $matchPage, $matchDir = '') {
    global $current_page, $current_dir;

    $isActive = ($current_page === $matchPage) || ($matchDir && $current_dir === $matchDir);

    $classes = $isActive
        ? 'bg-red-700 font-semibold'
        : 'hover:bg-red-800';

    return "
        <a href=\"{$href}\" class=\"flex items-center px-4 py-3 rounded-lg transition-colors {$classes}\">
            <span class=\"mr-3\"><i class=\"fa-solid {$icon}\"></i></span> {$label}
        </a>
    ";
}

// RECEIVING ACTIVE (main.php + inbox)
$isReceivingActive = (
    $current_page === 'main.php' ||
    $current_dir === 'inbox'
);
?>

<aside class="fixed top-0 left-0 h-full w-64 bg-red-900 text-white flex flex-col justify-between shadow-2xl z-40">

    <!-- Branding -->
    <div class="bg-red-800 px-6 py-6 border-b border-red-700">
        <h1 class="text-2xl font-bold">WMSU</h1>
        <p class="text-xs text-red-300 mt-1">Document Management</p>
    </div>

    <!-- Navigation -->
    <nav class="px-4 py-6 flex-1">
        <ul class="space-y-2">

            <!-- DASHBOARD -->
            <li>
                <?= navLink($base . 'dashboard/dashboard.php', 'fa-house', 'Dashboard', 'dashboard.php', 'dashboard') ?>
            </li>

            <!-- ARCHIVE -->
            <li>
                <?= navLink($base . 'archive.php', 'fa-archive', 'Archive', 'archive.php') ?>
            </li>

            <!-- RECEIVING (CLICKABLE + DROPDOWN) -->
            <li>

                <div class="flex items-center">

                    <!-- CLICKABLE MAIN -->
                    <a href="<?= $base ?>main/main.php"
                       class="flex-1 flex items-center px-4 py-3 rounded-l-lg transition-colors <?= $isReceivingActive ? 'bg-red-700 font-semibold' : 'hover:bg-red-800' ?>">
                        
                        <span class="mr-3"><i class="fa-solid fa-receipt"></i></span>
                        Receiving
                    </a>

                    <!-- DROPDOWN BUTTON -->
                    <button onclick="toggleDropdown('receivingMenu')" 
                        class="px-3 py-3 rounded-r-lg <?= $isReceivingActive ? 'bg-red-700' : 'hover:bg-red-800' ?>">
                        
                        <i id="receivingArrow"
                           class="fa-solid fa-chevron-down text-sm transition-transform <?= $isReceivingActive ? 'rotate-180' : '' ?>">
                        </i>
                    </button>

                </div>

                <!-- DROPDOWN MENU -->
                <ul id="receivingMenu" class="<?= $isReceivingActive ? '' : 'hidden' ?> ml-6 mt-2 space-y-1">

                    <li>
                        <a href="<?= $base ?>inbox/inbox.php"
                           class="block px-4 py-2 rounded-lg text-sm <?= ($current_page === 'inbox.php') ? 'bg-red-700 font-semibold' : 'hover:bg-red-700' ?>">
                           Inbox
                        </a>
                    </li>
                    <li>
                        <a href="<?= $base ?>release/release.php"
                           class="block px-4 py-2 rounded-lg text-sm <?= ($current_page === 'release.php') ? 'bg-red-700 font-semibold' : 'hover:bg-red-700' ?>">
                           Release
                        </a>
                    </li>

                </ul>

            </li>

            <!-- INVENTORY -->
            <li>
                <?= navLink('#', 'fa-boxes-stacked', 'Inventory', '') ?>
            </li>

        </ul>

        <hr class="my-6 border-red-700">

        <!-- USER INFO -->
        <div class="px-4 py-3 mb-3 bg-red-800 rounded-lg">
            <p class="text-xs text-red-300 mb-1">Logged in as</p>
            <p class="text-sm font-semibold truncate">
                <?= htmlspecialchars($_SESSION['user_email'] ?? 'Unknown') ?>
            </p>
            <p class="text-xs text-red-300 capitalize">
                <?= htmlspecialchars($_SESSION['user_role'] ?? 'viewer') ?>
            </p>
        </div>

        <!-- LOGOUT -->
        <a href="<?= $base ?>logout.php" class="flex items-center px-4 py-3 rounded-lg hover:bg-red-800 transition-colors">
            <span class="mr-3"><i class="fa-solid fa-right-from-bracket"></i></span> Logout
        </a>
    </nav>

    <!-- BOTTOM BUTTON -->
    <div class="px-4 py-6 flex justify-center">
        <button class="bg-red-700 w-12 h-12 rounded-full flex items-center justify-center shadow-lg hover:bg-red-600 transition-colors">
            <i class="fa-solid fa-envelope text-white"></i>
        </button>
    </div>
</aside>

<!-- FONT AWESOME -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

<script>
function toggleDropdown(id) {
    const menu = document.getElementById(id);
    const arrow = document.getElementById('receivingArrow');

    menu.classList.toggle('hidden');
    arrow.classList.toggle('rotate-180');
}
</script>