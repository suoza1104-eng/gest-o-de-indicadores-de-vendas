CREATE TABLE IF NOT EXISTS admin_users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email VARCHAR(190) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO admin_users (email, password_hash, is_active, created_at, updated_at)
VALUES (
    'admin@professoremersonleite.site',
    '$2y$12$6/xX10W3jw6yQ21ovAFNdeD/EBbyWmEROm1HQXoU8C96rfaYV1idO',
    1,
    NOW(),
    NOW()
)
ON DUPLICATE KEY UPDATE
    password_hash = VALUES(password_hash),
    is_active = VALUES(is_active),
    updated_at = NOW();

CREATE TABLE IF NOT EXISTS hotmart_sales_live (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    webhook_event VARCHAR(80) DEFAULT NULL,
    webhook_event_id VARCHAR(120) DEFAULT NULL,
    transaction_code VARCHAR(80) NOT NULL,
    status VARCHAR(60) DEFAULT NULL,
    transaction_date DATETIME NOT NULL,
    payment_confirmed_at DATETIME NULL,
    refund_or_chargeback_at DATETIME NULL,
    product_code VARCHAR(80) DEFAULT NULL,
    product_name VARCHAR(255) DEFAULT NULL,
    price_code VARCHAR(80) DEFAULT NULL,
    price_name VARCHAR(255) DEFAULT NULL,
    currency VARCHAR(10) DEFAULT 'BRL',
    gross_revenue DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    net_revenue DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    producer_net DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    refunded_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    chargeback_value DECIMAL(15,2) NOT NULL DEFAULT 0.00,
    buyer_name VARCHAR(255) DEFAULT NULL,
    buyer_email VARCHAR(255) DEFAULT NULL,
    buyer_phone_raw VARCHAR(80) DEFAULT NULL,
    buyer_phone_norm VARCHAR(20) DEFAULT NULL,
    matched_user_id BIGINT UNSIGNED DEFAULT NULL,
    match_method VARCHAR(30) DEFAULT NULL,
    utm_source VARCHAR(255) DEFAULT NULL,
    utm_medium VARCHAR(255) DEFAULT NULL,
    utm_campaign VARCHAR(255) DEFAULT NULL,
    utm_term VARCHAR(255) DEFAULT NULL,
    utm_content VARCHAR(255) DEFAULT NULL,
    raw_payload_json LONGTEXT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hotmart_sales_live_transaction (transaction_code),
    KEY idx_transaction_date (transaction_date),
    KEY idx_payment_confirmed_at (payment_confirmed_at),
    KEY idx_status (status),
    KEY idx_buyer_email (buyer_email),
    KEY idx_buyer_phone_norm (buyer_phone_norm),
    KEY idx_matched_user_id (matched_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS hotmart_webhook_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    event_id VARCHAR(120) NOT NULL,
    event_name VARCHAR(120) DEFAULT NULL,
    transaction_code VARCHAR(80) DEFAULT NULL,
    raw_payload_json LONGTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'received',
    message TEXT NULL,
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_hotmart_webhook_event_id (event_id),
    KEY idx_transaction_code (transaction_code),
    KEY idx_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS manual_sale_attributions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    transaction_code VARCHAR(80) NOT NULL,
    attribution_model ENUM('first_touch','last_touch') NOT NULL DEFAULT 'last_touch',
    campaign_group VARCHAR(255) DEFAULT NULL,
    campaign_group_norm VARCHAR(255) DEFAULT NULL,
    campaign_name VARCHAR(255) DEFAULT NULL,
    campaign_name_norm VARCHAR(255) DEFAULT NULL,
    ad_name VARCHAR(255) DEFAULT NULL,
    ad_name_norm VARCHAR(255) DEFAULT NULL,
    source_user_id BIGINT UNSIGNED DEFAULT NULL,
    lead_utm_source VARCHAR(255) DEFAULT NULL,
    lead_utm_medium VARCHAR(255) DEFAULT NULL,
    lead_utm_campaign VARCHAR(255) DEFAULT NULL,
    lead_utm_term VARCHAR(255) DEFAULT NULL,
    lead_utm_content VARCHAR(255) DEFAULT NULL,
    assigned_by VARCHAR(255) DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_tx_model (transaction_code, attribution_model)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

