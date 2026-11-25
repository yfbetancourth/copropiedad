-- Correo con «Iniciar sesión con Google / Microsoft» (OAuth 2.0 + PKCE) en lugar de una contraseña SMTP.
--   mail_oauth_connections = cuenta conectada del conjunto (owner_scope = 0) o personal de un administrador
--                            (owner_scope = user_id). Solo tiene permiso para ENVIAR (gmail.send / Mail.Send).
--                            Los tokens se guardan cifrados (AES-256-GCM, contexto ligado al conjunto y al dueño).
--   mail_oauth_states      = solicitudes de conexión en curso: «state» aleatorio guardado solo como hash, de un solo
--                            uso y con vencimiento, ligado al conjunto, al usuario y al destino; guarda cifrado el
--                            code_verifier de PKCE y la dirección de regreso (calculada en el servidor).
-- Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS mail_oauth_connections (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    owner_scope BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NULL,
    provider ENUM('google', 'microsoft') NOT NULL,
    email VARCHAR(254) NOT NULL,
    display_name VARCHAR(160) NOT NULL DEFAULT '',
    refresh_token_ciphertext TEXT NOT NULL,
    access_token_ciphertext TEXT NULL,
    access_expires_at DATETIME NULL,
    connected_by BIGINT UNSIGNED NULL,
    connected_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_sent_at DATETIME NULL,
    last_error VARCHAR(255) NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_mail_oauth_owner (tenant_id, owner_scope),
    CONSTRAINT chk_mail_oauth_owner CHECK ((owner_scope = 0 AND user_id IS NULL) OR (owner_scope > 0 AND user_id = owner_scope)),
    CONSTRAINT fk_mail_oauth_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_mail_oauth_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_oauth_connected_by FOREIGN KEY (connected_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mail_oauth_states (
    state_hash CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    tenant_id BIGINT UNSIGNED NOT NULL,
    user_id BIGINT UNSIGNED NOT NULL,
    target ENUM('tenant', 'personal') NOT NULL,
    provider ENUM('google', 'microsoft') NOT NULL,
    code_verifier_ciphertext TEXT NOT NULL,
    return_url VARCHAR(500) NOT NULL,
    expires_at DATETIME NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (state_hash),
    KEY idx_mail_oauth_states_expires (expires_at),
    CONSTRAINT fk_mail_oauth_states_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE,
    CONSTRAINT fk_mail_oauth_states_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
