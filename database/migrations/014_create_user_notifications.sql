CREATE TABLE IF NOT EXISTS billing_statement_user_reads (
    statement_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (statement_id, user_id),
    CONSTRAINT fk_billing_statement_user_reads_statement
        FOREIGN KEY (statement_id) REFERENCES billing_statements (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_billing_statement_user_reads_user
        FOREIGN KEY (user_id) REFERENCES admin_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    sender_id BIGINT UNSIGNED NOT NULL,
    subject VARCHAR(160) NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_admin_messages_sender_created (sender_id, created_at),
    CONSTRAINT fk_admin_messages_sender
        FOREIGN KEY (sender_id) REFERENCES admin_users (id)
        ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_message_recipients (
    message_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    read_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (message_id, user_id),
    KEY idx_admin_message_recipients_user_read (user_id, read_at),
    CONSTRAINT fk_admin_message_recipients_message
        FOREIGN KEY (message_id) REFERENCES admin_messages (id)
        ON DELETE CASCADE,
    CONSTRAINT fk_admin_message_recipients_user
        FOREIGN KEY (user_id) REFERENCES admin_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, 'mensajes',
       CASE
           WHEN roles.is_system = 1 THEN 'total'
           ELSE COALESCE(panel.permission_level, 'none')
       END
FROM admin_roles AS roles
LEFT JOIN admin_role_permissions AS panel
    ON panel.role_id = roles.id
   AND panel.module_key = 'panel';
