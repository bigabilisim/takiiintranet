CREATE TABLE internal_messages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    sender_user_id BIGINT UNSIGNED NOT NULL,
    recipient_user_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_internal_messages_recipient (recipient_user_id, read_at, created_at),
    INDEX idx_internal_messages_sender (sender_user_id, created_at),
    CONSTRAINT fk_internal_messages_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_internal_messages_sender FOREIGN KEY (sender_user_id) REFERENCES users(id),
    CONSTRAINT fk_internal_messages_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

