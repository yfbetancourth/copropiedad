-- Cuenta SMTP personal (por ejemplo, un correo certificado) con la que cada administrador
-- envía las notificaciones que él mismo dispara (emitir notas, reintentar correos).
-- La contraseña se guarda cifrada (AES-256-GCM con APP_KEY) y ligada al usuario.
CREATE TABLE IF NOT EXISTS admin_user_mail_settings (
    user_id BIGINT UNSIGNED NOT NULL,
    host VARCHAR(253) NOT NULL,
    port SMALLINT UNSIGNED NOT NULL,
    username VARCHAR(254) NOT NULL,
    password_ciphertext TEXT NOT NULL,
    encryption ENUM('tls', 'ssl') NOT NULL,
    from_address VARCHAR(254) NOT NULL,
    from_name VARCHAR(160) NOT NULL DEFAULT '',
    -- Solo se usa para enviar si está activa y su última prueba de envío fue exitosa.
    is_active TINYINT(1) NOT NULL DEFAULT 0,
    verified_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id),
    CONSTRAINT fk_admin_user_mail_settings_user
        FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Trazabilidad: desde qué cuenta y por quién salió cada notificación de cobro.
ALTER TABLE billing_statement_recipients
    ADD COLUMN IF NOT EXISTS sent_from VARCHAR(254) NULL AFTER sent_at,
    ADD COLUMN IF NOT EXISTS sent_by BIGINT UNSIGNED NULL AFTER sent_from;
