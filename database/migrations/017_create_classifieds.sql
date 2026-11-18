CREATE TABLE IF NOT EXISTS property_classifieds (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    operation ENUM('sale', 'purchase') NOT NULL,
    title VARCHAR(160) NOT NULL,
    property_type ENUM('house', 'apartment', 'commercial', 'other') NOT NULL,
    location VARCHAR(120) NOT NULL,
    description TEXT NOT NULL,
    price DECIMAL(14,2) NULL,
    status ENUM('published', 'closed') NOT NULL DEFAULT 'published',
    contact_name VARCHAR(100) NULL,
    contact_email VARCHAR(254) NULL,
    contact_phone VARCHAR(30) NULL,
    contact_authorized TINYINT(1) NOT NULL DEFAULT 0,
    contact_authorized_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_property_classifieds_status_created (status, created_at),
    KEY idx_property_classifieds_user_created (user_id, created_at),
    CONSTRAINT fk_property_classifieds_user
        FOREIGN KEY (user_id) REFERENCES admin_users (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS property_classified_images (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    listing_id BIGINT UNSIGNED NOT NULL,
    storage_name CHAR(40) NOT NULL,
    mime_type VARCHAR(32) NOT NULL,
    byte_size INT UNSIGNED NOT NULL,
    width SMALLINT UNSIGNED NOT NULL,
    height SMALLINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_property_classified_images_storage_name (storage_name),
    KEY idx_property_classified_images_listing (listing_id, id),
    CONSTRAINT fk_property_classified_images_listing
        FOREIGN KEY (listing_id) REFERENCES property_classifieds (id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
