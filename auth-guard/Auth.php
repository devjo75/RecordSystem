<?php
/**
 * auth.php — Core Authentication Guard
 * Include this at the TOP of every protected page.
 * Usage: require_once 'auth.php';
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Session timeout duration (in seconds)
define('SESSION_TIMEOUT', 1800); // 30 minutes
define('SESSION_WARNING', 60);   // Show warning 60 seconds before expiry

// If not logged in, redirect to login
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php?reason=unauthenticated');
    exit;
}

// Check session timeout
if (isset($_SESSION['last_activity'])) {
    $elapsed = time() - $_SESSION['last_activity'];

    if ($elapsed > SESSION_TIMEOUT) {
        // Expired — destroy session and redirect
        session_unset();
        session_destroy();
        session_start();
        $_SESSION['session_expired'] = true;
        header('Location: login.php?reason=expired');
        exit;
    }
}

// Update last activity timestamp
$_SESSION['last_activity'] = time();

// Ensure role is set; default to 'viewer' if missing
if (empty($_SESSION['user_role'])) {
    $_SESSION['user_role'] = 'viewer';
}

/**
 * Helper: Check if current user is Admin
 */
function is_admin(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'admin';
}

/**
 * Helper: Check if current user is Viewer
 */
function is_viewer(): bool {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === 'viewer';
}

/**
 * Helper: Get current user's email
 */
function current_user(): string {
    return $_SESSION['user_email'] ?? 'Unknown';
}

/**
 * Helper: Get current role (formatted)
 */
function current_role(): string {
    return ucfirst($_SESSION['user_role'] ?? 'viewer');
}