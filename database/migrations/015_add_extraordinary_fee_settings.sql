ALTER TABLE copropiedad_settings
    ADD COLUMN extraordinary_fee DECIMAL(15, 2) NULL AFTER fine_amount,
    ADD COLUMN extraordinary_fee_start_date DATE NULL AFTER extraordinary_fee,
    ADD COLUMN extraordinary_fee_end_date DATE NULL AFTER extraordinary_fee_start_date,
    ADD CONSTRAINT chk_copropiedad_extraordinary_fee_nonnegative
        CHECK (extraordinary_fee IS NULL OR extraordinary_fee >= 0),
    ADD CONSTRAINT chk_copropiedad_extraordinary_fee_dates
        CHECK (
            (extraordinary_fee IS NULL AND extraordinary_fee_start_date IS NULL AND extraordinary_fee_end_date IS NULL)
            OR (
                extraordinary_fee IS NOT NULL
                AND extraordinary_fee_start_date IS NOT NULL
                AND extraordinary_fee_end_date IS NOT NULL
                AND extraordinary_fee_start_date <= extraordinary_fee_end_date
            )
        );
