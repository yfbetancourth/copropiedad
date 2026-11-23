-- Vínculo de cada persona con el inmueble (Propietario / Inquilino / Residente) y su trazabilidad.
--
-- property_unit_assignments sigue siendo el vínculo VIGENTE (lo usan el portal, el cobro y las notificaciones).
-- property_unit_relationship_history guarda cada vínculo con su fecha de inicio y de fin; nunca se borra,
-- y conserva el nombre del inmueble y de la persona para que el historial sobreviva a cambios de estructura.

ALTER TABLE property_unit_assignments
    ADD COLUMN relation_type ENUM('propietario', 'inquilino', 'residente') NOT NULL DEFAULT 'propietario' AFTER user_id,
    ADD COLUMN resides TINYINT(1) NOT NULL DEFAULT 0 AFTER relation_type,
    ADD COLUMN started_on DATE NULL AFTER resides;

UPDATE property_unit_assignments SET started_on = DATE(created_at) WHERE started_on IS NULL;
ALTER TABLE property_unit_assignments MODIFY started_on DATE NOT NULL;

CREATE TABLE IF NOT EXISTS property_unit_relationship_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tenant_id BIGINT UNSIGNED NOT NULL,
    unit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    unit_name VARCHAR(190) NOT NULL DEFAULT '',
    user_id BIGINT UNSIGNED NULL,
    user_name VARCHAR(64) NOT NULL,
    relation_type ENUM('propietario', 'inquilino', 'residente') NOT NULL,
    resides TINYINT(1) NOT NULL DEFAULT 0,
    started_on DATE NOT NULL,
    ended_on DATE NULL,
    end_reason ENUM('retiro', 'cambio_vinculo', 'inmueble_eliminado', 'reinicio_conformacion') NULL,
    started_by BIGINT UNSIGNED NULL,
    ended_by BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_unit_history_unit (tenant_id, unit_id, started_on),
    KEY idx_unit_history_user (tenant_id, user_id),
    KEY idx_unit_history_open (tenant_id, unit_id, user_id, ended_on),
    CONSTRAINT fk_unit_history_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id),
    CONSTRAINT fk_unit_history_user FOREIGN KEY (user_id) REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_unit_history_started_by FOREIGN KEY (started_by) REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT fk_unit_history_ended_by FOREIGN KEY (ended_by) REFERENCES admin_users (id) ON DELETE SET NULL,
    CONSTRAINT chk_unit_history_dates CHECK (ended_on IS NULL OR ended_on >= started_on)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Los vínculos existentes se registran como propietarios desde la fecha en que se asignaron.
INSERT INTO property_unit_relationship_history
    (tenant_id, unit_id, unit_name, user_id, user_name, relation_type, resides, started_on)
SELECT assignments.tenant_id,
       assignments.unit_id,
       COALESCE((
           SELECT units.name
           FROM copropiedad_composition AS composition,
                JSON_TABLE(composition.units, '$[*]' COLUMNS (
                    id VARCHAR(32) PATH '$.id',
                    name VARCHAR(190) PATH '$.name'
                )) AS units
           WHERE composition.tenant_id = assignments.tenant_id
             AND units.id = assignments.unit_id
           LIMIT 1
       ), ''),
       assignments.user_id,
       users.username,
       assignments.relation_type,
       assignments.resides,
       assignments.started_on
FROM property_unit_assignments AS assignments
JOIN admin_users AS users ON users.id = assignments.user_id
WHERE NOT EXISTS (
    SELECT 1 FROM property_unit_relationship_history AS history
    WHERE history.tenant_id = assignments.tenant_id
      AND history.unit_id = assignments.unit_id
      AND history.user_id = assignments.user_id
      AND history.ended_on IS NULL
);
