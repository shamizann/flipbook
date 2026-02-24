<?php
require 'db.php';

header('Content-Type: application/json');

function isValidStoredPath(string $path): bool
{
    return preg_match('#^uploads/[a-zA-Z0-9._/\- ]+\.pdf$#i', $path) === 1;
}

function resolveBook(array $book): ?array
{
    if (!isset($book['file_path']) || !is_string($book['file_path']) || !isValidStoredPath($book['file_path'])) {
        return null;
    }

    $uploadsRoot = realpath(__DIR__ . DIRECTORY_SEPARATOR . 'uploads');
    $fileRealPath = realpath(__DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $book['file_path']));

    if ($uploadsRoot === false || $fileRealPath === false || strpos($fileRealPath, $uploadsRoot) !== 0 || !is_file($fileRealPath)) {
        return null;
    }

    $segments = explode('/', str_replace('\\', '/', $book['file_path']));
    $encodedPath = implode('/', array_map('rawurlencode', $segments));

    return [
        'id' => (int) $book['id'],
        'title' => $book['title'],
        'fileUrl' => $encodedPath,
        'fileName' => $book['file_name'],
    ];
}

try {
    $book = null;
    $bookId = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);

    if ($bookId && $bookId > 0) {
        $stmt = $pdo->prepare('SELECT id, title, file_name, file_path, deleted_at FROM books WHERE id = ? LIMIT 1');
        $stmt->execute([$bookId]);
        $book = $stmt->fetch();

        if ($book && !empty($book['deleted_at'])) {
            http_response_code(410);
            echo json_encode(['success' => false, 'message' => 'This book is currently in trash.']);
            exit;
        }
    } else {
        // Backward compatibility for old shared links: ?book=uploads/...
        $legacyPath = filter_input(INPUT_GET, 'book', FILTER_UNSAFE_RAW);
        if (!$legacyPath || !is_string($legacyPath)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid request. Provide a valid id or book path.']);
            exit;
        }

        $legacyPath = trim($legacyPath);
        if (!isValidStoredPath($legacyPath)) {
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid book path.']);
            exit;
        }

        $stmt = $pdo->prepare('SELECT id, title, file_name, file_path, deleted_at FROM books WHERE file_path = ? LIMIT 1');
        $stmt->execute([$legacyPath]);
        $book = $stmt->fetch();

        if ($book && !empty($book['deleted_at'])) {
            http_response_code(410);
            echo json_encode(['success' => false, 'message' => 'This book is currently in trash.']);
            exit;
        }
    }

    if (!$book) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Book not found.']);
        exit;
    }

    $resolvedBook = resolveBook($book);
    if (!$resolvedBook) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'Book file is invalid or missing.']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'book' => $resolvedBook,
    ]);
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to resolve book.']);
}
?>
