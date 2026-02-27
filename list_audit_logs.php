<?php
require 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

function getIntParam(string $key, int $default, int $min, int $max): int
{
    $value = filter_input(INPUT_GET, $key, FILTER_VALIDATE_INT);
    if ($value === false || $value === null) {
        return $default;
    }

    $value = (int) $value;
    if ($value < $min) {
        return $min;
    }
    if ($value > $max) {
        return $max;
    }
    return $value;
}

$page = getIntParam('page', 1, 1, 100000);
$perPage = getIntParam('per_page', 10, 1, 100);
$offset = ($page - 1) * $perPage;

$action = trim((string) (filter_input(INPUT_GET, 'action', FILTER_UNSAFE_RAW) ?? ''));
$action = substr($action, 0, 64);

$whereParts = [];
$params = [];

if ($action !== '') {
    $whereParts[] = 'a.action = :action';
    $params[':action'] = $action;
}

$whereClause = count($whereParts) > 0 ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

try {
    $countSql = "SELECT COUNT(*) FROM admin_audit_trail a $whereClause";
    $countStmt = $pdo->prepare($countSql);
    foreach ($params as $key => $value) {
        $countStmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $countStmt->execute();
    $total = (int) $countStmt->fetchColumn();

    $totalPages = $total > 0 ? (int) ceil($total / $perPage) : 1;
    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $dataSql = "
        SELECT
            a.id,
            a.admin_user_id,
            a.action,
            a.book_id,
            a.details_json,
            a.ip_address,
            a.user_agent,
            a.created_at,
            u.username AS admin_username,
            b.title AS book_title
        FROM admin_audit_trail a
        LEFT JOIN users u ON u.id = a.admin_user_id
        LEFT JOIN books b ON b.id = a.book_id
        $whereClause
        ORDER BY a.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $logs = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'logs' => $logs,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasPrev' => $page > 1,
            'hasNext' => $page < $totalPages,
        ],
        'filters' => [
            'action' => $action,
        ],
    ]);
} catch (PDOException $e) {
    if (stripos($e->getMessage(), 'admin_audit_trail') !== false) {
        http_response_code(503);
        echo json_encode([
            'success' => false,
            'message' => 'Audit table is missing. Run migration 2026-02-25-admin-audit-trail.sql first.',
        ]);
        exit;
    }

    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch audit logs.']);
}

