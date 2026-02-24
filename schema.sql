CREATE DATABASE IF NOT EXISTS flipbook;
USE flipbook;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS books (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(255) NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    page_count INT UNSIGNED NULL,
    created_by_user_id INT NULL,
    uploaded_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME NULL,
    deleted_by_user_id INT NULL,
    updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_books_deleted_at (deleted_at),
    INDEX idx_books_title (title),
    CONSTRAINT fk_books_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_books_deleted_by FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS book_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    version_number INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    file_size_bytes BIGINT UNSIGNED NULL,
    page_count INT UNSIGNED NULL,
    uploaded_by_user_id INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_book_version (book_id, version_number),
    INDEX idx_book_versions_book_id (book_id),
    CONSTRAINT fk_book_versions_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE,
    CONSTRAINT fk_book_versions_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Backfill existing books as version 1 if missing.
INSERT INTO book_versions (book_id, version_number, file_name, file_path, file_size_bytes, page_count, uploaded_by_user_id, created_at)
SELECT b.id, 1, b.file_name, b.file_path, b.file_size_bytes, b.page_count, b.created_by_user_id, b.uploaded_at
FROM books b
WHERE NOT EXISTS (
    SELECT 1 FROM book_versions v WHERE v.book_id = b.id
);

-- Example:
-- INSERT INTO users (username, password_hash)
-- VALUES ('admin', '$2y$10$replace_with_password_hash_generated_by_php_password_hash');
