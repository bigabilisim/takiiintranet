ALTER TABLE leave_requests
    ADD COLUMN manager_approval_count TINYINT UNSIGNED NOT NULL DEFAULT 1 AFTER total_days,
    ADD COLUMN calendar_state VARCHAR(40) NOT NULL DEFAULT 'tentative' AFTER status;

CREATE TABLE leave_request_approvals (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leave_request_id BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(40) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'pending',
    actor_user_id BIGINT UNSIGNED NULL,
    source VARCHAR(40) NULL,
    acted_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_leave_request_stage (leave_request_id, stage),
    CONSTRAINT fk_leave_approvals_request FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_approvals_actor FOREIGN KEY (actor_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE mail_approval_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leave_request_id BIGINT UNSIGNED NOT NULL,
    stage VARCHAR(40) NOT NULL,
    token_hash CHAR(64) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_mail_tokens_leave_request FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_calendar_events (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    leave_request_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    state VARCHAR(40) NOT NULL DEFAULT 'tentative',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_leave_calendar_range (starts_on, ends_on, state),
    CONSTRAINT fk_leave_calendar_request FOREIGN KEY (leave_request_id) REFERENCES leave_requests(id) ON DELETE CASCADE,
    CONSTRAINT fk_leave_calendar_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

