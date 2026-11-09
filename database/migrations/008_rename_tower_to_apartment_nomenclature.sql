ALTER TABLE copropiedad_settings
    CHANGE COLUMN tower_nomenclature apartment_nomenclature
        ENUM('numeric_two_digits', 'alphabetic')
        NOT NULL DEFAULT 'numeric_two_digits';
