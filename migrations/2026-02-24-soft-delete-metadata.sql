USE flipbook;

ALTER TABLE books
    ADD COLUMN file_size_bytes BIGINT UNSIGNED NULL AFTER file_path,
    ADD COLUMN page_count INT UNSIGNED NULL AFTER file_size_bytes,
    ADD COLUMN created_by_user_id INT NULL AFTER page_count,
    ADD COLUMN deleted_at DATETIME NULL AFTER uploaded_at,
    ADD COLUMN deleted_by_user_id INT NULL AFTER deleted_at,
    ADD COLUMN updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP AFTER deleted_by_user_id;

ALTER TABLE book_versions
    ADD COLUMN file_size_bytes BIGINT UNSIGNED NULL AFTER file_path,
    ADD COLUMN page_count INT UNSIGNED NULL AFTER file_size_bytes,
    ADD COLUMN uploaded_by_user_id INT NULL AFTER page_count;

ALTER TABLE books
    ADD INDEX idx_books_deleted_at (deleted_at),
    ADD INDEX idx_books_title (title);

ALTER TABLE books
    ADD CONSTRAINT fk_books_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE SET NULL,
    ADD CONSTRAINT fk_books_deleted_by FOREIGN KEY (deleted_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE book_versions
    ADD CONSTRAINT fk_book_versions_uploaded_by FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE SET NULL;

UPDATE book_versions v
JOIN books b ON b.id = v.book_id
SET
    v.file_size_bytes = COALESCE(v.file_size_bytes, b.file_size_bytes),
    v.page_count = COALESCE(v.page_count, b.page_count),
    v.uploaded_by_user_id = COALESCE(v.uploaded_by_user_id, b.created_by_user_id)
WHERE
    v.file_size_bytes IS NULL
    OR v.page_count IS NULL
    OR v.uploaded_by_user_id IS NULL;

INSERT INTO book_versions (book_id, version_number, file_name, file_path, file_size_bytes, page_count, uploaded_by_user_id, created_at)
SELECT b.id, 1, b.file_name, b.file_path, b.file_size_bytes, b.page_count, b.created_by_user_id, b.uploaded_at
FROM books b
WHERE NOT EXISTS (
    SELECT 1 FROM book_versions v WHERE v.book_id = b.id
);
