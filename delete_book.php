<?php
require_once 'security.php';
configureSecureSession();
require 'db.php';
require_once 'csrf.php';
require_once 'audit.php';
session_start();
enforceSessionTimeout();
sendSecurityHeaders();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

requireCsrfTokenForJsonPost();

$bookId = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);
if (!$bookId || $bookId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid book id.']);
    exit;
}

try {
    $adminUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;

    $stmt = $pdo->prepare('SELECT id, title, deleted_at FROM books WHERE id = ? LIMIT 1');
    $stmt->execute([$bookId]);
    $book = $stmt->fetch();

    if (!$book) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Book not found.']);
        exit;
    }

    if (!empty($book['deleted_at'])) {
        echo json_encode(['success' => true, 'message' => 'Book is already in trash.']);
        exit;
    }

    $trashStmt = $pdo->prepare('UPDATE books SET deleted_at = NOW(), deleted_by_user_id = ? WHERE id = ?');
    if (!$trashStmt->execute([$adminUserId, $bookId])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to move book to trash.']);
        exit;
    }

    $response = [
        'success' => true,
        'message' => 'Book moved to trash.',
    ];
    writeAdminAuditLog($pdo, 'trash', $adminUserId, (int) $bookId, [
        'title' => $book['title'] ?? null,
    ]);

    echo json_encode($response);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update book status.']);
}
?>
