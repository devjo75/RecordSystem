<?php
/**
 * auth.php — Core Authentication Guard
 * Include this at the TOP of every protected page.
 * Usage: require_once 'auth.php';
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// If not logged in, redirect to login
if (empty($_SESSION['logged_in']) || $_SESSION['logged_in'] !== true) {
    header('Location: login.php?reason=unauthenticated');
    exit;
}

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
 * Helper: Get current user's role (formatted)
 */
function current_role(): string {
    return ucfirst($_SESSION['user_role'] ?? 'viewer');
}