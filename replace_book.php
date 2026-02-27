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

$response = ['success' => false, 'message' => ''];
$maxUploadBytes = 50 * 1024 * 1024; // 50 MB
$adminUserId = isset($_SESSION['admin_user_id']) ? (int) $_SESSION['admin_user_id'] : null;

function getPdfPageCount(string $filePath): ?int
{
    $content = @file_get_contents($filePath);
    if ($content === false) {
        return null;
    }

    $matches = [];
    $count = preg_match_all('/\/Type\s*\/Page\b/', $content, $matches);
    if ($count === false || $count <= 0) {
        return null;
    }

    return $count;
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

if (!isset($_FILES['pdf_file']) || $_FILES['pdf_file']['error'] !== UPLOAD_ERR_OK) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'No file uploaded or upload error.']);
    exit;
}

$uploadDir = 'uploads/';
if (!is_dir($uploadDir)) {
    if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Upload directory is not available.']);
        exit;
    }
}

$uploadedFile = $_FILES['pdf_file'];
$originalFileName = basename($uploadedFile['name']);
$fileSize = (int) $uploadedFile['size'];

if ($fileSize <= 0 || $fileSize > $maxUploadBytes) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid file size. Max upload is 50 MB.']);
    exit;
}

if (!is_uploaded_file($uploadedFile['tmp_name'])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid upload source.']);
    exit;
}

$extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mimeType = $finfo->file($uploadedFile['tmp_name']) ?: '';

if ($extension !== 'pdf' || $mimeType !== 'application/pdf') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Only valid PDF files are allowed.']);
    exit;
}

$storedFileName = bin2hex(random_bytes(16)) . '.pdf';
$targetPath = $uploadDir . $storedFileName;

if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to move uploaded file.']);
    exit;
}

chmod($targetPath, 0644);
$storedFileSize = filesize($targetPath);
$fileSizeBytes = $storedFileSize !== false ? (int) $storedFileSize : null;
$pageCount = getPdfPageCount($targetPath);

try {
    $pdo->beginTransaction();

    $bookStmt = $pdo->prepare('SELECT id, title, file_name, file_path, deleted_at FROM books WHERE id = ? LIMIT 1');
    $bookStmt->execute([$bookId]);
    $book = $bookStmt->fetch();

    if (!$book) {
        $pdo->rollBack();
        if (is_file($targetPath)) {
            unlink($targetPath);
        }
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Book not found.']);
        exit;
    }

    if (!empty($book['deleted_at'])) {
        $pdo->rollBack();
        if (is_file($targetPath)) {
            unlink($targetPath);
        }
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Book is in trash. Restore it before replacing.']);
        exit;
    }

    $maxVersionStmt = $pdo->prepare('SELECT COALESCE(MAX(version_number), 0) AS max_version FROM book_versions WHERE book_id = ?');
    $maxVersionStmt->execute([$bookId]);
    $maxVersion = (int) ($maxVersionStmt->fetch()['max_version'] ?? 0);

    // Backfill missing version row for older records.
    if ($maxVersion === 0) {
        $seedStmt = $pdo->prepare('
            INSERT INTO book_versions (book_id, version_number, file_name, file_path, file_size_bytes, page_count, uploaded_by_user_id, created_at)
            VALUES (?, ?, ?, ?, NULL, NULL, ?, NOW())
        ');
        $seedStmt->execute([$bookId, 1, $book['file_name'], $book['file_path'], $adminUserId]);
        $maxVersion = 1;
    }

    $nextVersion = $maxVersion + 1;

    $insertVersionStmt = $pdo->prepare('
        INSERT INTO book_versions (book_id, version_number, file_name, file_path, file_size_bytes, page_count, uploaded_by_user_id)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');
    $insertVersionStmt->execute([$bookId, $nextVersion, $originalFileName, $targetPath, $fileSizeBytes, $pageCount, $adminUserId]);

    $updateBookStmt = $pdo->prepare('UPDATE books SET file_name = ?, file_path = ?, file_size_bytes = ?, page_count = ? WHERE id = ?');
    $updateBookStmt->execute([$originalFileName, $targetPath, $fileSizeBytes, $pageCount, $bookId]);

    $pdo->commit();
    writeAdminAuditLog($pdo, 'replace', $adminUserId, (int) $bookId, [
        'file_name' => $originalFileName,
        'stored_path' => $targetPath,
        'version' => $nextVersion,
        'file_size_bytes' => $fileSizeBytes,
        'page_count' => $pageCount,
    ]);

    $response['success'] = true;
    $response['message'] = 'PDF replaced successfully.';
    $response['bookId'] = (int) $bookId;
    $response['version'] = $nextVersion;
    $response['filePath'] = $targetPath;
    $response['fileSizeBytes'] = $fileSizeBytes;
    $response['pageCount'] = $pageCount;
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if (is_file($targetPath)) {
        unlink($targetPath);
    }
    http_response_code(500);
    if ($e instanceof PDOException && stripos($e->getMessage(), 'book_versions') !== false) {
        $response['message'] = 'Versioning table is missing. Run migration 2026-02-24-book-versions.sql first.';
    } else {
        $response['message'] = 'Failed to replace PDF.';
    }
}

echo json_encode($response);
?>
