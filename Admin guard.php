<?php
/**
 * admin_guard.php — Admin Role Guard
 * Include this AFTER auth.php on pages only admins can access.
 * Usage:
 *   require_once 'auth.php';
 *   require_once 'admin_guard.php';
 */

if (!function_exists('is_admin')) {
    require_once __DIR__ . '/auth.php';
}

if (!is_admin()) {
    // Store a flash message for main.php to display
    $_SESSION['flash_error'] = 'Access denied. You do not have permission to perform that action.';
    header('Location: main.php?error=unauthorized');
    exit;
}