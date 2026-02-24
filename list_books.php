<?php
require 'db.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

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

$query = trim((string) (filter_input(INPUT_GET, 'q', FILTER_UNSAFE_RAW) ?? ''));
$query = substr($query, 0, 150);

$status = strtolower((string) (filter_input(INPUT_GET, 'status', FILTER_UNSAFE_RAW) ?? 'active'));
if (!in_array($status, ['active', 'trashed', 'all'], true)) {
    $status = 'active';
}

$whereParts = [];
$params = [];

if ($status === 'active') {
    $whereParts[] = 'b.deleted_at IS NULL';
} elseif ($status === 'trashed') {
    $whereParts[] = 'b.deleted_at IS NOT NULL';
}

if ($query !== '') {
    $whereParts[] = '(b.title LIKE :search OR b.file_name LIKE :search)';
    $params[':search'] = '%' . $query . '%';
}

$whereClause = count($whereParts) > 0 ? ('WHERE ' . implode(' AND ', $whereParts)) : '';

try {
    $countSql = "SELECT COUNT(*) FROM books b $whereClause";
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
            b.id,
            b.title,
            b.file_name,
            b.file_path,
            b.file_size_bytes,
            b.page_count,
            b.uploaded_at,
            b.created_by_user_id,
            b.deleted_at,
            b.deleted_by_user_id,
            COALESCE(vmax.max_version, 1) AS latest_version,
            COALESCE(vlatest.created_at, b.uploaded_at) AS latest_at,
            COALESCE(vlatest.file_size_bytes, b.file_size_bytes) AS latest_file_size_bytes,
            COALESCE(vlatest.page_count, b.page_count) AS latest_page_count,
            COALESCE(vlatest.uploaded_by_user_id, b.created_by_user_id) AS latest_uploaded_by_user_id,
            COALESCE(u_latest.username, u_created.username) AS latest_uploaded_by_username,
            u_created.username AS created_by_username,
            u_deleted.username AS deleted_by_username
        FROM books b
        LEFT JOIN (
            SELECT book_id, MAX(version_number) AS max_version
            FROM book_versions
            GROUP BY book_id
        ) AS vmax ON vmax.book_id = b.id
        LEFT JOIN book_versions vlatest
            ON vlatest.book_id = b.id
            AND vlatest.version_number = vmax.max_version
        LEFT JOIN users u_latest ON u_latest.id = vlatest.uploaded_by_user_id
        LEFT JOIN users u_created ON u_created.id = b.created_by_user_id
        LEFT JOIN users u_deleted ON u_deleted.id = b.deleted_by_user_id
        $whereClause
        ORDER BY COALESCE(vlatest.created_at, b.uploaded_at) DESC, b.id DESC
        LIMIT :limit OFFSET :offset
    ";

    $stmt = $pdo->prepare($dataSql);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();

    $books = $stmt->fetchAll();

    echo json_encode([
        'success' => true,
        'books' => $books,
        'pagination' => [
            'page' => $page,
            'perPage' => $perPage,
            'total' => $total,
            'totalPages' => $totalPages,
            'hasPrev' => $page > 1,
            'hasNext' => $page < $totalPages,
        ],
        'filters' => [
            'q' => $query,
            'status' => $status,
        ],
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to fetch books list.']);
}
?>
