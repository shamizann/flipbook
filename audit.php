<?php

function getAuditClientIp(): ?string
{
    $value = $_SERVER['REMOTE_ADDR'] ?? null;
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    return substr($value, 0, 45);
}

function getAuditUserAgent(): ?string
{
    $value = $_SERVER['HTTP_USER_AGENT'] ?? null;
    if (!is_string($value)) {
        return null;
    }

    $value = trim($value);
    if ($value === '') {
        return null;
    }

    return substr($value, 0, 255);
}

function writeAdminAuditLog(PDO $pdo, string $action, ?int $adminUserId, ?int $bookId = null, ?array $details = null): void
{
    $normalizedAction = trim($action);
    if ($normalizedAction === '') {
        return;
    }

    $detailsJson = null;
    if ($details !== null) {
        $encoded = json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if (is_string($encoded)) {
            $detailsJson = $encoded;
        }
    }

    try {
        $stmt = $pdo->prepare('
            INSERT INTO admin_audit_trail (admin_user_id, action, book_id, details_json, ip_address, user_agent)
            VALUES (?, ?, ?, ?, ?, ?)
        ');
        $stmt->execute([
            $adminUserId,
            $normalizedAction,
            $bookId,
            $detailsJson,
            getAuditClientIp(),
            getAuditUserAgent(),
        ]);
    } catch (Throwable $e) {
        // Do not block user actions if audit table is unavailable.
    }
}

