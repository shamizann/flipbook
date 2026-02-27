<?php
require 'db.php';
require_once 'csrf.php';
require_once 'audit.php';
session_start();

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

$title = trim((string) ($_POST['title'] ?? ''));
if ($title === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Title is required.']);
    exit;
}

$titleLength = function_exists('mb_strlen') ? mb_strlen($title, 'UTF-8') : strlen($title);
if ($titleLength > 255) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Title is too long (max 255 characters).']);
    exit;
}

try {
    $adminUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;

    $bookStmt = $pdo->prepare('SELECT id, title FROM books WHERE id = ? LIMIT 1');
    $bookStmt->execute([$bookId]);
    $book = $bookStmt->fetch();

    if (!$book) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Book not found.']);
        exit;
    }

    $currentTitle = (string) ($book['title'] ?? '');
    if ($currentTitle === $title) {
        echo json_encode([
            'success' => true,
            'message' => 'Title is unchanged.',
            'bookId' => (int) $bookId,
            'title' => $currentTitle,
        ]);
        exit;
    }

    $updateStmt = $pdo->prepare('UPDATE books SET title = ? WHERE id = ?');
    if (!$updateStmt->execute([$title, $bookId])) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Failed to update title.']);
        exit;
    }

    writeAdminAuditLog($pdo, 'update_title', $adminUserId, (int) $bookId, [
        'old_title' => $currentTitle,
        'new_title' => $title,
    ]);

    echo json_encode([
        'success' => true,
        'message' => 'Title updated.',
        'bookId' => (int) $bookId,
        'title' => $title,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to update title.']);
}

