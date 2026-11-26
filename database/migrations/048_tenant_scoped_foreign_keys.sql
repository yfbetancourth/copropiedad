-- Aislamiento por conjunto en la base de datos: las llaves foráneas entre tablas que tienen tenant_id pasan a ser
-- compuestas (tenant_id, columna) → padre (tenant_id, id). Además de exigir que el registro padre exista, garantizan
-- que pertenezca al MISMO conjunto (antes solo lo controlaba la aplicación).
-- No se convierten, a propósito:
--   * columnas de actor (*_by, sender_id): las puede llenar el Superadmin, que no pertenece a ningún conjunto;
--   * llaves ON DELETE SET NULL: anularían también tenant_id, que es obligatorio.
-- Se conserva la misma regla ON DELETE de cada llave. Idempotente: se puede aplicar más de una vez.

-- Llave (tenant_id, id) en cada tabla padre.
ALTER TABLE admin_roles ADD UNIQUE KEY IF NOT EXISTS uq_admin_roles_tenant_id (tenant_id, id);
ALTER TABLE admin_users ADD UNIQUE KEY IF NOT EXISTS uq_admin_users_tenant_id (tenant_id, id);
ALTER TABLE billing_bank_imports ADD UNIQUE KEY IF NOT EXISTS uq_billing_bank_imports_tenant_id (tenant_id, id);
ALTER TABLE billing_payments ADD UNIQUE KEY IF NOT EXISTS uq_billing_payments_tenant_id (tenant_id, id);
ALTER TABLE billing_statements ADD UNIQUE KEY IF NOT EXISTS uq_billing_statements_tenant_id (tenant_id, id);
ALTER TABLE billing_unidentified_deposits ADD UNIQUE KEY IF NOT EXISTS uq_billing_unidentified_deposits_tenant_id (tenant_id, id);
ALTER TABLE common_areas ADD UNIQUE KEY IF NOT EXISTS uq_common_areas_tenant_id (tenant_id, id);
ALTER TABLE data_consents ADD UNIQUE KEY IF NOT EXISTS uq_data_consents_tenant_id (tenant_id, id);
ALTER TABLE privacy_document_versions ADD UNIQUE KEY IF NOT EXISTS uq_privacy_document_versions_tenant_id (tenant_id, id);
ALTER TABLE resident_types ADD UNIQUE KEY IF NOT EXISTS uq_resident_types_tenant_id (tenant_id, id);

-- Llaves compuestas (reemplazan a las simples).
ALTER TABLE admin_users
    DROP FOREIGN KEY IF EXISTS fk_admin_users_role,
    ADD CONSTRAINT fk_admin_users_role_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, role_id) REFERENCES admin_roles (tenant_id, id);
ALTER TABLE billing_bank_movements
    DROP FOREIGN KEY IF EXISTS fk_bank_movements_import,
    ADD CONSTRAINT fk_billing_bank_movements_import_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, import_id) REFERENCES billing_bank_imports (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE billing_bold_terminal_payments
    DROP FOREIGN KEY IF EXISTS fk_bold_terminal_statement,
    ADD CONSTRAINT fk_billing_bold_terminal_payments_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_bold_terminal_user,
    ADD CONSTRAINT fk_billing_bold_terminal_payments_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id);
ALTER TABLE billing_clearances
    DROP FOREIGN KEY IF EXISTS fk_billing_clearances_statement,
    ADD CONSTRAINT fk_billing_clearances_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id);
ALTER TABLE billing_deposit_claims
    DROP FOREIGN KEY IF EXISTS fk_deposit_claims_deposit,
    ADD CONSTRAINT fk_billing_deposit_claims_deposit_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, deposit_id) REFERENCES billing_unidentified_deposits (tenant_id, id) ON DELETE CASCADE,
    DROP FOREIGN KEY IF EXISTS fk_deposit_claims_statement,
    ADD CONSTRAINT fk_billing_deposit_claims_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_deposit_claims_user,
    ADD CONSTRAINT fk_billing_deposit_claims_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE billing_online_payments
    DROP FOREIGN KEY IF EXISTS fk_billing_online_payment_statement,
    ADD CONSTRAINT fk_billing_online_payments_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_billing_online_payment_user,
    ADD CONSTRAINT fk_billing_online_payments_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id);
ALTER TABLE billing_payments
    DROP FOREIGN KEY IF EXISTS fk_billing_payment_statement,
    ADD CONSTRAINT fk_billing_payments_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id);
ALTER TABLE billing_payment_links
    DROP FOREIGN KEY IF EXISTS fk_billing_payment_links_statement,
    ADD CONSTRAINT fk_billing_payment_links_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_billing_payment_links_user,
    ADD CONSTRAINT fk_billing_payment_links_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id);
ALTER TABLE billing_payment_reports
    DROP FOREIGN KEY IF EXISTS fk_billing_payment_reports_payment,
    ADD CONSTRAINT fk_billing_payment_reports_payment_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, payment_id) REFERENCES billing_payments (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_billing_payment_reports_statement,
    ADD CONSTRAINT fk_billing_payment_reports_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_billing_payment_reports_user,
    ADD CONSTRAINT fk_billing_payment_reports_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id);
ALTER TABLE billing_statements
    DROP FOREIGN KEY IF EXISTS fk_billing_statement_previous,
    ADD CONSTRAINT fk_billing_statements_previous_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, previous_statement_id) REFERENCES billing_statements (tenant_id, id);
ALTER TABLE billing_unidentified_deposits
    DROP FOREIGN KEY IF EXISTS fk_unidentified_deposits_payment,
    ADD CONSTRAINT fk_billing_unidentified_deposits_payment_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, payment_id) REFERENCES billing_payments (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_unidentified_deposits_statement,
    ADD CONSTRAINT fk_billing_unidentified_deposits_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id);
ALTER TABLE billing_wompi_payments
    DROP FOREIGN KEY IF EXISTS fk_billing_wompi_payment_statement,
    ADD CONSTRAINT fk_billing_wompi_payments_statement_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, statement_id) REFERENCES billing_statements (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_billing_wompi_payment_user,
    ADD CONSTRAINT fk_billing_wompi_payments_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id);
ALTER TABLE common_area_bookings
    DROP FOREIGN KEY IF EXISTS fk_common_area_bookings_area,
    ADD CONSTRAINT fk_common_area_bookings_area_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, area_id) REFERENCES common_areas (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_common_area_bookings_user,
    ADD CONSTRAINT fk_common_area_bookings_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE contact_messages
    DROP FOREIGN KEY IF EXISTS fk_contact_messages_consent,
    ADD CONSTRAINT fk_contact_messages_consent_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, consent_id) REFERENCES data_consents (tenant_id, id);
ALTER TABLE data_consents
    DROP FOREIGN KEY IF EXISTS fk_data_consents_authorization,
    ADD CONSTRAINT fk_data_consents_authorization_version_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, authorization_version_id) REFERENCES privacy_document_versions (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_data_consents_policy,
    ADD CONSTRAINT fk_data_consents_data_policy_version_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, data_policy_version_id) REFERENCES privacy_document_versions (tenant_id, id);
ALTER TABLE mail_oauth_connections
    DROP FOREIGN KEY IF EXISTS fk_mail_oauth_user,
    ADD CONSTRAINT fk_mail_oauth_connections_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE mail_oauth_states
    DROP FOREIGN KEY IF EXISTS fk_mail_oauth_states_user,
    ADD CONSTRAINT fk_mail_oauth_states_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE property_classifieds
    DROP FOREIGN KEY IF EXISTS fk_property_classifieds_user,
    ADD CONSTRAINT fk_property_classifieds_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE property_unit_assignments
    DROP FOREIGN KEY IF EXISTS fk_property_unit_assignments_user,
    ADD CONSTRAINT fk_property_unit_assignments_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE resident_conversations
    DROP FOREIGN KEY IF EXISTS fk_resident_conversations_resident,
    ADD CONSTRAINT fk_resident_conversations_resident_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, resident_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE resident_profiles
    DROP FOREIGN KEY IF EXISTS fk_resident_profiles_type,
    ADD CONSTRAINT fk_resident_profiles_resident_type_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, resident_type_id) REFERENCES resident_types (tenant_id, id),
    DROP FOREIGN KEY IF EXISTS fk_resident_profiles_user,
    ADD CONSTRAINT fk_resident_profiles_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
ALTER TABLE user_notices
    DROP FOREIGN KEY IF EXISTS fk_user_notices_user,
    ADD CONSTRAINT fk_user_notices_user_id_tenant FOREIGN KEY IF NOT EXISTS (tenant_id, user_id) REFERENCES admin_users (tenant_id, id) ON DELETE CASCADE;
