-- Regla de registro por tipo de residente: un tipo marcado con requires_unit_owner (por defecto «Inquilino») solo se
-- registra si se vincula a un inmueble que ya tiene propietario vigente. Se usa una marca y no el nombre porque el
-- catálogo es editable.

ALTER TABLE resident_types
    ADD COLUMN IF NOT EXISTS requires_unit_owner TINYINT(1) NOT NULL DEFAULT 0 AFTER description;

UPDATE resident_types SET requires_unit_owner = 1 WHERE name = 'Inquilino';
