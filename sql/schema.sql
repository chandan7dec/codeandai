-- ============================================================
-- Demo Class Registration System - MySQL Schema
-- ============================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ── Demo Classes ─────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `demo_classes` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `title` VARCHAR(255) NOT NULL,
    `topic` VARCHAR(255) NOT NULL DEFAULT '',
    `trainer_name` VARCHAR(255) NOT NULL DEFAULT '',
    `scheduled_at` DATETIME NOT NULL,
    `timezone` VARCHAR(100) NOT NULL,
    `teams_link` VARCHAR(500) NULL DEFAULT NULL,
    `status` ENUM('active', 'cancelled', 'archived') NOT NULL DEFAULT 'active',
    `registration_open` TINYINT(1) NOT NULL DEFAULT 0,
    `capacity` INT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_status` (`status`),
    INDEX `idx_public_availability` (`status`, `registration_open`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Registrants ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `registrants` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `name` VARCHAR(255) NOT NULL,
    `email` VARCHAR(255) NOT NULL,
    `phone_number` VARCHAR(50) NOT NULL,
    `consented_to_whatsapp` TINYINT(1) NOT NULL DEFAULT 0,
    `wa_consent_at` DATETIME NULL DEFAULT NULL,
    `wa_consent_withdrawn_at` DATETIME NULL DEFAULT NULL,
    `privacy_notice_accepted_at` DATETIME NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_email` (`email`),
    INDEX `idx_phone` (`phone_number`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Registrations ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `registrations` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `registrant_id` VARCHAR(36) NOT NULL,
    `demo_class_id` VARCHAR(36) NOT NULL,
    `source_campaign` VARCHAR(255) NULL DEFAULT NULL,
    `registration_status` ENUM('submitted', 'confirmed', 'failed_delivery', 'cancelled') NOT NULL DEFAULT 'submitted',
    `confirmation_message` TEXT NULL DEFAULT NULL,
    `submitted_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `duplicate_key` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_duplicate_key` (`duplicate_key`),
    INDEX `idx_registrant` (`registrant_id`),
    INDEX `idx_demo_class` (`demo_class_id`),
    CONSTRAINT `fk_registrations_registrant` FOREIGN KEY (`registrant_id`) REFERENCES `registrants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_registrations_demo_class` FOREIGN KEY (`demo_class_id`) REFERENCES `demo_classes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── WhatsApp Consent Records ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `whatsapp_consent_records` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `registration_id` VARCHAR(36) NOT NULL,
    `action` ENUM('consent', 'withdrawal', 'update') NOT NULL,
    `consent_state` TINYINT(1) NOT NULL,
    `reason` TEXT NULL DEFAULT NULL,
    `recorded_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_registration` (`registration_id`),
    CONSTRAINT `fk_consent_registration` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Manual Follow-Up Records ─────────────────────────────────
CREATE TABLE IF NOT EXISTS `manual_follow_up_records` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `registration_id` VARCHAR(36) NOT NULL,
    `follow_up_type` ENUM('announcement_sent', 'group_invitation_attempted', 'teams_link_shared', 'reminder_sent', 'opt_out_recorded') NOT NULL,
    `outcome` ENUM('pending', 'attempted', 'completed', 'blocked') NOT NULL DEFAULT 'pending',
    `notes` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_registration` (`registration_id`),
    CONSTRAINT `fk_followup_registration` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Campaign Sources ─────────────────────────────────────────
CREATE TABLE IF NOT EXISTS `campaign_sources` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `registration_id` VARCHAR(36) NOT NULL,
    `source_name` VARCHAR(255) NULL DEFAULT NULL,
    `utm_campaign` VARCHAR(255) NULL DEFAULT NULL,
    `referrer` VARCHAR(500) NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX `idx_registration` (`registration_id`),
    CONSTRAINT `fk_campaign_registration` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── WhatsApp Redirect Records ────────────────────────────────
CREATE TABLE IF NOT EXISTS `whatsapp_redirect_records` (
    `id` VARCHAR(36) NOT NULL PRIMARY KEY,
    `registration_id` VARCHAR(36) NOT NULL,
    `invite_url` VARCHAR(500) NULL DEFAULT NULL,
    `redirect_url` VARCHAR(500) NULL DEFAULT NULL,
    `status` ENUM('pending', 'redirected', 'failed', 'expired') NOT NULL DEFAULT 'pending',
    `error_message` TEXT NULL DEFAULT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX `idx_registration` (`registration_id`),
    CONSTRAINT `fk_redirect_registration` FOREIGN KEY (`registration_id`) REFERENCES `registrations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
