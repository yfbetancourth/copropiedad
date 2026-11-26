-- Normalización: las respuestas del asistente de conformación pasan del JSON copropiedad_composition.answers a
-- columnas propias (NULL = pregunta aún sin responder; 0..5000 como valida el formulario).
-- Se elimina copropiedad_composition.housing_type: duplicaba copropiedad_settings.housing_type (al cambiar la
-- caracterización la conformación siempre se borra, así que ambos valores eran siempre iguales).
-- Idempotente: se puede aplicar más de una vez.

ALTER TABLE copropiedad_composition
    ADD COLUMN IF NOT EXISTS houses SMALLINT UNSIGNED NULL AFTER tenant_id,
    ADD COLUMN IF NOT EXISTS towers SMALLINT UNSIGNED NULL AFTER houses,
    ADD COLUMN IF NOT EXISTS floors_per_tower SMALLINT UNSIGNED NULL AFTER towers,
    ADD COLUMN IF NOT EXISTS units_per_floor SMALLINT UNSIGNED NULL AFTER floors_per_tower,
    ADD COLUMN IF NOT EXISTS commercial_properties SMALLINT UNSIGNED NULL AFTER units_per_floor,
    ADD COLUMN IF NOT EXISTS parking_spots SMALLINT UNSIGNED NULL AFTER commercial_properties,
    ADD COLUMN IF NOT EXISTS storage_units SMALLINT UNSIGNED NULL AFTER parking_spots;

-- Traspaso desde el JSON, solo mientras la columna exista.
DELIMITER //
BEGIN NOT ATOMIC
    IF EXISTS (SELECT 1 FROM information_schema.COLUMNS
               WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'copropiedad_composition' AND COLUMN_NAME = 'answers') THEN
        EXECUTE IMMEDIATE
            'UPDATE copropiedad_composition SET
                houses = JSON_VALUE(answers, ''$.houses''),
                towers = JSON_VALUE(answers, ''$.towers''),
                floors_per_tower = JSON_VALUE(answers, ''$.floors_per_tower''),
                units_per_floor = JSON_VALUE(answers, ''$.units_per_floor''),
                commercial_properties = JSON_VALUE(answers, ''$.commercial_properties''),
                parking_spots = JSON_VALUE(answers, ''$.parking_spots''),
                storage_units = JSON_VALUE(answers, ''$.storage_units''),
                updated_at = updated_at';
    END IF;
END //
DELIMITER ;

ALTER TABLE copropiedad_composition
    DROP CONSTRAINT IF EXISTS chk_composition_answers_range;
ALTER TABLE copropiedad_composition
    ADD CONSTRAINT chk_composition_answers_range CHECK (
        COALESCE(houses, 1) BETWEEN 1 AND 5000
        AND COALESCE(towers, 1) BETWEEN 1 AND 5000
        AND COALESCE(floors_per_tower, 1) BETWEEN 1 AND 5000
        AND COALESCE(units_per_floor, 1) BETWEEN 1 AND 5000
        AND COALESCE(commercial_properties, 1) BETWEEN 1 AND 5000
        AND COALESCE(parking_spots, 0) <= 5000
        AND COALESCE(storage_units, 0) <= 5000
    );

ALTER TABLE copropiedad_composition
    DROP COLUMN IF EXISTS answers,
    DROP COLUMN IF EXISTS housing_type;
