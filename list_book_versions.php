<?php
require 'db.php';
session_start();

header('Content-Type: application/json');

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$bookId || $bookId < 1) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid book id.']);
    exit;
}

function isValidStoredPath(string $path): bool
{
    return preg_match('#^uploads/[a-zA-Z0-9._/\- ]+\.pdf$#i', $path) === 1;
}

function toDownloadUrl(string $path): string
{
    $segments = explode('/', str_replace('\\', '/', $path));
    return implode('/', array_map('rawurlencode', $segments));
}

try {
    $bookStmt = $pdo->prepare('
        SELECT
            b.id,
            b.title,
            b.file_name,
            b.file_path,
            b.file_size_bytes,
            b.page_count,
            b.uploaded_at,
            b.deleted_at,
            b.created_by_user_id,
            u.username AS created_by_username
        FROM books b
        LEFT JOIN users u ON u.id = b.created_by_user_id
        WHERE b.id = ?
        LIMIT 1
    ');
    $bookStmt->execute([$bookId]);
    $book = $bookStmt->fetch();

    if (!$book) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Book not found.']);
        exit;
    }

    try {
        $versionStmt = $pdo->prepare('
            SELECT
                v.version_number,
                v.file_name,
                v.file_path,
                v.file_size_bytes,
                v.page_count,
                v.created_at,
                v.uploaded_by_user_id,
                u.username AS uploaded_by_username
            FROM book_versions v
            LEFT JOIN users u ON u.id = v.uploaded_by_user_id
            WHERE v.book_id = ?
            ORDER BY v.version_number DESC
        ');
        $versionStmt->execute([$bookId]);
        $rows = $versionStmt->fetchAll();
    } catch (PDOException $e) {
        if (stripos($e->getMessage(), 'book_versions') === false) {
            throw $e;
        }
        $rows = [];
    }

    $versions = [];
    $uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    $maxVersion = 0;
    $hasCurrentVersion = false;

    foreach ($rows as $row) {
        $versionNumber = (int) $row['version_number'];
        $maxVersion = max($maxVersion, $versionNumber);
        $filePath = $row['file_path'];
        $isCurrent = $filePath === $book['file_path'];
        $hasCurrentVersion = $hasCurrentVersion || $isCurrent;

        $isAvailable = false;
        $downloadUrl = null;

        if (is_string($filePath) && isValidStoredPath($filePath)) {
            $candidatePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $filePath);
            $candidateDir = realpath(dirname($candidatePath));
            if ($uploadsRoot !== false && $candidateDir !== false && strpos($candidateDir, $uploadsRoot) === 0) {
                $isAvailable = is_file($candidatePath);
                $downloadUrl = toDownloadUrl($filePath);
            }
        }

        $versions[] = [
            'version' => $versionNumber,
            'fileName' => $row['file_name'],
            'fileSizeBytes' => $row['file_size_bytes'] !== null ? (int) $row['file_size_bytes'] : null,
            'pageCount' => $row['page_count'] !== null ? (int) $row['page_count'] : null,
            'createdAt' => $row['created_at'],
            'uploadedByUserId' => $row['uploaded_by_user_id'] !== null ? (int) $row['uploaded_by_user_id'] : null,
            'uploadedByUsername' => $row['uploaded_by_username'] ?? null,
            'isCurrent' => $isCurrent,
            'isAvailable' => $isAvailable,
            'downloadUrl' => $downloadUrl,
        ];
    }

    if (count($versions) === 0) {
        $fallbackVersion = 1;
        $downloadUrl = null;
        $isAvailable = false;

        if (is_string($book['file_path']) && isValidStoredPath($book['file_path'])) {
            $candidatePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $book['file_path']);
            $candidateDir = realpath(dirname($candidatePath));
            if ($uploadsRoot !== false && $candidateDir !== false && strpos($candidateDir, $uploadsRoot) === 0) {
                $isAvailable = is_file($candidatePath);
                $downloadUrl = toDownloadUrl($book['file_path']);
            }
        }

        $versions[] = [
            'version' => $fallbackVersion,
            'fileName' => $book['file_name'],
            'fileSizeBytes' => $book['file_size_bytes'] !== null ? (int) $book['file_size_bytes'] : null,
            'pageCount' => $book['page_count'] !== null ? (int) $book['page_count'] : null,
            'createdAt' => $book['uploaded_at'],
            'uploadedByUserId' => $book['created_by_user_id'] !== null ? (int) $book['created_by_user_id'] : null,
            'uploadedByUsername' => $book['created_by_username'] ?? null,
            'isCurrent' => true,
            'isAvailable' => $isAvailable,
            'downloadUrl' => $downloadUrl,
        ];
        $maxVersion = $fallbackVersion;
        $hasCurrentVersion = true;
    }

    if (!$hasCurrentVersion) {
        $maxVersion += 1;
        $downloadUrl = null;
        $isAvailable = false;

        if (is_string($book['file_path']) && isValidStoredPath($book['file_path'])) {
            $candidatePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $book['file_path']);
            $candidateDir = realpath(dirname($candidatePath));
            if ($uploadsRoot !== false && $candidateDir !== false && strpos($candidateDir, $uploadsRoot) === 0) {
                $isAvailable = is_file($candidatePath);
                $downloadUrl = toDownloadUrl($book['file_path']);
            }
        }

        $versions[] = [
            'version' => $maxVersion,
            'fileName' => $book['file_name'],
            'fileSizeBytes' => $book['file_size_bytes'] !== null ? (int) $book['file_size_bytes'] : null,
            'pageCount' => $book['page_count'] !== null ? (int) $book['page_count'] : null,
            'createdAt' => $book['uploaded_at'],
            'uploadedByUserId' => $book['created_by_user_id'] !== null ? (int) $book['created_by_user_id'] : null,
            'uploadedByUsername' => $book['created_by_username'] ?? null,
            'isCurrent' => true,
            'isAvailable' => $isAvailable,
            'downloadUrl' => $downloadUrl,
        ];
    }

    usort($versions, function ($a, $b) {
        return $b['version'] <=> $a['version'];
    });

    echo json_encode([
        'success' => true,
        'book' => [
            'id' => (int) $book['id'],
            'title' => $book['title'],
            'isDeleted' => !empty($book['deleted_at']),
            'currentVersion' => max(array_column($versions, 'version')),
        ],
        'versions' => $versions,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to load version history.']);
}
?>
