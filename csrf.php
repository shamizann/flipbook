<?php

function getCsrfToken(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    if (
        !isset($_SESSION['csrf_token'])
        || !is_string($_SESSION['csrf_token'])
        || strlen($_SESSION['csrf_token']) < 32
    ) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function isValidCsrfToken(?string $token): bool
{
    if (!is_string($token) || $token === '') {
        return false;
    }

    $sessionToken = $_SESSION['csrf_token'] ?? null;
    if (!is_string($sessionToken) || $sessionToken === '') {
        return false;
    }

    return hash_equals($sessionToken, $token);
}

function requireCsrfTokenForJsonPost(): void
{
    $token = $_POST['csrf_token'] ?? null;
    if (isValidCsrfToken(is_string($token) ? $token : null)) {
        return;
    }

    http_response_code(419);
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Invalid CSRF token.']);
    exit;
}

