ALTER TABLE copropiedad_settings
    MODIFY COLUMN apartment_nomenclature
        ENUM('numeric_two_digits', 'alphabetic', 'numeric')
        NOT NULL DEFAULT 'numeric';

UPDATE copropiedad_settings
SET apartment_nomenclature = 'numeric'
WHERE apartment_nomenclature = 'numeric_two_digits';

ALTER TABLE copropiedad_settings
    CHANGE COLUMN apartment_nomenclature tower_nomenclature
        ENUM('numeric', 'alphabetic')
        NOT NULL DEFAULT 'numeric';
