CREATE TABLE IF NOT EXISTS menu_sources (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(64) NOT NULL UNIQUE,
    source_name VARCHAR(150) NOT NULL,
    source_type ENUM('gviz', 'csv_appscript') NOT NULL,
    source_url VARCHAR(1000) NOT NULL,
    source_sheet_id VARCHAR(128) NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_menu_sources_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_import_runs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_source_id INT UNSIGNED NOT NULL,
    started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at DATETIME NULL,
    status ENUM('running', 'success', 'failed') NOT NULL DEFAULT 'running',
    imported_item_count INT UNSIGNED NOT NULL DEFAULT 0,
    imported_variant_count INT UNSIGNED NOT NULL DEFAULT 0,
    source_payload_hash VARCHAR(64) NULL,
    source_payload_size INT UNSIGNED NOT NULL DEFAULT 0,
    summary_json LONGTEXT NULL,
    error_message VARCHAR(500) NULL,
    INDEX idx_menu_import_runs_source_id (menu_source_id),
    INDEX idx_menu_import_runs_started_at (started_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_items (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_source_id INT UNSIGNED NOT NULL,
    category_name VARCHAR(190) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    item_description TEXT NULL,
    image_url VARCHAR(1000) NULL,
    serving_unit VARCHAR(100) NULL,
    chef_special TINYINT(1) NOT NULL DEFAULT 0,
    spice_level VARCHAR(100) NULL,
    jain_price VARCHAR(100) NULL,
    is_veg TINYINT(1) NOT NULL DEFAULT 1,
    row_diet ENUM('veg', 'nonveg') NOT NULL DEFAULT 'veg',
    desc_non_veg TINYINT(1) NOT NULL DEFAULT 0,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    row_index INT UNSIGNED NOT NULL DEFAULT 0,
    raw_row_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_menu_items_source_id (menu_source_id),
    INDEX idx_menu_items_category (category_name),
    INDEX idx_menu_items_sort (menu_source_id, row_index)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_item_variants (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_item_id BIGINT UNSIGNED NOT NULL,
    variant_group ENUM('protein', 'serving', 'dynamic', 'price_unit') NOT NULL,
    variant_key VARCHAR(150) NOT NULL,
    variant_value VARCHAR(150) NULL,
    variant_order INT UNSIGNED NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_menu_item_variants_item_id (menu_item_id),
    INDEX idx_menu_item_variants_group_key (variant_group, variant_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO menu_sources (source_key, source_name, source_type, source_url, source_sheet_id)
VALUES
    (
        'awg_main',
        'Asian Wok Main Menu',
        'gviz',
        'https://docs.google.com/spreadsheets/d/19hUSc2ny1NGd73WDTQfosdS3O7xhwiQbdGbiDgKSQlA/gviz/tq?tqx=out:json;reqId:1&tq=select%20*&headers=1',
        '19hUSc2ny1NGd73WDTQfosdS3O7xhwiQbdGbiDgKSQlA'
    ),
    (
        'namastemenu',
        'Namaste Menu',
        'gviz',
        'https://docs.google.com/spreadsheets/d/1BbxQ-HN-QsknQAXGp75IpaGqfnD6b1acLPnMUdi5hAg/gviz/tq?tqx=out:json;reqId:1&tq=select%20*&headers=1',
        '1BbxQ-HN-QsknQAXGp75IpaGqfnD6b1acLPnMUdi5hAg'
    ),
    (
        'cocktail',
        'Cocktail Menu',
        'csv_appscript',
        'https://script.google.com/macros/s/AKfycbxKdQvD58ks4-lowwGtmNeVyODOpQbFVFi8_Xgd6r6fYV6N40sA5gDwzt0C15MNBfQ23A/exec',
        NULL
    )
ON DUPLICATE KEY UPDATE
    source_name = VALUES(source_name),
    source_type = VALUES(source_type),
    source_url = VALUES(source_url),
    source_sheet_id = VALUES(source_sheet_id),
    is_active = 1;