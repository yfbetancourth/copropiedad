-- Nomenclatura de apartamentos elegible:
--   piso_numero      -> Torre 1 - Apartamento 101 (piso + dos digitos)
--   piso_apartamento -> Torre 1 - Piso 1 - Apartamento 01 (formato anterior)
--   consecutivo      -> Torre 1 - Apartamento 1 (consecutivo dentro de cada torre)
ALTER TABLE copropiedad_settings
    ADD COLUMN apartment_nomenclature ENUM('piso_numero', 'piso_apartamento', 'consecutivo')
        NOT NULL DEFAULT 'piso_numero'
        AFTER tower_nomenclature;

-- Las copropiedades que ya tienen estructura conservan el formato con el que se generaron sus inmuebles.
UPDATE copropiedad_settings AS settings
JOIN copropiedad_composition AS composition ON composition.id = 1
SET settings.apartment_nomenclature = 'piso_apartamento'
WHERE settings.id = 1
  AND (composition.units IS NOT NULL OR JSON_LENGTH(composition.answers) > 0);
