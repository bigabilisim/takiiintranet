CREATE TABLE news_posts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    author_user_id BIGINT UNSIGNED NOT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    publish_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_news_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_news_author FOREIGN KEY (author_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE news_post_translations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    news_post_id BIGINT UNSIGNED NOT NULL,
    locale VARCHAR(12) NOT NULL,
    title VARCHAR(220) NOT NULL,
    body MEDIUMTEXT NOT NULL,
    UNIQUE KEY uq_news_locale (news_post_id, locale),
    CONSTRAINT fk_news_translation_post FOREIGN KEY (news_post_id) REFERENCES news_posts(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_types (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(140) NOT NULL,
    code VARCHAR(40) NOT NULL,
    requires_file TINYINT(1) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_leave_type_company_code (company_id, code),
    CONSTRAINT fk_leave_types_company FOREIGN KEY (company_id) REFERENCES companies(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_balances (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    leave_type_id BIGINT UNSIGNED NOT NULL,
    year SMALLINT UNSIGNED NOT NULL,
    total_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    used_days DECIMAL(6,2) NOT NULL DEFAULT 0,
    UNIQUE KEY uq_leave_balance (user_id, leave_type_id, year),
    CONSTRAINT fk_leave_balances_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_leave_balances_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE leave_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    leave_type_id BIGINT UNSIGNED NOT NULL,
    starts_on DATE NOT NULL,
    ends_on DATE NOT NULL,
    total_days DECIMAL(6,2) NOT NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_leave_requests_user FOREIGN KEY (user_id) REFERENCES users(id),
    CONSTRAINT fk_leave_requests_type FOREIGN KEY (leave_type_id) REFERENCES leave_types(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE budgets (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    user_id BIGINT UNSIGNED NULL,
    year SMALLINT UNSIGNED NOT NULL,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    amount DECIMAL(14,2) NOT NULL,
    used_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_budgets_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_budgets_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_requests (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT UNSIGNED NOT NULL,
    requester_user_id BIGINT UNSIGNED NOT NULL,
    department_id BIGINT UNSIGNED NULL,
    budget_id BIGINT UNSIGNED NULL,
    status VARCHAR(40) NOT NULL DEFAULT 'draft',
    total_amount DECIMAL(14,2) NOT NULL DEFAULT 0,
    currency CHAR(3) NOT NULL DEFAULT 'USD',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_purchase_company FOREIGN KEY (company_id) REFERENCES companies(id),
    CONSTRAINT fk_purchase_requester FOREIGN KEY (requester_user_id) REFERENCES users(id),
    CONSTRAINT fk_purchase_department FOREIGN KEY (department_id) REFERENCES departments(id),
    CONSTRAINT fk_purchase_budget FOREIGN KEY (budget_id) REFERENCES budgets(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_request_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    purchase_request_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(240) NOT NULL,
    quantity DECIMAL(10,2) NOT NULL DEFAULT 1,
    unit_price DECIMAL(14,2) NOT NULL,
    total_price DECIMAL(14,2) NOT NULL,
    CONSTRAINT fk_purchase_items_request FOREIGN KEY (purchase_request_id) REFERENCES purchase_requests(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

