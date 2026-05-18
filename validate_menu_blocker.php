<?php
require_once __DIR__ . '/bootstrap/app.php';
require_once __DIR__ . '/app/Config/Database.php';

use AWG\Config\Database;

try {
    $pdo = Database::connection();
    
    // Check if table exists
    $result = $pdo->query('
        SELECT TABLE_NAME 
        FROM INFORMATION_SCHEMA.TABLES 
        WHERE TABLE_NAME = "menu_blocker_spins" 
        AND TABLE_SCHEMA = DATABASE()
    ');
    
    if ($result && $result->fetch()) {
        echo "✓ Database table menu_blocker_spins exists\n";
        
        // Get table info
        $cols = $pdo->query('DESC menu_blocker_spins');
        $colCount = $cols->rowCount();
        echo "✓ Table has $colCount columns\n";
        
        // Check indexes
        $indexes = $pdo->query('SHOW INDEXES FROM menu_blocker_spins');
        $indexCount = count($indexes->fetchAll());
        echo "✓ Table has $indexCount indexes\n";
        
        echo "\n✓ DATABASE VALIDATION PASSED\n";
    } else {
        echo "ERROR: menu_blocker_spins table not found\n";
        exit(1);
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    exit(1);
}
