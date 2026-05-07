CREATE TABLE user_permission_overrides (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    is_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_user_permission_override (user_id, permission_id),
    CONSTRAINT fk_user_permission_overrides_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_permission_overrides_permission FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE department_leave_policies (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    department_id BIGINT UNSIGNED NOT NULL,
    manager_approval_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
    manager_1_user_id BIGINT UNSIGNED NULL,
    manager_2_user_id BIGINT UNSIGNED NULL,
    hr_user_id BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_department_leave_policy (department_id),
    CONSTRAINT fk_department_leave_policies_department FOREIGN KEY (department_id) REFERENCES departments(id) ON DELETE CASCADE,
    CONSTRAINT fk_department_leave_policies_manager_1 FOREIGN KEY (manager_1_user_id) REFERENCES users(id),
    CONSTRAINT fk_department_leave_policies_manager_2 FOREIGN KEY (manager_2_user_id) REFERENCES users(id),
    CONSTRAINT fk_department_leave_policies_hr FOREIGN KEY (hr_user_id) REFERENCES users(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

