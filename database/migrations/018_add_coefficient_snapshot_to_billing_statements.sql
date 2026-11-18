ALTER TABLE billing_statements
    ADD COLUMN coefficient DECIMAL(7, 4) NULL AFTER administration_fee;
