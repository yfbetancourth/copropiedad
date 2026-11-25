-- Estructura completa de la base de datos Copropiedad (sin datos), con las migraciones 001 a 038 aplicadas.
-- Generado el 2026-11-25 12:51 desde la base local con mysqldump --no-data.
-- Uso en una base nueva y vacía:
--   mysql -u root -e "CREATE DATABASE copropiedad CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci"
--   mysql -u root copropiedad < database/schema/copropiedad-schema.sql
-- Después: crear el Superadmin con bin/create-superadmin.php. Una base existente se actualiza con database/migrations/.

/*M!999999\- enable the sandbox mode */ 

/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;
/*!40103 SET @OLD_TIME_ZONE=@@TIME_ZONE */;
/*!40103 SET TIME_ZONE='+00:00' */;
/*!40014 SET @OLD_UNIQUE_CHECKS=@@UNIQUE_CHECKS, UNIQUE_CHECKS=0 */;
/*!40014 SET @OLD_FOREIGN_KEY_CHECKS=@@FOREIGN_KEY_CHECKS, FOREIGN_KEY_CHECKS=0 */;
/*!40101 SET @OLD_SQL_MODE=@@SQL_MODE, SQL_MODE='NO_AUTO_VALUE_ON_ZERO' */;
/*!40111 SET @OLD_SQL_NOTES=@@SQL_NOTES, SQL_NOTES=0 */;
DROP TABLE IF EXISTS `admin_message_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_message_recipients` (
  `message_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`message_id`,`user_id`),
  KEY `idx_admin_message_recipients_user_read` (`user_id`,`read_at`),
  CONSTRAINT `fk_admin_message_recipients_message` FOREIGN KEY (`message_id`) REFERENCES `admin_messages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_admin_message_recipients_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `sender_id` bigint(20) unsigned NOT NULL,
  `subject` varchar(160) NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_admin_messages_sender_created` (`sender_id`,`created_at`),
  KEY `idx_admin_messages_tenant_created` (`tenant_id`,`created_at`),
  CONSTRAINT `fk_admin_messages_sender` FOREIGN KEY (`sender_id`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `fk_admin_messages_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_password_reset_tokens`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_password_reset_tokens` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_reset_token_hash` (`token_hash`),
  KEY `idx_admin_reset_user_created` (`user_id`,`created_at`),
  CONSTRAINT `fk_admin_reset_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_role_permissions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_role_permissions` (
  `role_id` bigint(20) unsigned NOT NULL,
  `module_key` varchar(64) NOT NULL,
  `permission_level` enum('none','read','write','total') NOT NULL DEFAULT 'none',
  PRIMARY KEY (`role_id`,`module_key`),
  CONSTRAINT `fk_admin_role_permissions_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_roles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_roles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `tenant_scope` bigint(20) unsigned GENERATED ALWAYS AS (ifnull(`tenant_id`,0)) STORED,
  `name` varchar(64) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `is_system` tinyint(1) NOT NULL DEFAULT 0,
  `kind` enum('staff','resident') NOT NULL DEFAULT 'staff',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_roles_scope_name` (`tenant_scope`,`name`),
  KEY `idx_admin_roles_tenant` (`tenant_id`),
  CONSTRAINT `fk_admin_roles_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_user_mail_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_user_mail_settings` (
  `user_id` bigint(20) unsigned NOT NULL,
  `host` varchar(253) NOT NULL,
  `port` smallint(5) unsigned NOT NULL,
  `username` varchar(254) NOT NULL,
  `password_ciphertext` text NOT NULL,
  `encryption` enum('tls','ssl') NOT NULL,
  `from_address` varchar(254) NOT NULL,
  `from_name` varchar(160) NOT NULL DEFAULT '',
  `is_active` tinyint(1) NOT NULL DEFAULT 0,
  `verified_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  CONSTRAINT `fk_admin_user_mail_settings_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `admin_users`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `admin_users` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned DEFAULT NULL,
  `tenant_scope` bigint(20) unsigned GENERATED ALWAYS AS (ifnull(`tenant_id`,0)) STORED,
  `username` varchar(64) NOT NULL,
  `email` varchar(254) NOT NULL,
  `password_hash` varchar(255) NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `failed_attempts` tinyint(3) unsigned NOT NULL DEFAULT 0,
  `locked_until` datetime DEFAULT NULL,
  `auth_version` int(10) unsigned NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admin_users_scope_username` (`tenant_scope`,`username`),
  UNIQUE KEY `uq_admin_users_scope_email` (`tenant_scope`,`email`),
  KEY `idx_admin_users_role_id` (`role_id`),
  KEY `idx_admin_users_tenant` (`tenant_id`),
  CONSTRAINT `fk_admin_users_role` FOREIGN KEY (`role_id`) REFERENCES `admin_roles` (`id`),
  CONSTRAINT `fk_admin_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_bold_terminal_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_bold_terminal_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `statement_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `integration_id` varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `expected_amount` decimal(15,2) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `transaction_id` varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_bold_terminal_reference` (`reference`),
  UNIQUE KEY `uq_bold_terminal_integration` (`integration_id`),
  UNIQUE KEY `uq_bold_terminal_transaction` (`transaction_id`),
  KEY `idx_bold_terminal_statement` (`statement_id`,`user_id`,`status`),
  KEY `fk_bold_terminal_user` (`user_id`),
  KEY `idx_bold_terminal_tenant` (`tenant_id`,`status`),
  CONSTRAINT `fk_bold_terminal_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_bold_terminal_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_bold_terminal_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `chk_bold_terminal_amount` CHECK (`expected_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_monthly_items`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_monthly_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `unit_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `period` char(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `category` enum('opening_balance','interest','legal','fine','retroactive','extra','other') NOT NULL,
  `description` varchar(160) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_billing_items_unit_period` (`unit_id`,`period`),
  KEY `idx_billing_items_period_unit` (`period`,`unit_id`),
  KEY `fk_billing_item_user` (`created_by`),
  KEY `idx_billing_items_tenant_period_unit` (`tenant_id`,`period`,`unit_id`),
  CONSTRAINT `fk_billing_item_user` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `fk_billing_items_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `chk_billing_item_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_online_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_online_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `statement_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `bold_payment_link` varchar(80) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `checkout_url` varchar(500) NOT NULL,
  `expected_amount` decimal(15,2) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `transaction_id` varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_online_payment_reference` (`reference`),
  UNIQUE KEY `uq_billing_online_payment_bold_link` (`bold_payment_link`),
  UNIQUE KEY `uq_billing_online_payment_transaction` (`transaction_id`),
  KEY `idx_billing_online_payment_statement` (`statement_id`,`user_id`,`status`),
  KEY `fk_billing_online_payment_user` (`user_id`),
  KEY `idx_billing_online_payment_tenant` (`tenant_id`,`status`),
  CONSTRAINT `fk_billing_online_payment_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_billing_online_payment_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_billing_online_payment_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `chk_billing_online_payment_amount` CHECK (`expected_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_payment_links`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_payment_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `statement_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `token_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `token_ciphertext` varchar(255) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `channel` enum('admin','portal') NOT NULL,
  `expires_at` datetime NOT NULL,
  `created_by` bigint(20) unsigned NOT NULL,
  `sent_to` varchar(254) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` bigint(20) unsigned DEFAULT NULL,
  `open_count` int(10) unsigned NOT NULL DEFAULT 0,
  `last_opened_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_payment_links_token` (`token_hash`),
  KEY `idx_billing_payment_links_statement` (`tenant_id`,`statement_id`,`expires_at`),
  KEY `fk_billing_payment_links_statement` (`statement_id`),
  KEY `fk_billing_payment_links_user` (`user_id`),
  KEY `fk_billing_payment_links_creator` (`created_by`),
  KEY `fk_billing_payment_links_revoker` (`revoked_by`),
  CONSTRAINT `fk_billing_payment_links_creator` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `fk_billing_payment_links_revoker` FOREIGN KEY (`revoked_by`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `fk_billing_payment_links_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_billing_payment_links_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_billing_payment_links_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_payment_reports`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_payment_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `statement_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `paid_on` date NOT NULL,
  `method` enum('transferencia','consignacion','efectivo','otro') NOT NULL,
  `reference` varchar(80) NOT NULL DEFAULT '',
  `notes` varchar(255) NOT NULL DEFAULT '',
  `proof_storage_name` varchar(40) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `proof_mime_type` varchar(32) NOT NULL,
  `proof_byte_size` int(10) unsigned NOT NULL,
  `status` enum('pending','processing','approved','rejected') NOT NULL DEFAULT 'pending',
  `review_notes` varchar(255) DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_payment_reports_proof` (`proof_storage_name`),
  KEY `idx_billing_payment_reports_tenant_status` (`tenant_id`,`status`,`created_at`),
  KEY `idx_billing_payment_reports_user` (`tenant_id`,`user_id`,`created_at`),
  KEY `idx_billing_payment_reports_statement` (`statement_id`),
  KEY `fk_billing_payment_reports_user` (`user_id`),
  KEY `fk_billing_payment_reports_reviewer` (`reviewed_by`),
  KEY `fk_billing_payment_reports_payment` (`payment_id`),
  CONSTRAINT `fk_billing_payment_reports_payment` FOREIGN KEY (`payment_id`) REFERENCES `billing_payments` (`id`),
  CONSTRAINT `fk_billing_payment_reports_reviewer` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `fk_billing_payment_reports_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_billing_payment_reports_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_billing_payment_reports_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `chk_billing_payment_report_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `statement_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `received_on` date NOT NULL,
  `reference` varchar(80) NOT NULL DEFAULT '',
  `notes` varchar(255) NOT NULL DEFAULT '',
  `created_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `voided_at` datetime DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `void_reason` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_billing_payment_statement_date` (`statement_id`,`received_on`),
  KEY `fk_billing_payment_user` (`created_by`),
  KEY `idx_billing_payment_received` (`received_on`),
  KEY `fk_billing_payment_voided_by` (`voided_by`),
  KEY `idx_billing_payment_tenant_received` (`tenant_id`,`received_on`),
  CONSTRAINT `fk_billing_payment_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_billing_payment_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_billing_payment_user` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `fk_billing_payment_voided_by` FOREIGN KEY (`voided_by`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `chk_billing_payment_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_statement_lines`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_statement_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `statement_id` bigint(20) unsigned NOT NULL,
  `category` enum('administration','interest','legal','fine','retroactive','extra','other') NOT NULL,
  `description` varchar(160) NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_billing_statement_lines_statement` (`statement_id`),
  CONSTRAINT `fk_billing_statement_line_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `chk_billing_statement_line_amount` CHECK (`amount` >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_statement_recipients`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_statement_recipients` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `statement_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `recipient_email` varchar(254) NOT NULL,
  `recipient_name` varchar(64) NOT NULL,
  `delivery_status` enum('pending','sending','sent','failed') NOT NULL DEFAULT 'pending',
  `delivery_attempts` smallint(5) unsigned NOT NULL DEFAULT 0,
  `delivery_started_at` datetime DEFAULT NULL,
  `last_error` varchar(500) DEFAULT NULL,
  `sent_at` datetime DEFAULT NULL,
  `sent_from` varchar(254) DEFAULT NULL,
  `sent_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_statement_recipient` (`statement_id`,`user_id`),
  KEY `idx_billing_recipient_delivery` (`statement_id`,`delivery_status`),
  KEY `fk_billing_recipient_user` (`user_id`),
  CONSTRAINT `fk_billing_recipient_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_billing_recipient_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_statement_user_reads`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_statement_user_reads` (
  `statement_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `read_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`statement_id`,`user_id`),
  KEY `fk_billing_statement_user_reads_user` (`user_id`),
  CONSTRAINT `fk_billing_statement_user_reads_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_billing_statement_user_reads_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_statements`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_statements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `unit_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `period` char(7) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_name` varchar(160) NOT NULL,
  `unit_category` varchar(80) NOT NULL,
  `issued_at` datetime NOT NULL,
  `issue_date` date NOT NULL,
  `previous_period` char(7) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `previous_statement_id` bigint(20) unsigned DEFAULT NULL,
  `previous_opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_interest` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_legal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_fine` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_retroactive` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_extra` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_other` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_total` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_payments` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `previous_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `opening_balance` decimal(15,2) NOT NULL DEFAULT 0.00,
  `administration_fee` decimal(15,2) NOT NULL DEFAULT 0.00,
  `coefficient` decimal(7,4) DEFAULT NULL,
  `interest` decimal(15,2) NOT NULL DEFAULT 0.00,
  `legal` decimal(15,2) NOT NULL DEFAULT 0.00,
  `fine` decimal(15,2) NOT NULL DEFAULT 0.00,
  `retroactive` decimal(15,2) NOT NULL DEFAULT 0.00,
  `extra` decimal(15,2) NOT NULL DEFAULT 0.00,
  `other` decimal(15,2) NOT NULL DEFAULT 0.00,
  `total_due` decimal(15,2) NOT NULL DEFAULT 0.00,
  `early_discount` decimal(15,2) NOT NULL DEFAULT 0.00,
  `early_payment_date` date NOT NULL,
  `payment_due_date` date NOT NULL,
  `property_name` varchar(160) NOT NULL,
  `property_address` varchar(255) NOT NULL DEFAULT '',
  `property_tax_id` varchar(32) NOT NULL DEFAULT '',
  `account_holder` varchar(160) NOT NULL DEFAULT '',
  `bank_name` varchar(120) NOT NULL DEFAULT '',
  `account_type` enum('corriente','ahorros') DEFAULT NULL,
  `account_number` varchar(34) NOT NULL DEFAULT '',
  `property_email` varchar(254) NOT NULL,
  `issued_by` bigint(20) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_statement_tenant_unit_period` (`tenant_id`,`unit_id`,`period`),
  KEY `idx_billing_statement_period` (`period`),
  KEY `fk_billing_statement_user` (`issued_by`),
  KEY `fk_billing_statement_previous` (`previous_statement_id`),
  KEY `idx_billing_statement_tenant_period` (`tenant_id`,`period`),
  CONSTRAINT `fk_billing_statement_previous` FOREIGN KEY (`previous_statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_billing_statement_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_billing_statement_user` FOREIGN KEY (`issued_by`) REFERENCES `admin_users` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_unidentified_deposits`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_unidentified_deposits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `amount` decimal(15,2) NOT NULL,
  `deposited_on` date NOT NULL,
  `channel` enum('consignacion','transferencia','otro') NOT NULL,
  `bank_reference` varchar(80) NOT NULL DEFAULT '',
  `payer_detail` varchar(160) NOT NULL DEFAULT '',
  `notes` varchar(255) NOT NULL DEFAULT '',
  `proof_storage_name` varchar(40) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `proof_mime_type` varchar(32) DEFAULT NULL,
  `proof_byte_size` int(10) unsigned DEFAULT NULL,
  `status` enum('pending','processing','identified','discarded') NOT NULL DEFAULT 'pending',
  `statement_id` bigint(20) unsigned DEFAULT NULL,
  `payment_id` bigint(20) unsigned DEFAULT NULL,
  `resolution_note` varchar(255) DEFAULT NULL,
  `resolved_by` bigint(20) unsigned DEFAULT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_unidentified_deposits_status` (`tenant_id`,`status`,`deposited_on`),
  KEY `fk_unidentified_deposits_statement` (`statement_id`),
  KEY `fk_unidentified_deposits_payment` (`payment_id`),
  KEY `fk_unidentified_deposits_resolved_by` (`resolved_by`),
  KEY `fk_unidentified_deposits_created_by` (`created_by`),
  CONSTRAINT `fk_unidentified_deposits_created_by` FOREIGN KEY (`created_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_unidentified_deposits_payment` FOREIGN KEY (`payment_id`) REFERENCES `billing_payments` (`id`),
  CONSTRAINT `fk_unidentified_deposits_resolved_by` FOREIGN KEY (`resolved_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_unidentified_deposits_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_unidentified_deposits_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `chk_unidentified_deposit_amount` CHECK (`amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_unit_references`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_unit_references` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `unit_id` char(32) NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `reference` varchar(12) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`tenant_id`,`unit_id`),
  UNIQUE KEY `uq_billing_unit_reference` (`tenant_id`,`reference`),
  UNIQUE KEY `uq_billing_unit_reference_sequence` (`tenant_id`,`sequence`),
  CONSTRAINT `fk_billing_unit_references_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `billing_wompi_payments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `billing_wompi_payments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `statement_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `reference` varchar(60) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `expected_amount` decimal(15,2) NOT NULL,
  `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  `transaction_id` varchar(80) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `payment_method_type` varchar(40) DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_billing_wompi_payment_reference` (`reference`),
  UNIQUE KEY `uq_billing_wompi_payment_transaction` (`transaction_id`),
  KEY `idx_billing_wompi_payment_statement` (`tenant_id`,`statement_id`,`status`),
  KEY `fk_billing_wompi_payment_statement` (`statement_id`),
  KEY `fk_billing_wompi_payment_user` (`user_id`),
  CONSTRAINT `fk_billing_wompi_payment_statement` FOREIGN KEY (`statement_id`) REFERENCES `billing_statements` (`id`),
  CONSTRAINT `fk_billing_wompi_payment_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_billing_wompi_payment_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`),
  CONSTRAINT `chk_billing_wompi_payment_amount` CHECK (`expected_amount` > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `bold_payment_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `bold_payment_settings` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `api_key_ciphertext` text NOT NULL,
  `payment_methods` varchar(255) NOT NULL,
  `api_integrations_key_ciphertext` varchar(2048) NOT NULL DEFAULT '',
  `api_integrations_webhook_secret_ciphertext` varchar(2048) NOT NULL DEFAULT '',
  `api_integrations_terminal_model` varchar(40) NOT NULL DEFAULT '',
  `api_integrations_terminal_serial` varchar(80) NOT NULL DEFAULT '',
  `api_integrations_terminal_name` varchar(80) NOT NULL DEFAULT '',
  `api_integrations_payment_method` varchar(32) NOT NULL DEFAULT 'POS',
  `api_integrations_environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`),
  CONSTRAINT `fk_bold_payment_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `common_area_bookings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `common_area_bookings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `area_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime NOT NULL,
  `guests` smallint(5) unsigned DEFAULT NULL,
  `notes` varchar(255) NOT NULL DEFAULT '',
  `status` enum('pending','approved','rejected','cancelled') NOT NULL DEFAULT 'pending',
  `decision_notes` varchar(255) DEFAULT NULL,
  `decided_by` bigint(20) unsigned DEFAULT NULL,
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_common_area_bookings_agenda` (`tenant_id`,`area_id`,`status`,`starts_at`),
  KEY `idx_common_area_bookings_user` (`tenant_id`,`user_id`,`starts_at`),
  KEY `fk_common_area_bookings_area` (`area_id`),
  KEY `fk_common_area_bookings_user` (`user_id`),
  KEY `fk_common_area_bookings_decider` (`decided_by`),
  CONSTRAINT `fk_common_area_bookings_area` FOREIGN KEY (`area_id`) REFERENCES `common_areas` (`id`),
  CONSTRAINT `fk_common_area_bookings_decider` FOREIGN KEY (`decided_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_common_area_bookings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_common_area_bookings_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_common_area_bookings_range` CHECK (`starts_at` < `ends_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `common_areas`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `common_areas` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `name` varchar(120) NOT NULL,
  `description` varchar(1000) NOT NULL DEFAULT '',
  `rules` varchar(2000) NOT NULL DEFAULT '',
  `capacity` smallint(5) unsigned DEFAULT NULL,
  `opens_at` time NOT NULL,
  `closes_at` time NOT NULL,
  `max_hours` tinyint(3) unsigned NOT NULL DEFAULT 4,
  `max_days_ahead` smallint(5) unsigned NOT NULL DEFAULT 60,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_common_areas_tenant_name` (`tenant_id`,`name`),
  CONSTRAINT `fk_common_areas_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `chk_common_areas_hours` CHECK (`opens_at` < `closes_at`),
  CONSTRAINT `chk_common_areas_max_hours` CHECK (`max_hours` between 1 and 24)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `contact_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `contact_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `consent_id` bigint(20) unsigned NOT NULL,
  `name` varchar(100) NOT NULL,
  `email` varchar(254) NOT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `message` text NOT NULL,
  `status` enum('new','read') NOT NULL DEFAULT 'new',
  `ip_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `read_at` datetime DEFAULT NULL,
  `read_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_contact_messages_tenant_status` (`tenant_id`,`status`,`created_at`),
  KEY `idx_contact_messages_rate` (`tenant_id`,`ip_hash`,`created_at`),
  KEY `fk_contact_messages_consent` (`consent_id`),
  KEY `fk_contact_messages_read_by` (`read_by`),
  CONSTRAINT `fk_contact_messages_consent` FOREIGN KEY (`consent_id`) REFERENCES `data_consents` (`id`),
  CONSTRAINT `fk_contact_messages_read_by` FOREIGN KEY (`read_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_contact_messages_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copropiedad_composition`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `copropiedad_composition` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `housing_type` enum('casa','torres_apartamento','mixto') NOT NULL,
  `answers` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL CHECK (json_valid(`answers`)),
  `units` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`units`)),
  `completed` tinyint(1) NOT NULL DEFAULT 0,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`),
  CONSTRAINT `fk_copropiedad_composition_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copropiedad_mail_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `copropiedad_mail_settings` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `host` varchar(253) NOT NULL,
  `port` smallint(5) unsigned NOT NULL,
  `username` varchar(254) NOT NULL,
  `password_ciphertext` text NOT NULL,
  `encryption` enum('tls','ssl') NOT NULL,
  `from_address` varchar(254) NOT NULL,
  `from_name` varchar(160) NOT NULL DEFAULT '',
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`),
  CONSTRAINT `fk_copropiedad_mail_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `copropiedad_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `copropiedad_settings` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `property_name` varchar(160) NOT NULL DEFAULT '',
  `housing_type` enum('casa','torres_apartamento','mixto') DEFAULT NULL,
  `tower_nomenclature` enum('numeric','alphabetic') NOT NULL DEFAULT 'numeric',
  `apartment_nomenclature` enum('piso_numero','piso_apartamento','consecutivo') NOT NULL DEFAULT 'piso_numero',
  `address` varchar(255) NOT NULL DEFAULT '',
  `tax_id` varchar(32) NOT NULL DEFAULT '',
  `account_holder` varchar(160) NOT NULL DEFAULT '',
  `bank_name` varchar(120) NOT NULL DEFAULT '',
  `account_type` enum('corriente','ahorros') DEFAULT NULL,
  `account_number` varchar(34) NOT NULL DEFAULT '',
  `early_payment_day` tinyint(3) unsigned DEFAULT NULL,
  `payment_due_day` tinyint(3) unsigned DEFAULT NULL,
  `administration_fee` decimal(15,2) DEFAULT NULL,
  `fee_basis` enum('base','budget') NOT NULL DEFAULT 'base',
  `effective_date` date DEFAULT NULL,
  `early_payment_discount` decimal(15,2) DEFAULT NULL,
  `late_fee_type` enum('percentage','fixed') NOT NULL DEFAULT 'percentage',
  `late_fee_value` decimal(15,2) DEFAULT NULL,
  `late_fee_days` smallint(5) unsigned NOT NULL DEFAULT 0,
  `fine_amount` decimal(15,2) DEFAULT NULL,
  `extraordinary_fee` decimal(15,2) DEFAULT NULL,
  `extraordinary_fee_start_date` date DEFAULT NULL,
  `extraordinary_fee_end_date` date DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`),
  CONSTRAINT `fk_copropiedad_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `chk_copropiedad_settings_percentage` CHECK (`late_fee_type` <> 'percentage' or `late_fee_value` is null or `late_fee_value` <= 100),
  CONSTRAINT `chk_copropiedad_payment_due_day` CHECK (`payment_due_day` is null or `payment_due_day` between 1 and 28),
  CONSTRAINT `chk_copropiedad_payment_days` CHECK (`early_payment_day` is null or `payment_due_day` is null or `early_payment_day` <= `payment_due_day`),
  CONSTRAINT `chk_copropiedad_extraordinary_fee_nonnegative` CHECK (`extraordinary_fee` is null or `extraordinary_fee` >= 0),
  CONSTRAINT `chk_copropiedad_extraordinary_fee_dates` CHECK (`extraordinary_fee` is null and `extraordinary_fee_start_date` is null and `extraordinary_fee_end_date` is null or `extraordinary_fee` is not null and `extraordinary_fee_start_date` is not null and `extraordinary_fee_end_date` is not null and `extraordinary_fee_start_date` <= `extraordinary_fee_end_date`),
  CONSTRAINT `chk_copropiedad_early_payment_day` CHECK (`early_payment_day` is null or `early_payment_day` between 0 and 28)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `data_consents`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `data_consents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `source` enum('contact_form') NOT NULL,
  `subject_name` varchar(100) NOT NULL,
  `subject_email` varchar(254) NOT NULL,
  `subject_phone` varchar(30) DEFAULT NULL,
  `data_policy_version_id` bigint(20) unsigned NOT NULL,
  `authorization_version_id` bigint(20) unsigned NOT NULL,
  `granted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `ip_address` varchar(45) NOT NULL,
  `user_agent` varchar(255) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by` bigint(20) unsigned DEFAULT NULL,
  `revocation_note` varchar(255) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `idx_data_consents_tenant_granted` (`tenant_id`,`granted_at`),
  KEY `idx_data_consents_tenant_email` (`tenant_id`,`subject_email`),
  KEY `fk_data_consents_policy` (`data_policy_version_id`),
  KEY `fk_data_consents_authorization` (`authorization_version_id`),
  KEY `fk_data_consents_revoked_by` (`revoked_by`),
  CONSTRAINT `fk_data_consents_authorization` FOREIGN KEY (`authorization_version_id`) REFERENCES `privacy_document_versions` (`id`),
  CONSTRAINT `fk_data_consents_policy` FOREIGN KEY (`data_policy_version_id`) REFERENCES `privacy_document_versions` (`id`),
  CONSTRAINT `fk_data_consents_revoked_by` FOREIGN KEY (`revoked_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_data_consents_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `privacy_document_versions`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `privacy_document_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `document` enum('data_policy','authorization','cookie_policy') NOT NULL,
  `version` smallint(5) unsigned NOT NULL,
  `content` mediumtext NOT NULL,
  `change_summary` varchar(255) NOT NULL,
  `published_at` datetime NOT NULL DEFAULT current_timestamp(),
  `published_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_privacy_document_version` (`tenant_id`,`document`,`version`),
  KEY `fk_privacy_versions_published_by` (`published_by`),
  CONSTRAINT `fk_privacy_versions_published_by` FOREIGN KEY (`published_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_privacy_versions_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `privacy_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `privacy_settings` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `contact_email` varchar(254) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `responsible_area` varchar(120) DEFAULT NULL,
  `contact_form_enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`tenant_id`),
  KEY `fk_privacy_settings_updated_by` (`updated_by`),
  CONSTRAINT `fk_privacy_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_privacy_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_classified_images`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_classified_images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `listing_id` bigint(20) unsigned NOT NULL,
  `storage_name` char(40) NOT NULL,
  `mime_type` varchar(32) NOT NULL,
  `byte_size` int(10) unsigned NOT NULL,
  `width` smallint(5) unsigned NOT NULL,
  `height` smallint(5) unsigned NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_property_classified_images_storage_name` (`storage_name`),
  KEY `idx_property_classified_images_listing` (`listing_id`,`id`),
  CONSTRAINT `fk_property_classified_images_listing` FOREIGN KEY (`listing_id`) REFERENCES `property_classifieds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_classifieds`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_classifieds` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `operation` enum('sale','purchase','rent') NOT NULL,
  `title` varchar(160) NOT NULL,
  `property_type` enum('house','apartment','commercial','other') NOT NULL,
  `location` varchar(120) NOT NULL,
  `description` text NOT NULL,
  `price` decimal(14,2) DEFAULT NULL,
  `status` enum('pending','published','rejected','closed') NOT NULL DEFAULT 'pending',
  `review_note` varchar(255) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `reviewed_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `contact_name` varchar(100) DEFAULT NULL,
  `contact_email` varchar(254) DEFAULT NULL,
  `contact_phone` varchar(30) DEFAULT NULL,
  `contact_authorized` tinyint(1) NOT NULL DEFAULT 0,
  `contact_authorized_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_property_classifieds_status_created` (`status`,`created_at`),
  KEY `idx_property_classifieds_user_created` (`user_id`,`created_at`),
  KEY `idx_property_classifieds_tenant_status` (`tenant_id`,`status`,`created_at`),
  KEY `fk_property_classifieds_reviewed_by` (`reviewed_by`),
  CONSTRAINT `fk_property_classifieds_reviewed_by` FOREIGN KEY (`reviewed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_property_classifieds_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_property_classifieds_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_unit_assignments`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_unit_assignments` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `unit_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `relation_type` enum('propietario','inquilino','residente') NOT NULL DEFAULT 'propietario',
  `resides` tinyint(1) NOT NULL DEFAULT 0,
  `started_on` date NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`unit_id`,`user_id`),
  KEY `idx_property_unit_assignments_user` (`user_id`),
  KEY `idx_property_unit_assignments_tenant_unit` (`tenant_id`,`unit_id`),
  CONSTRAINT `fk_property_unit_assignments_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_property_unit_assignments_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `property_unit_relationship_history`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `property_unit_relationship_history` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `unit_id` char(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `unit_name` varchar(190) NOT NULL DEFAULT '',
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `user_name` varchar(64) NOT NULL,
  `relation_type` enum('propietario','inquilino','residente') NOT NULL,
  `resides` tinyint(1) NOT NULL DEFAULT 0,
  `started_on` date NOT NULL,
  `ended_on` date DEFAULT NULL,
  `end_reason` enum('retiro','cambio_vinculo','inmueble_eliminado','reinicio_conformacion') DEFAULT NULL,
  `started_by` bigint(20) unsigned DEFAULT NULL,
  `ended_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_unit_history_unit` (`tenant_id`,`unit_id`,`started_on`),
  KEY `idx_unit_history_user` (`tenant_id`,`user_id`),
  KEY `idx_unit_history_open` (`tenant_id`,`unit_id`,`user_id`,`ended_on`),
  KEY `fk_unit_history_user` (`user_id`),
  KEY `fk_unit_history_started_by` (`started_by`),
  KEY `fk_unit_history_ended_by` (`ended_by`),
  CONSTRAINT `fk_unit_history_ended_by` FOREIGN KEY (`ended_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_unit_history_started_by` FOREIGN KEY (`started_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_unit_history_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_unit_history_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `chk_unit_history_dates` CHECK (`ended_on` is null or `ended_on` >= `started_on`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `public_site_content`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `public_site_content` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `published_content` longtext DEFAULT NULL,
  `published_at` datetime DEFAULT NULL,
  `published_by` bigint(20) unsigned DEFAULT NULL,
  `draft_content` longtext DEFAULT NULL,
  `draft_updated_at` datetime DEFAULT NULL,
  `draft_updated_by` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`tenant_id`),
  KEY `fk_public_site_published_by` (`published_by`),
  KEY `fk_public_site_draft_by` (`draft_updated_by`),
  CONSTRAINT `fk_public_site_draft_by` FOREIGN KEY (`draft_updated_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_public_site_published_by` FOREIGN KEY (`published_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_public_site_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `chk_public_site_published_json` CHECK (`published_content` is null or json_valid(`published_content`)),
  CONSTRAINT `chk_public_site_draft_json` CHECK (`draft_content` is null or json_valid(`draft_content`))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resident_conversation_messages`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resident_conversation_messages` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `conversation_id` bigint(20) unsigned NOT NULL,
  `author_id` bigint(20) unsigned DEFAULT NULL,
  `author_side` enum('resident','staff') NOT NULL,
  `body` text NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_resident_conversation_messages_thread` (`conversation_id`,`id`),
  KEY `fk_resident_conversation_messages_author` (`author_id`),
  CONSTRAINT `fk_resident_conversation_messages_author` FOREIGN KEY (`author_id`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_resident_conversation_messages_conversation` FOREIGN KEY (`conversation_id`) REFERENCES `resident_conversations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resident_conversations`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resident_conversations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `resident_id` bigint(20) unsigned NOT NULL,
  `category` enum('consulta','peticion','queja','reclamo','sugerencia') NOT NULL,
  `subject` varchar(160) NOT NULL,
  `status` enum('open','answered','closed') NOT NULL DEFAULT 'open',
  `resident_unread` tinyint(1) NOT NULL DEFAULT 0,
  `staff_unread` tinyint(1) NOT NULL DEFAULT 1,
  `last_message_at` datetime NOT NULL DEFAULT current_timestamp(),
  `closed_at` datetime DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_resident_conversations_inbox` (`tenant_id`,`status`,`last_message_at`),
  KEY `idx_resident_conversations_resident` (`tenant_id`,`resident_id`,`last_message_at`),
  KEY `fk_resident_conversations_resident` (`resident_id`),
  KEY `fk_resident_conversations_closed_by` (`closed_by`),
  CONSTRAINT `fk_resident_conversations_closed_by` FOREIGN KEY (`closed_by`) REFERENCES `admin_users` (`id`) ON DELETE SET NULL,
  CONSTRAINT `fk_resident_conversations_resident` FOREIGN KEY (`resident_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_resident_conversations_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resident_profiles`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resident_profiles` (
  `user_id` bigint(20) unsigned NOT NULL,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `full_name` varchar(160) NOT NULL,
  `resident_type_id` bigint(20) unsigned DEFAULT NULL,
  `document_type` enum('CC','CE','TI','PP','PPT','NIT','OTRO') DEFAULT NULL,
  `document_number` varchar(30) DEFAULT NULL,
  `phone` varchar(30) DEFAULT NULL,
  `notes` varchar(500) NOT NULL DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `uq_resident_profiles_document` (`tenant_id`,`document_type`,`document_number`),
  KEY `idx_resident_profiles_tenant_name` (`tenant_id`,`full_name`),
  KEY `idx_resident_profiles_type` (`resident_type_id`),
  CONSTRAINT `fk_resident_profiles_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`),
  CONSTRAINT `fk_resident_profiles_type` FOREIGN KEY (`resident_type_id`) REFERENCES `resident_types` (`id`),
  CONSTRAINT `fk_resident_profiles_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `resident_types`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `resident_types` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `name` varchar(80) NOT NULL,
  `description` varchar(255) NOT NULL DEFAULT '',
  `requires_unit_owner` tinyint(1) NOT NULL DEFAULT 0,
  `is_active` tinyint(1) NOT NULL DEFAULT 1,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_resident_types_name` (`tenant_id`,`name`),
  CONSTRAINT `fk_resident_types_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenant_modules`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenant_modules` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `module_key` varchar(64) NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 1,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`,`module_key`),
  CONSTRAINT `fk_tenant_modules_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `tenants`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `tenants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `slug` varchar(63) CHARACTER SET ascii COLLATE ascii_general_ci NOT NULL,
  `name` varchar(160) NOT NULL,
  `status` enum('active','suspended') NOT NULL DEFAULT 'active',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_tenants_slug` (`slug`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `user_notices`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `user_notices` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `tenant_id` bigint(20) unsigned NOT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `kind` varchar(40) NOT NULL,
  `title` varchar(160) NOT NULL,
  `body` varchar(500) NOT NULL DEFAULT '',
  `link` varchar(255) NOT NULL DEFAULT '',
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user_notices_tenant_user_read` (`tenant_id`,`user_id`,`read_at`,`created_at`),
  KEY `fk_user_notices_user` (`user_id`),
  CONSTRAINT `fk_user_notices_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_user_notices_user` FOREIGN KEY (`user_id`) REFERENCES `admin_users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
DROP TABLE IF EXISTS `wompi_payment_settings`;
/*!40101 SET @saved_cs_client     = @@character_set_client */;
/*!40101 SET character_set_client = utf8mb4 */;
CREATE TABLE `wompi_payment_settings` (
  `tenant_id` bigint(20) unsigned NOT NULL,
  `environment` enum('sandbox','production') NOT NULL DEFAULT 'sandbox',
  `public_key` varchar(120) NOT NULL,
  `private_key_ciphertext` text NOT NULL,
  `events_secret_ciphertext` text NOT NULL,
  `integrity_secret_ciphertext` text NOT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`tenant_id`),
  CONSTRAINT `fk_wompi_payment_settings_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
/*!40101 SET character_set_client = @saved_cs_client */;
/*!40103 SET TIME_ZONE=@OLD_TIME_ZONE */;

/*!40101 SET SQL_MODE=@OLD_SQL_MODE */;
/*!40014 SET FOREIGN_KEY_CHECKS=@OLD_FOREIGN_KEY_CHECKS */;
/*!40014 SET UNIQUE_CHECKS=@OLD_UNIQUE_CHECKS */;
/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
/*!40111 SET SQL_NOTES=@OLD_SQL_NOTES */;

