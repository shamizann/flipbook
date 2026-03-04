<?php
require_once 'security.php';
configureSecureSession();
require_once 'csrf.php';
session_start();
enforceSessionTimeout();
sendSecurityHeaders();

if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

$csrfToken = getCsrfToken();

// SECURITY: Logout via POST with CSRF token (not GET) to prevent forced-logout attacks
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (isValidCsrfToken($_POST['csrf_token'] ?? null)) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
        header('Location: login.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
    <title>Flipbook Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        body.admin-body {
            display: block;
            background: #f4f4f4;
            color: #333;
            overflow: auto;
            padding: 20px;
        }

        .admin-container {
            max-width: 1040px;
            margin: 0 auto;
            background: white;
            padding: 30px;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.1);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            gap: 12px;
        }

        h1,
        h2 {
            margin: 0;
        }

        .upload-section {
            background: #e9ecef;
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 16px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .list-controls {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) auto auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
        }

        .list-controls input,
        .list-controls select {
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: #fff;
        }

        .audit-controls {
            display: grid;
            grid-template-columns: minmax(180px, 1fr) auto auto;
            gap: 10px;
            align-items: center;
            margin-bottom: 12px;
            margin-top: 10px;
        }

        .audit-controls select {
            padding: 8px 10px;
            border: 1px solid #ccc;
            border-radius: 6px;
            background: #fff;
        }

        .btn {
            border: none;
            border-radius: 6px;
            padding: 8px 12px;
            cursor: pointer;
        }

        .btn-primary {
            background: #007bff;
            color: #fff;
        }

        .btn-secondary {
            background: #6c757d;
            color: #fff;
        }

        .book-list-admin .book-item {
            background: white;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            color: #333;
            padding: 12px 14px;
            border-radius: 8px 8px 0 0;
            margin-top: 12px;
            margin-bottom: 0;
            cursor: default;
            gap: 12px;
        }

        .book-item.trashed {
            border-color: #d7b0b0;
            background: #fff9f9;
        }

        .book-list-admin .book-item:hover {
            background: #f8f9fa;
        }

        .actions {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            justify-content: flex-end;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-delete:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .btn-view {
            background: #28a745;
            color: white;
            text-decoration: none;
            padding: 6px 10px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }

        .btn-copy {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
            text-decoration: none;
        }

        .btn-edit {
            background: #0d6efd;
            color: #fff;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-replace {
            background: #f0ad4e;
            color: #1f1f1f;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-history {
            background: #495057;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-restore {
            background: #198754;
            color: white;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-hard-delete {
            background: #7a1a1a;
            color: #fff;
            border: none;
            padding: 6px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-logout {
            background: #6c757d;
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border: none;
            border-radius: 4px;
            white-space: nowrap;
            cursor: pointer;
            font-size: 14px;
        }

        .btn-logout:hover {
            background: #5a6268;
        }

        .btn-delete:disabled,
        .btn-edit:disabled,
        .btn-replace:disabled,
        .btn-restore:disabled,
        .btn-hard-delete:disabled,
        .btn:disabled {
            opacity: 0.7;
            cursor: not-allowed;
        }

        .status-message {
            margin-bottom: 12px;
            padding: 10px 12px;
            border-radius: 6px;
            display: none;
        }

        .status-message.info {
            display: block;
            background: #e7f3ff;
            color: #1f4f8a;
        }

        .status-message.success {
            display: block;
            background: #e9f7ef;
            color: #1c7430;
        }

        .status-message.error {
            display: block;
            background: #fcebea;
            color: #a91b1b;
        }

        .list-summary {
            margin-bottom: 8px;
            font-size: 14px;
            color: #525252;
        }

        .book-title-row {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 6px;
            flex-wrap: wrap;
        }

        .version-pill {
            background: #343a40;
            color: #fff;
            border-radius: 999px;
            font-size: 12px;
            padding: 2px 8px;
        }

        .trash-pill {
            background: #a91b1b;
            color: #fff;
            border-radius: 999px;
            font-size: 12px;
            padding: 2px 8px;
        }

        .book-meta {
            font-size: 13px;
            color: #4f4f4f;
            margin-top: 2px;
        }

        .history-panel {
            border: 1px solid #ddd;
            border-top: none;
            background: #f8f9fa;
            border-radius: 0 0 8px 8px;
            padding: 10px 14px;
            margin-bottom: 12px;
            display: none;
        }

        .history-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 8px 0;
            border-bottom: 1px solid #e5e5e5;
        }

        .history-item:last-child {
            border-bottom: none;
        }

        .history-meta {
            font-size: 13px;
            color: #4a4a4a;
            line-height: 1.4;
        }

        .history-current {
            background: #198754;
            color: #fff;
            border-radius: 999px;
            font-size: 11px;
            padding: 2px 7px;
            margin-left: 8px;
        }

        .history-missing {
            color: #b33a3a;
            font-size: 12px;
        }

        .pagination-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            margin-top: 14px;
        }

        .pagination-controls button {
            border: 1px solid #c9c9c9;
            background: #fff;
            border-radius: 5px;
            padding: 6px 10px;
            cursor: pointer;
        }

        .pagination-controls button.active {
            background: #007bff;
            color: #fff;
            border-color: #007bff;
        }

        .pagination-controls button:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        .audit-section {
            margin-top: 30px;
        }

        .audit-table-wrap {
            border: 1px solid #ddd;
            border-radius: 8px;
            overflow: auto;
            background: #fff;
        }

        .audit-table {
            width: 100%;
            border-collapse: collapse;
            min-width: 760px;
            font-size: 13px;
        }

        .audit-table th,
        .audit-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #ececec;
            text-align: left;
            vertical-align: top;
        }

        .audit-table th {
            position: sticky;
            top: 0;
            background: #f8f9fa;
            z-index: 1;
        }

        .audit-table tr:last-child td {
            border-bottom: none;
        }

        .audit-empty {
            padding: 14px;
            color: #666;
        }

        .audit-action-pill {
            display: inline-block;
            border-radius: 999px;
            background: #495057;
            color: #fff;
            padding: 2px 8px;
            font-size: 11px;
            letter-spacing: 0.2px;
        }

        .audit-secondary {
            color: #666;
            font-size: 12px;
            margin-top: 4px;
            line-height: 1.35;
        }

        .modal-overlay {
            position: fixed;
            inset: 0;
            background: rgba(0, 0, 0, 0.45);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 2200;
            padding: 16px;
        }

        .modal-overlay.active {
            display: flex;
        }

        .modal-card {
            width: 100%;
            max-width: 460px;
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 18px 42px rgba(0, 0, 0, 0.24);
            padding: 18px;
        }

        .modal-title {
            margin: 0;
            font-size: 20px;
            color: #1f2937;
        }

        .modal-subtitle {
            margin-top: 8px;
            margin-bottom: 14px;
            color: #4b5563;
            font-size: 14px;
            line-height: 1.4;
        }

        .modal-form label {
            display: block;
            font-weight: 600;
            margin-bottom: 6px;
        }

        .modal-form input[type="text"] {
            width: 100%;
            border: 1px solid #c8ced6;
            border-radius: 8px;
            padding: 9px 11px;
            font-size: 14px;
            color: #1f2937;
        }

        .modal-form input[type="text"]:focus {
            outline: none;
            border-color: #0d6efd;
            box-shadow: 0 0 0 3px rgba(13, 110, 253, 0.12);
        }

        .modal-error {
            margin-top: 10px;
            font-size: 13px;
            color: #b42318;
            min-height: 18px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 8px;
            margin-top: 14px;
        }

        @media (max-width: 900px) {
            .list-controls {
                grid-template-columns: 1fr 1fr;
            }

            .audit-controls {
                grid-template-columns: 1fr 1fr;
            }

            .book-list-admin .book-item {
                flex-direction: column;
            }

            .actions {
                justify-content: flex-start;
            }
        }

        @media (max-width: 560px) {
            .list-controls {
                grid-template-columns: 1fr;
            }

            .audit-controls {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>

<body class="admin-body">

    <div class="admin-container">
        <div class="header">
            <h1>Flipbook Library Manager</h1>
            <form method="POST" style="margin:0;">
                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                <input type="hidden" name="logout" value="1">
                <button type="submit" class="btn-logout">Logout</button>
            </form>
        </div>

        <div class="upload-section">
            <input type="file" id="upload-input" accept=".pdf">
            <button id="upload-btn" class="btn btn-primary" type="button">Upload New PDF</button>
        </div>

        <div class="list-controls">
            <input type="text" id="search-input" placeholder="Search title or filename...">
            <select id="status-filter">
                <option value="active">Active</option>
                <option value="trashed">Trash</option>
                <option value="all">All</option>
            </select>
            <select id="per-page-select">
                <option value="10">10 / page</option>
                <option value="20">20 / page</option>
                <option value="50">50 / page</option>
            </select>
            <button id="refresh-btn" class="btn btn-secondary" type="button">Refresh</button>
        </div>

        <div id="admin-message" class="status-message"></div>

        <h2>Uploaded Books</h2>
        <div id="list-summary" class="list-summary"></div>
        <div id="admin-book-list" class="book-list-admin">
            <div class="loading">Loading books...</div>
        </div>
        <div id="pagination-controls" class="pagination-controls"></div>

        <div class="audit-section">
            <h2>Audit Trail</h2>
            <div class="audit-controls">
                <select id="audit-action-filter">
                    <option value="">All actions</option>
                    <option value="login">login</option>
                    <option value="upload">upload</option>
                    <option value="replace">replace</option>
                    <option value="trash">trash</option>
                    <option value="restore">restore</option>
                    <option value="update_title">update_title</option>
                    <option value="hard_delete">hard_delete</option>
                    <option value="cleanup_orphan_uploads">cleanup_orphan_uploads</option>
                </select>
                <select id="audit-per-page-select">
                    <option value="10">10 / page</option>
                    <option value="20">20 / page</option>
                    <option value="50">50 / page</option>
                </select>
                <button id="audit-refresh-btn" class="btn btn-secondary" type="button">Refresh Logs</button>
            </div>
            <div id="audit-summary" class="list-summary"></div>
            <div id="audit-log-list" class="audit-table-wrap">
                <div class="audit-empty">Loading audit logs...</div>
            </div>
            <div id="audit-pagination-controls" class="pagination-controls"></div>
        </div>
    </div>

    <div id="edit-title-modal" class="modal-overlay" aria-hidden="true">
        <div class="modal-card" role="dialog" aria-modal="true" aria-labelledby="edit-title-heading">
            <h3 id="edit-title-heading" class="modal-title">Edit Book Title</h3>
            <p id="edit-title-book-label" class="modal-subtitle"></p>
            <form id="edit-title-form" class="modal-form">
                <label for="edit-title-input">Title</label>
                <input id="edit-title-input" type="text" maxlength="255" required>
                <div id="edit-title-error" class="modal-error" role="alert"></div>
                <div class="modal-actions">
                    <button id="edit-title-cancel" class="btn btn-secondary" type="button">Cancel</button>
                    <button id="edit-title-save" class="btn btn-primary" type="submit">Save Title</button>
                </div>
            </form>
        </div>
    </div>

    <script src="assets/js/admin.js"></script>
</body>

</html>
