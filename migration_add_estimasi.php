<?php
require_once 'db.php';

try {
    // Check if columns exist
    $result = $pdo->query("SHOW COLUMNS FROM waiting_list");
    $columns = [];
    while ($row = $result->fetch()) {
        $columns[] = $row['Field'];
    }
    
    if (!in_array('estimasi_kosong', $columns)) {
        $pdo->exec("ALTER TABLE waiting_list ADD COLUMN estimasi_kosong VARCHAR(5) NULL COMMENT 'Format: HH:mm'");
        echo "✓ Kolom estimasi_kosong ditambahkan\n";
    } else {
        echo "✓ Kolom estimasi_kosong sudah ada\n";
    }
    
    if (!in_array('estimasi_selesai', $columns)) {
        $pdo->exec("ALTER TABLE waiting_list ADD COLUMN estimasi_selesai VARCHAR(5) NULL COMMENT 'Format: HH:mm'");
        echo "✓ Kolom estimasi_selesai ditambahkan\n";
    } else {
        echo "✓ Kolom estimasi_selesai sudah ada\n";
    }
    
    echo "\nMigration selesai!\n";
    
} catch(Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
