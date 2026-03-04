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

$confirmPhrase = trim((string) ($_POST['confirm_phrase'] ?? ''));
if ($confirmPhrase !== 'DELETE') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Confirmation phrase is required.']);
    exit;
}

function isValidStoredPdfPath(string $path): bool
{
    return preg_match('#^uploads/[a-zA-Z0-9._/\- ]+\.pdf$#i', $path) === 1;
}

function resolveSafeUploadPath(string $storedPath): ?string
{
    if (!isValidStoredPdfPath($storedPath)) {
        return null;
    }

    $baseUploads = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    if ($baseUploads === false) {
        return null;
    }

    $fullPath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $storedPath);
    $directory = realpath(dirname($fullPath));
    if ($directory === false || strpos($directory, $baseUploads) !== 0) {
        return null;
    }

    return $fullPath;
}

function isPathStillReferenced(PDO $pdo, string $storedPath): bool
{
    $checkSql = '
        SELECT 1
        FROM (
            SELECT file_path FROM books
            UNION ALL
            SELECT file_path FROM book_versions
        ) AS refs
        WHERE refs.file_path = ?
        LIMIT 1
    ';
    $stmt = $pdo->prepare($checkSql);
    $stmt->execute([$storedPath]);
    return (bool) $stmt->fetchColumn();
}

try {
    $adminUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;

    $bookStmt = $pdo->prepare('SELECT id, title, file_path, deleted_at FROM books WHERE id = ? LIMIT 1');
    $bookStmt->execute([$bookId]);
    $book = $bookStmt->fetch();

    if (!$book) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Book not found.']);
        exit;
    }

    if (empty($book['deleted_at'])) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Book must be in trash before permanent deletion.']);
        exit;
    }

    $pathStmt = $pdo->prepare('SELECT file_path FROM book_versions WHERE book_id = ?');
    $pathStmt->execute([$bookId]);
    $paths = $pathStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $paths[] = $book['file_path'];
    $candidatePaths = array_values(array_unique(array_filter($paths, static function ($value) {
        return is_string($value) && $value !== '';
    })));

    $pdo->beginTransaction();

    $deleteStmt = $pdo->prepare('DELETE FROM books WHERE id = ? AND deleted_at IS NOT NULL');
    $deleteStmt->execute([$bookId]);
    if ($deleteStmt->rowCount() < 1) {
        $pdo->rollBack();
        http_response_code(409);
        echo json_encode(['success' => false, 'message' => 'Book could not be permanently deleted.']);
        exit;
    }

    writeAdminAuditLog($pdo, 'hard_delete', $adminUserId, (int) $bookId, [
        'title' => $book['title'] ?? null,
        'candidate_file_count' => count($candidatePaths),
    ]);

    $pdo->commit();

    $deletedFiles = [];
    $skippedFiles = [];
    $missingFiles = [];

    foreach ($candidatePaths as $storedPath) {
        if (isPathStillReferenced($pdo, $storedPath)) {
            $skippedFiles[] = $storedPath;
            continue;
        }

        $fullPath = resolveSafeUploadPath($storedPath);
        if ($fullPath === null || !is_file($fullPath)) {
            $missingFiles[] = $storedPath;
            continue;
        }

        if (@unlink($fullPath)) {
            $deletedFiles[] = $storedPath;
        } else {
            $skippedFiles[] = $storedPath;
        }
    }

    echo json_encode([
        'success' => true,
        'message' => 'Book permanently deleted.',
        'deletedFilesCount' => count($deletedFiles),
        'missingFilesCount' => count($missingFiles),
        'skippedFilesCount' => count($skippedFiles),
    ]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to permanently delete book.']);
}

