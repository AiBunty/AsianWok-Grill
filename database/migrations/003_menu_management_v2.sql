CREATE TABLE IF NOT EXISTS menu_items_v2 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_type ENUM('menu_a', 'menu_b', 'menu_c') NOT NULL,
    category VARCHAR(190) NOT NULL,
    item_name VARCHAR(255) NOT NULL,
    description TEXT NULL,
    image_url VARCHAR(1000) NULL,
    is_available TINYINT(1) NOT NULL DEFAULT 1,
    is_chef_special TINYINT(1) NOT NULL DEFAULT 0,
    is_veg TINYINT(1) NOT NULL DEFAULT 0,
    is_nonveg TINYINT(1) NOT NULL DEFAULT 0,
    is_jain TINYINT(1) NOT NULL DEFAULT 0,
    is_universal TINYINT(1) NOT NULL DEFAULT 0,
    primary_diet ENUM('veg','nonveg','jain','mixed','universal','bar','') NOT NULL DEFAULT '',
    pricing_mode ENUM('standard','custom_variants') NOT NULL DEFAULT 'standard',
    price_veg DECIMAL(10,2) NULL,
    price_jain DECIMAL(10,2) NULL,
    price_chicken DECIMAL(10,2) NULL,
    price_mutton DECIMAL(10,2) NULL,
    price_basa DECIMAL(10,2) NULL,
    price_prawns DECIMAL(10,2) NULL,
    price_surmai DECIMAL(10,2) NULL,
    price_pomfret DECIMAL(10,2) NULL,
    price_crab DECIMAL(10,2) NULL,
    price_egg DECIMAL(10,2) NULL,
    price_half DECIMAL(10,2) NULL,
    price_full DECIMAL(10,2) NULL,
    price_plain DECIMAL(10,2) NULL,
    price_butter DECIMAL(10,2) NULL,
    price_medium DECIMAL(10,2) NULL,
    price_large DECIMAL(10,2) NULL,
    price_direct DECIMAL(10,2) NULL,
    category_sort_order INT NOT NULL DEFAULT 0,
    item_sort_order INT NOT NULL DEFAULT 0,
    source_row INT NULL,
    manually_edited TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_miv2_menu_type (menu_type),
    INDEX idx_miv2_category (menu_type, category),
    INDEX idx_miv2_sort (menu_type, category_sort_order, item_sort_order, id),
    INDEX idx_miv2_visibility (menu_type, is_available),
    INDEX idx_miv2_source_row (menu_type, source_row)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_item_variants_v2 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    item_id BIGINT UNSIGNED NOT NULL,
    variant_label VARCHAR(190) NOT NULL,
    price DECIMAL(10,2) NULL,
    variant_sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_miv2_item FOREIGN KEY (item_id) REFERENCES menu_items_v2(id) ON DELETE CASCADE,
    INDEX idx_miv2_item_sort (item_id, variant_sort_order, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_categories_v2 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_type ENUM('menu_a', 'menu_b', 'menu_c') NOT NULL,
    name VARCHAR(190) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    aliases_json LONGTEXT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_menu_categories_v2_type_name (menu_type, name),
    INDEX idx_menu_categories_v2_sort (menu_type, sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS menu_import_snapshots_v2 (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    menu_type ENUM('menu_a', 'menu_b', 'menu_c') NOT NULL,
    note VARCHAR(255) NULL,
    snapshot_json LONGTEXT NOT NULL,
    created_by VARCHAR(120) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_menu_import_snapshots_v2_type_created (menu_type, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
