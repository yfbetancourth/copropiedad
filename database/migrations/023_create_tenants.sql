-- Multicliente: cada conjunto residencial es un cliente (tenant) identificado por su subdominio.
-- Una sola base de datos; cada tabla de negocio lleva tenant_id y toda consulta filtra por él.
-- La copropiedad existente se convierte en el cliente 1 (slug «principal»), que el Superadmin puede renombrar.
-- Las tablas hijas (líneas, destinatarios, lecturas, imágenes, permisos, tokens) heredan el cliente de su tabla padre.

CREATE TABLE IF NOT EXISTS tenants (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug VARCHAR(63) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
    name VARCHAR(160) NOT NULL,
    status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tenants_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Módulos que el Superadmin habilita o deshabilita por cliente. Un módulo sin fila se considera deshabilitado.
CREATE TABLE IF NOT EXISTS tenant_modules (
    tenant_id BIGINT UNSIGNED NOT NULL,
    module_key VARCHAR(64) NOT NULL,
    enabled TINYINT(1) NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, module_key),
    CONSTRAINT fk_tenant_modules_tenant
        FOREIGN KEY (tenant_id) REFERENCES tenants (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO tenants (id, slug, name)
SELECT 1, 'principal',
       COALESCE(NULLIF(TRIM((SELECT property_name FROM copropiedad_settings WHERE id = 1)), ''), 'Copropiedad principal');

INSERT IGNORE INTO tenant_modules (tenant_id, module_key, enabled)
VALUES (1, 'cartera', 1), (1, 'pagos_en_linea', 1), (1, 'mensajes', 1), (1, 'portal', 1), (1, 'clasificados', 1);

-- Roles: el rol Superadmin es de la plataforma (tenant_id NULL); los demás pertenecen a un cliente.
-- tenant_scope (0 = plataforma) permite unicidad por cliente, porque UNIQUE no compara NULL.
ALTER TABLE admin_roles
    ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN tenant_scope BIGINT UNSIGNED AS (IFNULL(tenant_id, 0)) STORED AFTER tenant_id;

UPDATE admin_roles SET tenant_id = 1 WHERE is_system = 0;

ALTER TABLE admin_roles
    DROP INDEX uq_admin_roles_name,
    ADD UNIQUE KEY uq_admin_roles_scope_name (tenant_scope, name),
    ADD KEY idx_admin_roles_tenant (tenant_id),
    ADD CONSTRAINT fk_admin_roles_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- Rol de sistema del cliente: el administrador asignado por el Superadmin. Acceso total dentro de su conjunto.
INSERT IGNORE INTO admin_roles (tenant_id, name, description, is_system)
VALUES (1, 'Administrador', 'Administrador principal del conjunto, asignado por el Superadmin', 1);

-- Usuarios: una cuenta pertenece a un solo conjunto; el Superadmin (tenant_id NULL) puede entrar a cualquiera.
ALTER TABLE admin_users
    ADD COLUMN tenant_id BIGINT UNSIGNED NULL AFTER id,
    ADD COLUMN tenant_scope BIGINT UNSIGNED AS (IFNULL(tenant_id, 0)) STORED AFTER tenant_id;

UPDATE admin_users AS users
JOIN admin_roles AS roles ON roles.id = users.role_id
SET users.tenant_id = roles.tenant_id;

ALTER TABLE admin_users
    DROP INDEX uq_admin_users_username,
    DROP INDEX uq_admin_users_email,
    ADD UNIQUE KEY uq_admin_users_scope_username (tenant_scope, username),
    ADD UNIQUE KEY uq_admin_users_scope_email (tenant_scope, email),
    ADD KEY idx_admin_users_tenant (tenant_id),
    ADD CONSTRAINT fk_admin_users_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);

-- Configuraciones que eran de un solo registro (id = 1) pasan a un registro por cliente.
ALTER TABLE copropiedad_settings DROP CONSTRAINT chk_copropiedad_settings_singleton;
ALTER TABLE copropiedad_settings ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 FIRST;
ALTER TABLE copropiedad_settings
    DROP PRIMARY KEY,
    DROP COLUMN id,
    ALTER COLUMN tenant_id DROP DEFAULT,
    ADD PRIMARY KEY (tenant_id),
    ADD CONSTRAINT fk_copropiedad_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE;

ALTER TABLE copropiedad_composition DROP CONSTRAINT chk_copropiedad_composition_singleton;
ALTER TABLE copropiedad_composition ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 FIRST;
ALTER TABLE copropiedad_composition
    DROP PRIMARY KEY,
    DROP COLUMN id,
    ALTER COLUMN tenant_id DROP DEFAULT,
    ADD PRIMARY KEY (tenant_id),
    ADD CONSTRAINT fk_copropiedad_composition_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE;

ALTER TABLE copropiedad_mail_settings DROP CONSTRAINT chk_copropiedad_mail_settings_singleton;
ALTER TABLE copropiedad_mail_settings ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 FIRST;
ALTER TABLE copropiedad_mail_settings
    DROP PRIMARY KEY,
    DROP COLUMN id,
    ALTER COLUMN tenant_id DROP DEFAULT,
    ADD PRIMARY KEY (tenant_id),
    ADD CONSTRAINT fk_copropiedad_mail_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE;

ALTER TABLE bold_payment_settings DROP CONSTRAINT chk_bold_payment_settings_singleton;
ALTER TABLE bold_payment_settings ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 FIRST;
ALTER TABLE bold_payment_settings
    DROP PRIMARY KEY,
    DROP COLUMN id,
    ALTER COLUMN tenant_id DROP DEFAULT,
    ADD PRIMARY KEY (tenant_id),
    ADD CONSTRAINT fk_bold_payment_settings_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE;

-- Tablas de negocio: los datos existentes quedan en el cliente 1.
ALTER TABLE property_unit_assignments
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 FIRST,
    ADD KEY idx_property_unit_assignments_tenant_unit (tenant_id, unit_id),
    ADD CONSTRAINT fk_property_unit_assignments_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE property_unit_assignments ALTER COLUMN tenant_id DROP DEFAULT;

ALTER TABLE billing_monthly_items
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_billing_items_tenant_period_unit (tenant_id, period, unit_id),
    ADD CONSTRAINT fk_billing_items_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE billing_monthly_items ALTER COLUMN tenant_id DROP DEFAULT;

ALTER TABLE billing_statements
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    DROP INDEX uq_billing_statement_unit_period,
    ADD UNIQUE KEY uq_billing_statement_tenant_unit_period (tenant_id, unit_id, period),
    ADD KEY idx_billing_statement_tenant_period (tenant_id, period),
    ADD CONSTRAINT fk_billing_statement_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE billing_statements ALTER COLUMN tenant_id DROP DEFAULT;

ALTER TABLE billing_payments
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_billing_payment_tenant_received (tenant_id, received_on),
    ADD CONSTRAINT fk_billing_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE billing_payments ALTER COLUMN tenant_id DROP DEFAULT;

ALTER TABLE billing_online_payments
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_billing_online_payment_tenant (tenant_id, status),
    ADD CONSTRAINT fk_billing_online_payment_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE billing_online_payments ALTER COLUMN tenant_id DROP DEFAULT;

ALTER TABLE billing_bold_terminal_payments
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_bold_terminal_tenant (tenant_id, status),
    ADD CONSTRAINT fk_bold_terminal_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE billing_bold_terminal_payments ALTER COLUMN tenant_id DROP DEFAULT;

ALTER TABLE admin_messages
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_admin_messages_tenant_created (tenant_id, created_at),
    ADD CONSTRAINT fk_admin_messages_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE admin_messages ALTER COLUMN tenant_id DROP DEFAULT;

ALTER TABLE property_classifieds
    ADD COLUMN tenant_id BIGINT UNSIGNED NOT NULL DEFAULT 1 AFTER id,
    ADD KEY idx_property_classifieds_tenant_status (tenant_id, status, created_at),
    ADD CONSTRAINT fk_property_classifieds_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id);
ALTER TABLE property_classifieds ALTER COLUMN tenant_id DROP DEFAULT;
