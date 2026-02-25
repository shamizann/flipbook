USE flipbook;

CREATE TABLE IF NOT EXISTS admin_audit_trail (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_user_id INT NULL,
    action VARCHAR(64) NOT NULL,
    book_id INT NULL,
    details_json JSON NULL,
    ip_address VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_admin_audit_action_created_at (action, created_at),
    INDEX idx_admin_audit_admin_user_id (admin_user_id),
    INDEX idx_admin_audit_book_id (book_id)
);

