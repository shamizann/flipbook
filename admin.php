<?php
session_start();
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    header('Location: login.php');
    exit;
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Flipbook Admin</title>
    <link rel="stylesheet" href="assets/css/style.css">
    <style>
        /* Specific Admin Styles */
        body.admin-body {
            display: block;
            background: #f4f4f4;
            color: #333;
            overflow: auto;
            padding: 20px;
        }

        .admin-container {
            max-width: 800px;
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
        }

        h1 {
            margin: 0;
        }

        .upload-section {
            background: #e9ecef;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            display: flex;
            gap: 10px;
            align-items: center;
        }

        .book-list-admin .book-item {
            background: white;
            border: 1px solid #ddd;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #333;
        }

        .book-list-admin .book-item:hover {
            background: #f8f9fa;
        }

        .actions {
            display: flex;
            gap: 10px;
        }

        .btn-delete {
            background: #dc3545;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-view {
            background: #28a745;
            color: white;
            text-decoration: none;
            padding: 5px 10px;
            border-radius: 4px;
        }

        .btn-copy {
            background: #17a2b8;
            color: white;
            border: none;
            padding: 5px 10px;
            border-radius: 4px;
            cursor: pointer;
        }

        .btn-logout {
            background: #6c757d;
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 4px;
        }

        .btn-logout:hover {
            background: #5a6268;
        }
    </style>
</head>

<body class="admin-body">

    <div class="admin-container">
        <div class="header">
            <h1>Flipbook Library Manager</h1>
            <a href="?logout=true" class="btn-logout">Logout</a>
        </div>

        <div class="upload-section">
            <input type="file" id="upload-input" accept=".pdf">
            <button id="upload-btn" class="btn btn-primary">Upload New PDF</button>
        </div>

        <h2>Uploaded Books</h2>
        <div id="admin-book-list" class="book-list-admin">
            <div class="loading">Loading books...</div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            const uploadInput = document.getElementById('upload-input');
            const uploadBtn = document.getElementById('upload-btn');
            const bookList = document.getElementById('admin-book-list');

            // Upload Logic
            uploadBtn.addEventListener('click', () => {
                const file = uploadInput.files[0];
                if (!file) {
                    alert('Please select a PDF file first.');
                    return;
                }

                if (file.type !== 'application/pdf') {
                    alert('Only PDF files are allowed.');
                    return;
                }

                const formData = new FormData();
                formData.append('pdf_file', file);

                uploadBtn.textContent = 'Uploading...';
                uploadBtn.disabled = true;

                fetch('upload.php', {
                    method: 'POST',
                    body: formData
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            alert('Upload successful!');
                            uploadInput.value = '';
                            fetchBooks();
                        } else {
                            if (data.message === 'Unauthorized') {
                                window.location.href = 'login.php';
                            } else {
                                alert('Upload failed: ' + data.message);
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Error:', error);
                        alert('An error occurred.');
                    })
                    .finally(() => {
                        uploadBtn.textContent = 'Upload New PDF';
                        uploadBtn.disabled = false;
                    });
            });

            // List Books
            function fetchBooks() {
                bookList.innerHTML = '<div class="loading">Loading...</div>';
                fetch('list_books.php')
                    .then(response => response.json())
                    .then(data => {
                        bookList.innerHTML = '';
                        if (data.success && data.books.length > 0) {
                            data.books.forEach(book => {
                                const item = document.createElement('div');
                                item.className = 'book-item';
                                item.innerHTML = `
                                <div>
                                    <strong>${book.title}</strong><br>
                                    <small>${new Date(book.uploaded_at).toLocaleDateString()}</small>
                                </div>
                                <div class="actions">
                                    <a href="index.html?book=${encodeURIComponent(book.file_path)}" target="_blank" class="btn-view">View</a>
                                    <button class="btn-copy" onclick="copyLink('${book.file_path}')">Copy Link</button>
                                </div>
                            `;
                                bookList.appendChild(item);
                            });
                        } else {
                            if (data.message === 'Unauthorized') {
                                // Optional: Redirect or show login link
                                bookList.innerHTML = '<div>Session expired. <a href="login.php">Login</a></div>';
                            } else {
                                bookList.innerHTML = '<div>No books found.</div>';
                            }
                        }
                    });
            }

            window.copyLink = function (path) {
                const url = window.location.origin + '/flipbook/index.html?book=' + encodeURIComponent(path);
                navigator.clipboard.writeText(url).then(() => {
                    alert('Link copied to clipboard!');
                });
            };

            fetchBooks();
        });
    </script>
</body>

</html>