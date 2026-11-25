-- Privacidad y datos personales (Ley 1581 de 2012 y Decreto 1377 de 2013, compilado en el Decreto 1074 de 2015).
--   privacy_settings           = datos de contacto del responsable para peticiones, consultas y reclamos.
--   privacy_document_versions  = versiones publicadas e inmutables de la política de tratamiento, el texto de
--                                autorización y la política de cookies. Cada cambio publica una versión nueva; las
--                                anteriores se conservan porque son la prueba de lo que aceptó cada titular.
--   data_consents              = registro de autorizaciones (prueba exigida por el art. 17 lit. b de la Ley 1581):
--                                quién, cuándo, desde dónde y qué versiones aceptó; revocación con fecha y nota.
--   contact_messages           = mensajes del formulario de contacto de la página pública.
-- Permisos del panel: «privacidad» (políticas y registro) y «contacto» (mensajes del sitio web): Total para el rol
-- de sistema; los roles personalizados empiezan sin acceso.
-- Idempotente: se puede aplicar más de una vez.

CREATE TABLE IF NOT EXISTS privacy_settings (
    tenant_id BIGINT UNSIGNED NOT NULL,
    contact_email VARCHAR(254) NULL,
    contact_phone VARCHAR(30) NULL,
    responsible_area VARCHAR(120) NULL,
    contact_form_enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    updated_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (tenant_id),
    CONSTRAINT fk_privacy_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_privacy_settings_updated_by FOREIGN KEY (updated_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS privacy_document_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    document ENUM('data_policy', 'authorization', 'cookie_policy') NOT NULL,
    version SMALLINT UNSIGNED NOT NULL,
    content MEDIUMTEXT NOT NULL,
    change_summary VARCHAR(255) NOT NULL,
    published_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    published_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_privacy_document_version (tenant_id, document, version),
    CONSTRAINT fk_privacy_versions_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_privacy_versions_published_by FOREIGN KEY (published_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS data_consents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    source ENUM('contact_form') NOT NULL,
    subject_name VARCHAR(100) NOT NULL,
    subject_email VARCHAR(254) NOT NULL,
    subject_phone VARCHAR(30) NULL,
    data_policy_version_id BIGINT UNSIGNED NOT NULL,
    authorization_version_id BIGINT UNSIGNED NOT NULL,
    granted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) NOT NULL,
    user_agent VARCHAR(255) NULL,
    revoked_at DATETIME NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revocation_note VARCHAR(255) NULL,
    PRIMARY KEY (id),
    KEY idx_data_consents_tenant_granted (tenant_id, granted_at),
    KEY idx_data_consents_tenant_email (tenant_id, subject_email),
    CONSTRAINT fk_data_consents_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_data_consents_policy FOREIGN KEY (data_policy_version_id) REFERENCES privacy_document_versions (id),
    CONSTRAINT fk_data_consents_authorization FOREIGN KEY (authorization_version_id) REFERENCES privacy_document_versions (id),
    CONSTRAINT fk_data_consents_revoked_by FOREIGN KEY (revoked_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contact_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    consent_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    phone VARCHAR(30) NULL,
    message TEXT NOT NULL,
    status ENUM('new', 'read') NOT NULL DEFAULT 'new',
    ip_hash CHAR(64) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at DATETIME NULL,
    read_by BIGINT UNSIGNED NULL,
    PRIMARY KEY (id),
    KEY idx_contact_messages_tenant_status (tenant_id, status, created_at),
    KEY idx_contact_messages_rate (tenant_id, ip_hash, created_at),
    CONSTRAINT fk_contact_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_contact_messages_consent FOREIGN KEY (consent_id) REFERENCES data_consents (id),
    CONSTRAINT fk_contact_messages_read_by FOREIGN KEY (read_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO admin_role_permissions (role_id, module_key, permission_level)
SELECT roles.id, modules.module_key, CASE WHEN roles.is_system = 1 THEN 'total' ELSE 'none' END
FROM admin_roles AS roles
CROSS JOIN (SELECT 'privacidad' AS module_key UNION ALL SELECT 'contacto') AS modules
WHERE roles.kind = 'staff';
