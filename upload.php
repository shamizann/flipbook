<?php
require 'db.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_FILES['pdf_file']) && $_FILES['pdf_file']['error'] === UPLOAD_ERR_OK) {
        $fileHtml = '';
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        $fileName = basename($_FILES['pdf_file']['name']);
        $targetPath = $uploadDir . time() . '_' . $fileName;
        $fileType = strtolower(pathinfo($targetPath, PATHINFO_EXTENSION));

        if ($fileType === 'pdf') {
            if (move_uploaded_file($_FILES['pdf_file']['tmp_name'], $targetPath)) {
                // Save to DB
                $stmt = $pdo->prepare("INSERT INTO books (title, file_name, file_path) VALUES (?, ?, ?)");
                $title = pathinfo($fileName, PATHINFO_FILENAME);
                if ($stmt->execute([$title, $fileName, $targetPath])) {
                    $response['success'] = true;
                    $response['message'] = 'File uploaded successfully.';
                    $response['filePath'] = $targetPath;
                } else {
                    $response['message'] = 'Failed to save to database.';
                }
            } else {
                $response['message'] = 'Failed to move uploaded file.';
            }
        } else {
            $response['message'] = 'Only PDF files are allowed.';
        }
    } else {
        $response['message'] = 'No file uploaded or upload error.';
    }
}

header('Content-Type: application/json');
echo json_encode($response);
?>