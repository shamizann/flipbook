<?php
require 'db.php';
session_start();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Content-Type: application/json');
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

header('Content-Type: application/json');

try {
    $stmt = $pdo->query("SELECT * FROM books ORDER BY uploaded_at DESC");
    $books = $stmt->fetchAll();
    echo json_encode(['success' => true, 'books' => $books]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>