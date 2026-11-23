-- Atención a residentes: el residente escribe a la administración desde su portal (consulta, petición, queja,
-- reclamo o sugerencia) y conversa con el equipo hasta que la solicitud se cierra. Forma parte del módulo
-- «Mensajes»; en el panel la atienden las cuentas con el permiso Mensajes.
-- Estados: open = espera respuesta de la administración; answered = la administración respondió;
--          closed = cerrada (el residente puede abrir una nueva).

CREATE TABLE IF NOT EXISTS resident_conversations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    resident_id BIGINT UNSIGNED NOT NULL,
    category ENUM('consulta', 'peticion', 'queja', 'reclamo', 'sugerencia') NOT NULL,
    subject VARCHAR(160) NOT NULL,
    status ENUM('open', 'answered', 'closed') NOT NULL DEFAULT 'open',
    resident_unread TINYINT(1) NOT NULL DEFAULT 0,
    staff_unread TINYINT(1) NOT NULL DEFAULT 1,
    last_message_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    closed_at DATETIME NULL,
    closed_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resident_conversations_inbox (tenant_id, status, last_message_at),
    KEY idx_resident_conversations_resident (tenant_id, resident_id, last_message_at),
    CONSTRAINT fk_resident_conversations_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_resident_conversations_resident FOREIGN KEY (resident_id) REFERENCES admin_users (id) ON DELETE CASCADE,
    CONSTRAINT fk_resident_conversations_closed_by FOREIGN KEY (closed_by) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS resident_conversation_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    conversation_id BIGINT UNSIGNED NOT NULL,
    author_id BIGINT UNSIGNED NULL,
    author_side ENUM('resident', 'staff') NOT NULL,
    body TEXT NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_resident_conversation_messages_thread (conversation_id, id),
    CONSTRAINT fk_resident_conversation_messages_conversation FOREIGN KEY (conversation_id) REFERENCES resident_conversations (id) ON DELETE CASCADE,
    CONSTRAINT fk_resident_conversation_messages_author FOREIGN KEY (author_id) REFERENCES admin_users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
