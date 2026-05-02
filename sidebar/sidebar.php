<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// BASE PATH (IMPORTANT)
// Dynamically detect the app's subfolder so links work on any host/subdirectory.
$_app_root = rtrim(str_replace('\\', '/', realpath(__DIR__ . '/..')), '/');
$_doc_root = rtrim(str_replace('\\', '/', realpath($_SERVER['DOCUMENT_ROOT'])), '/');
$_rel = str_replace($_doc_root, '', $_app_root);
$base = rtrim($_rel, '/') . '/';

// Detect current page + folder
$current_page = basename($_SERVER['PHP_SELF']);
$current_dir  = basename(dirname($_SERVER['PHP_SELF']));

// Fetch unread inbox count for the current user
if (!isset($inbox_unread) && !empty($_SESSION['user_email'])) {
    if (function_exists('getPDO')) {
        try {
            $pdo_sidebar = getPDO();
            $s = $pdo_sidebar->prepare(
                "SELECT COUNT(*) FROM document_recipients
                 WHERE recipient_email = ? AND status IN ('Pending', 'Sent')"
            );
            $s->execute([$_SESSION['user_email']]);
            $inbox_unread = (int) $s->fetchColumn();
        } catch (Exception $e) {
            $inbox_unread = 0;
        }
    } else {
        $inbox_unread = 0;
    }
}

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

// INBOX NAV LINK
function inboxNavLink($href, $matchPage) {
    global $current_page, $inbox_unread;

    $isActive = ($current_page === $matchPage);
    $classes  = $isActive ? 'bg-red-700 font-semibold' : 'hover:bg-red-800';

    $badge = (!empty($inbox_unread) && $inbox_unread > 0)
        ? '<span class="ml-auto bg-white text-red-900 text-xs font-bold rounded-full min-w-[20px] h-5 flex items-center justify-center px-1.5 leading-none">'
          . ($inbox_unread > 99 ? '99+' : $inbox_unread)
          . '</span>'
        : '';

    return "
        <a href=\"{$href}\" class=\"flex items-center px-4 py-3 rounded-lg transition-colors {$classes}\">
            <span class=\"mr-3\"><i class=\"fa-solid fa-inbox\"></i></span>
            Inbox
            {$badge}
        </a>
    ";
}
?>

<!-- Mobile overlay backdrop -->
<div id="sidebarOverlay" class="fixed inset-0 bg-black/50 z-30 hidden lg:hidden" onclick="closeSidebar()"></div>

<aside id="sidebar" class="fixed top-0 left-0 h-full w-64 bg-red-900 text-white flex flex-col justify-between shadow-2xl z-40 -translate-x-full lg:translate-x-0 transition-transform duration-300">

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

            <!-- RECEIVING -->
            <li>
                <?= navLink($base . 'pages/receiving.php', 'fa-receipt', 'Receiving', 'receiving.php') ?>
            </li>

            <!-- INBOX -->
            <li>
                <?= inboxNavLink($base . 'pages/inbox.php', 'inbox.php') ?>
            </li>

            <!-- RELEASE -->
            <li>
                <?= navLink($base . 'pages/release.php', 'fa-paper-plane', 'Release', 'release.php') ?>
            </li>

            <!-- USER MANAGEMENT -->
           <?= navLink($base . 'pages/user_management.php', 'fa-users', 'User Management', 'user_management.php') ?>

            <!-- TRASH -->
            <li>
                <?= navLink($base . 'pages/trash.php', 'fa-trash-can', 'Trash', 'trash.php') ?>
            </li>

        </ul>
    </nav>

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
</aside>

<!-- FONT AWESOME -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">