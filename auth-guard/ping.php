<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

if (!empty($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    $_SESSION['last_activity'] = time(); // Refresh timer
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(401);
    echo json_encode(['status' => 'expired']);
}