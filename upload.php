<?php
require 'db.php';
require_once 'csrf.php';
require_once 'audit.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
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
    $response['message'] = 'Method not allowed.';
    header('Content-Type: application/json');
    echo json_encode($response);
    exit;
}

requireCsrfTokenForJsonPost();

if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            if (!mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
                $response['message'] = 'Upload directory is not available.';
                header('Content-Type: application/json');
                echo json_encode($response);
                exit;
            }
        }

        $uploadedFile = $_FILES['pdf_file'];
        $originalFileName = basename($uploadedFile['name']);
        $fileSize = (int) $uploadedFile['size'];

        if ($fileSize <= 0 || $fileSize > $maxUploadBytes) {
            $response['message'] = 'Invalid file size. Max upload is 50 MB.';
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        if (!is_uploaded_file($uploadedFile['tmp_name'])) {
            $response['message'] = 'Invalid upload source.';
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $extension = strtolower(pathinfo($originalFileName, PATHINFO_EXTENSION));
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mimeType = $finfo->file($uploadedFile['tmp_name']) ?: '';

        if ($extension !== 'pdf' || $mimeType !== 'application/pdf') {
            $response['message'] = 'Only valid PDF files are allowed.';
            header('Content-Type: application/json');
            echo json_encode($response);
            exit;
        }

        $safeBaseName = preg_replace('/[^a-zA-Z0-9._-]/', '_', pathinfo($originalFileName, PATHINFO_FILENAME));
        $safeBaseName = trim($safeBaseName, '._-');
        if ($safeBaseName === '') {
            $safeBaseName = 'document';
        }

        $storedFileName = bin2hex(random_bytes(16)) . '.pdf';
        $targetPath = $uploadDir . $storedFileName;

        if (move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            chmod($targetPath, 0644);
            $storedFileSize = filesize($targetPath);
            $fileSizeBytes = $storedFileSize !== false ? (int) $storedFileSize : null;
            $pageCount = getPdfPageCount($targetPath);

            try {
                $pdo->beginTransaction();

                // Save current book record
                $stmt = $pdo->prepare("
                    INSERT INTO books (title, file_name, file_path, file_size_bytes, page_count, created_by_user_id)
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                if ($stmt->execute([$safeBaseName, $originalFileName, $targetPath, $fileSizeBytes, $pageCount, $adminUserId])) {
                    $bookId = (int) $pdo->lastInsertId();

                    try {
                        $versionStmt = $pdo->prepare("
                            INSERT INTO book_versions (book_id, version_number, file_name, file_path, file_size_bytes, page_count, uploaded_by_user_id)
                            VALUES (?, ?, ?, ?, ?, ?, ?)
                        ");
                        $versionInserted = $versionStmt->execute([$bookId, 1, $originalFileName, $targetPath, $fileSizeBytes, $pageCount, $adminUserId]);

                        if (!$versionInserted) {
                            throw new RuntimeException('Failed to create initial version.');
                        }
                    } catch (PDOException $e) {
                        // Backward compatibility if migration hasn't been applied yet.
                        if (stripos($e->getMessage(), 'book_versions') === false) {
                            throw $e;
                        }
                    }

                    $pdo->commit();
                    writeAdminAuditLog($pdo, 'upload', $adminUserId, $bookId, [
                        'file_name' => $originalFileName,
                        'stored_path' => $targetPath,
                        'file_size_bytes' => $fileSizeBytes,
                        'page_count' => $pageCount,
                    ]);
                    $response['success'] = true;
                    $response['message'] = 'File uploaded successfully.';
                    $response['bookId'] = $bookId;
                    $response['filePath'] = $targetPath;
                    $response['fileSizeBytes'] = $fileSizeBytes;
                    $response['pageCount'] = $pageCount;
                } else {
                    $pdo->rollBack();
                    $response['message'] = 'Failed to save to database.';
                }
            } catch (PDOException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (is_file($targetPath)) {
                    unlink($targetPath);
                }
                $response['message'] = 'Failed to save to database.';
            } catch (RuntimeException $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                if (is_file($targetPath)) {
                    unlink($targetPath);
                }
                $response['message'] = 'Failed to save to database.';
            }
        } else {
                $response['message'] = 'Failed to move uploaded file.';
        }
} else {
    $response['message'] = 'No file uploaded or upload error.';
}

header('Content-Type: application/json');
echo json_encode($response);
?>
