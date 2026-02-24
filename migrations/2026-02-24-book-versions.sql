USE flipbook;

CREATE TABLE IF NOT EXISTS book_versions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    book_id INT NOT NULL,
    version_number INT NOT NULL,
    file_name VARCHAR(255) NOT NULL,
    file_path VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_book_version (book_id, version_number),
    INDEX idx_book_versions_book_id (book_id),
    CONSTRAINT fk_book_versions_book FOREIGN KEY (book_id) REFERENCES books(id) ON DELETE CASCADE
);

INSERT INTO book_versions (book_id, version_number, file_name, file_path, created_at)
SELECT b.id, 1, b.file_name, b.file_path, b.uploaded_at
FROM books b
WHERE NOT EXISTS (
    SELECT 1 FROM book_versions v WHERE v.book_id = b.id
);
