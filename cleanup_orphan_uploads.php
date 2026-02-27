<?php
require 'db.php';
require_once 'audit.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "This script can only be run from CLI.\n";
    exit(1);
}

$options = getopt('', ['delete', 'limit::', 'help']);
if (isset($options['help'])) {
    echo "Usage:\n";
    echo "  php cleanup_orphan_uploads.php            # dry run\n";
    echo "  php cleanup_orphan_uploads.php --delete   # remove orphan files\n";
    echo "  php cleanup_orphan_uploads.php --delete --limit=50\n";
    exit(0);
}

$deleteMode = isset($options['delete']);
$limit = null;
if (isset($options['limit'])) {
    $parsedLimit = filter_var($options['limit'], FILTER_VALIDATE_INT);
    if ($parsedLimit !== false && $parsedLimit !== null && $parsedLimit > 0) {
        $limit = (int) $parsedLimit;
    }
}

$uploadDir = __DIR__ . DIRECTORY_SEPARATOR . 'uploads';
if (!is_dir($uploadDir)) {
    echo "Uploads directory not found: {$uploadDir}\n";
    exit(1);
}

$files = glob($uploadDir . DIRECTORY_SEPARATOR . '*.pdf');
if ($files === false) {
    echo "Failed to read uploads directory.\n";
    exit(1);
}

$allUploadPaths = [];
foreach ($files as $absolutePath) {
    if (!is_string($absolutePath) || !is_file($absolutePath)) {
        continue;
    }

    $basename = basename($absolutePath);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        continue;
    }

    $allUploadPaths[] = 'uploads/' . $basename;
}

$referenced = [];
$refStmt = $pdo->query('
    SELECT file_path
    FROM (
        SELECT file_path FROM books
        UNION ALL
        SELECT file_path FROM book_versions
    ) AS refs
');
foreach ($refStmt->fetchAll(PDO::FETCH_COLUMN) as $path) {
    if (is_string($path) && $path !== '') {
        $referenced[$path] = true;
    }
}

$orphans = [];
foreach ($allUploadPaths as $relativePath) {
    if (!isset($referenced[$relativePath])) {
        $orphans[] = $relativePath;
    }
}

if ($limit !== null && count($orphans) > $limit) {
    $orphans = array_slice($orphans, 0, $limit);
}

$deleted = [];
$failed = [];

if ($deleteMode) {
    foreach ($orphans as $relativePath) {
        $absolutePath = __DIR__ . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        if (!is_file($absolutePath)) {
            $failed[] = $relativePath;
            continue;
        }

        if (@unlink($absolutePath)) {
            $deleted[] = $relativePath;
        } else {
            $failed[] = $relativePath;
        }
    }
}

$summary = [
    'mode' => $deleteMode ? 'delete' : 'dry-run',
    'scanned_pdf_files' => count($allUploadPaths),
    'orphan_candidates' => count($orphans),
    'deleted' => count($deleted),
    'failed' => count($failed),
];

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

if (count($orphans) > 0) {
    echo "Orphan files:\n";
    foreach ($orphans as $path) {
        echo " - {$path}\n";
    }
}

if ($deleteMode) {
    writeAdminAuditLog($pdo, 'cleanup_orphan_uploads', null, null, [
        'scanned_pdf_files' => count($allUploadPaths),
        'orphan_candidates' => count($orphans),
        'deleted' => count($deleted),
        'failed' => count($failed),
    ]);
}

if ($deleteMode && count($failed) > 0) {
    exit(2);
}

exit(0);

