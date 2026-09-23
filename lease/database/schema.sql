CREATE DATABASE IF NOT EXISTS lease_import_manager
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE lease_import_manager;

CREATE TABLE IF NOT EXISTS lease_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,

    so_number VARCHAR(100) NOT NULL UNIQUE,

    customer_name VARCHAR(255) NULL,
    email VARCHAR(255) NULL,
    phone VARCHAR(100) NULL,
    company VARCHAR(255) NULL,
    lease_partner VARCHAR(255) NULL,

    bike_type ENUM(
        'elektrisch',
        'racefiets',
        'gravelfiets',
        'speedpedelec',
        'elektrische gezinsfiets',
        'niet elektrisch city bike',
        'mountainbike',
        'onbekend'
    ) DEFAULT 'onbekend',

    bike_name VARCHAR(255) NULL,
    frame_number VARCHAR(255) NULL,

    order_status_raw VARCHAR(255) NULL,
    order_status_code VARCHAR(50) NULL,
    order_status_label VARCHAR(255) NULL,

    lease_start_date DATE NULL,
    lease_end_date DATE NULL,

    maintenance_budget DECIMAL(10,2) NULL,
    yearly_maintenance_end_date DATE NULL,

    o2o_budget_grace_start_date DATE NULL,
    o2o_budget_valid_until DATE NULL,

    cyclis_budget_grace_start_date DATE NULL,
    cyclis_budget_valid_until DATE NULL,

    source_file VARCHAR(255) NULL,
    last_imported_at DATETIME NULL,

    archived TINYINT(1) NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,

    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS import_batches (
    id INT AUTO_INCREMENT PRIMARY KEY,
    filename VARCHAR(255) NOT NULL,
    total_rows INT DEFAULT 0,
    imported_rows INT DEFAULT 0,
    updated_rows INT DEFAULT 0,
archived_updates INT DEFAULT 0,
skipped_rows INT DEFAULT 0,
created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS import_errors (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_batch_id INT NULL,
    row_number INT NULL,
    so_number VARCHAR(100) NULL,
    error_message TEXT NULL,
    raw_data JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (import_batch_id) REFERENCES import_batches(id)
);

CREATE TABLE IF NOT EXISTS import_mappings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    mapping_name VARCHAR(255) NOT NULL,
    mapping_json JSON NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_mapping_name (mapping_name)
);

CREATE TABLE IF NOT EXISTS import_created_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    import_batch_id INT NOT NULL,
    lease_order_id INT NOT NULL,
    so_number VARCHAR(100) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (import_batch_id) REFERENCES import_batches(id),
    FOREIGN KEY (lease_order_id) REFERENCES lease_orders(id)
);

CREATE TABLE IF NOT EXISTS customer_logbook (
    id INT AUTO_INCREMENT PRIMARY KEY,
    lease_order_id INT NOT NULL,
    so_number VARCHAR(100) NULL,
    action_type VARCHAR(100) NOT NULL,
    action_label VARCHAR(255) NOT NULL,
    amount DECIMAL(10,2) NULL,
    note TEXT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (lease_order_id) REFERENCES lease_orders(id)
);