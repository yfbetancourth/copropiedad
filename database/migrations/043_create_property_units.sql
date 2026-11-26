-- Normalización: los inmuebles pasan de un JSON (copropiedad_composition.units) a su propia tabla.
--   property_units = un inmueble por fila, con su categoría, nombre, coeficiente y orden de presentación.
--                    Llave (tenant_id, id): permite llaves foráneas compuestas que, además de exigir que el inmueble
--                    exista, garantizan que pertenezca al mismo conjunto que el registro que lo referencia.
-- Las tablas que guardan unit_id quedan amarradas a property_units con ON DELETE RESTRICT: un inmueble con cartera,
-- referencia de pago o vínculos vigentes no se puede borrar (antes solo lo impedía la aplicación).
-- property_unit_relationship_history NO lleva llave foránea a propósito: es un registro histórico que conserva
-- unit_name y debe sobrevivir al borrado del inmueble (motivo «inmueble_eliminado»).
-- Todos los unit_id quedan en ascii_bin (identificadores hexadecimales; dos tablas estaban en utf8mb4_unicode_ci).
-- Idempotente: se puede aplicar más de una vez. Requiere MariaDB 10.6+ (JSON_TABLE).

CREATE TABLE IF NOT EXISTS property_units (
    tenant_id BIGINT UNSIGNED NOT NULL,
    id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    sort_order INT UNSIGNED NOT NULL,
    category VARCHAR(80) NOT NULL,
    name VARCHAR(160) NOT NULL,
    coefficient DECIMAL(7,4) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (tenant_id, id),
    UNIQUE KEY uq_property_units_name (tenant_id, name),
    KEY idx_property_units_order (tenant_id, sort_order),
    CONSTRAINT chk_property_units_id CHECK (id REGEXP '^[a-f0-9]{32}$'),
    CONSTRAINT chk_property_units_coefficient CHECK (coefficient IS NULL OR coefficient BETWEEN 0 AND 100),
    CONSTRAINT fk_property_units_tenant FOREIGN KEY (tenant_id) REFERENCES tenants (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Traspaso desde el JSON, solo mientras la columna exista (la consulta se arma en tiempo de ejecución para que el
-- script no falle al reaplicarlo cuando la columna ya se eliminó).
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copropiedad_composition' AND COLUMN_NAME = 'units') THEN
        EXECUTE IMMEDIATE
            'INSERT IGNORE INTO property_units (tenant_id, id, sort_order, category, name, coefficient)
             SELECT c.tenant_id, j.id, j.position, j.category, j.name, NULLIF(j.coefficient, '''')
             FROM copropiedad_composition AS c,
                  JSON_TABLE(c.units, ''$[*]'' COLUMNS (
                      position FOR ORDINALITY,
                      id VARCHAR(32) PATH ''$.id'',
                      category VARCHAR(80) PATH ''$.category'',
                      name VARCHAR(160) PATH ''$.name'',
                      coefficient VARCHAR(12) PATH ''$.coefficient''
                  )) AS j
             WHERE c.units IS NOT NULL';
    END IF;
END //
DELIMITER ;

ALTER TABLE billing_clearances
    MODIFY unit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;
ALTER TABLE billing_unit_references
    MODIFY unit_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL;

ALTER TABLE property_unit_assignments
    ADD CONSTRAINT fk_unit_assignments_unit FOREIGN KEY IF NOT EXISTS (tenant_id, unit_id)
        REFERENCES property_units (tenant_id, id);
ALTER TABLE billing_statements
    ADD CONSTRAINT fk_billing_statements_unit FOREIGN KEY IF NOT EXISTS (tenant_id, unit_id)
        REFERENCES property_units (tenant_id, id);
ALTER TABLE billing_monthly_items
    ADD KEY IF NOT EXISTS idx_billing_items_tenant_unit (tenant_id, unit_id),
    ADD CONSTRAINT fk_billing_items_unit FOREIGN KEY IF NOT EXISTS (tenant_id, unit_id)
        REFERENCES property_units (tenant_id, id);
ALTER TABLE billing_clearances
    ADD CONSTRAINT fk_billing_clearances_unit FOREIGN KEY IF NOT EXISTS (tenant_id, unit_id)
        REFERENCES property_units (tenant_id, id);
ALTER TABLE billing_unit_references
    ADD CONSTRAINT fk_billing_unit_references_unit FOREIGN KEY IF NOT EXISTS (tenant_id, unit_id)
        REFERENCES property_units (tenant_id, id);

-- El JSON queda reemplazado por la tabla.
ALTER TABLE copropiedad_composition DROP COLUMN IF EXISTS units;
