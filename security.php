<?php
/**
 * Security bootstrap — include this at the top of every entry-point PHP file.
 *
 * Handles:
 *  - Secure session configuration (HttpOnly, Secure, SameSite)
 *  - Security response headers
 *  - Session idle timeout
 *  - Login rate-limiting helpers
 */

// ─── Session Security ────────────────────────────────────────────────────────
// Must be called BEFORE session_start().
function configureSecureSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return; // already started
    }

    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => 0,            // session cookie (cleared on browser close)
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isHttps,     // only send over HTTPS
        'httponly'  => true,         // not accessible via JavaScript
        'samesite'  => 'Strict',    // prevents CSRF via cross-site requests
    ]);

    session_name('FLIPBOOK_SESSID');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
}

// ─── Session Idle Timeout ────────────────────────────────────────────────────
define('SESSION_IDLE_TIMEOUT', 1800); // 30 minutes

function enforceSessionTimeout(): void
{
    if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
        return;
    }

    $now = time();

    if (isset($_SESSION['last_activity']) && ($now - $_SESSION['last_activity']) > SESSION_IDLE_TIMEOUT) {
        // Session expired — destroy it
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        return;
    }

    $_SESSION['last_activity'] = $now;
}

// ─── Security Headers ────────────────────────────────────────────────────────
function sendSecurityHeaders(): void
{
    // Prevent clickjacking
    header('X-Frame-Options: DENY');

    // Prevent MIME-type sniffing
    header('X-Content-Type-Options: nosniff');

    // Referrer policy — only send origin for cross-origin requests
    header('Referrer-Policy: strict-origin-when-cross-origin');

    // Permissions policy — disable dangerous browser features
    header('Permissions-Policy: camera=(), microphone=(), geolocation=()');

    // XSS protection (legacy browsers)
    header('X-XSS-Protection: 1; mode=block');

    // Cache control for authenticated pages
    if (isset($_SESSION['admin_logged_in']) && $_SESSION['admin_logged_in'] === true) {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
    }
}

// ─── Login Rate Limiting ─────────────────────────────────────────────────────
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCKOUT_SECONDS', 900); // 15 minutes
define('LOGIN_ATTEMPTS_DIR', __DIR__ . '/tmp/login_attempts');

function getLoginAttemptsFile(string $ip): string
{
    $dir = LOGIN_ATTEMPTS_DIR;
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }

    // Use a hash so filenames are safe and uniform
    return $dir . '/' . hash('sha256', $ip) . '.json';
}

function isLoginRateLimited(string $ip): bool
{
    $file = getLoginAttemptsFile($ip);
    if (!is_file($file)) {
        return false;
    }

    $data = @json_decode((string) @file_get_contents($file), true);
    if (!is_array($data)) {
        return false;
    }

    $attempts = (int) ($data['attempts'] ?? 0);
    $lastAttempt = (int) ($data['last_attempt'] ?? 0);

    // If lockout period has expired, reset
    if ((time() - $lastAttempt) > LOGIN_LOCKOUT_SECONDS) {
        @unlink($file);
        return false;
    }

    return $attempts >= LOGIN_MAX_ATTEMPTS;
}

function recordFailedLogin(string $ip): void
{
    $file = getLoginAttemptsFile($ip);

    $data = ['attempts' => 0, 'last_attempt' => 0];
    if (is_file($file)) {
        $existing = @json_decode((string) @file_get_contents($file), true);
        if (is_array($existing)) {
            // Reset if lockout has expired
            if ((time() - (int) ($existing['last_attempt'] ?? 0)) > LOGIN_LOCKOUT_SECONDS) {
                $data = ['attempts' => 0, 'last_attempt' => 0];
            } else {
                $data = $existing;
            }
        }
    }

    $data['attempts'] = ((int) ($data['attempts'] ?? 0)) + 1;
    $data['last_attempt'] = time();

    @file_put_contents($file, json_encode($data), LOCK_EX);
}

function clearFailedLogins(string $ip): void
{
    $file = getLoginAttemptsFile($ip);
    if (is_file($file)) {
        @unlink($file);
    }
}

function getClientIp(): string
{
    return trim((string) ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'));
}

// ─── Cleanup stale lockout files (called occasionally) ───────────────────────
function cleanupStaleLockoutFiles(): void
{
    $dir = LOGIN_ATTEMPTS_DIR;
    if (!is_dir($dir)) {
        return;
    }

    $files = @glob($dir . '/*.json');
    if (!is_array($files)) {
        return;
    }

    $cutoff = time() - LOGIN_LOCKOUT_SECONDS - 3600; // 1 hour grace
    foreach ($files as $file) {
        if (is_file($file) && filemtime($file) < $cutoff) {
            @unlink($file);
        }
    }
}
