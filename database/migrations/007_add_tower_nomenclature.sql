ALTER TABLE copropiedad_settings
    ADD COLUMN tower_nomenclature ENUM('numeric_two_digits', 'alphabetic')
        NOT NULL DEFAULT 'numeric_two_digits' AFTER housing_type;
