ALTER TABLE copropiedad_settings
    ADD COLUMN account_number VARCHAR(34) NOT NULL DEFAULT '' AFTER account_type;
