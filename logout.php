<?php
require_once __DIR__ . '/includes/bootstrap.php';

// Logging out changes state, so require a POST with a valid CSRF token.
$token = $_POST['_csrf'] ?? null;
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && is_string($token) && csrf_valid($token)) {
    logout_user();
    session_start();
    flash('info', 'You have been signed out.');
}

redirect('login.php');
