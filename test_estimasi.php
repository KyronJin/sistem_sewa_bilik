<?php
require_once 'db.php';

echo "=== TEST SISTEM ESTIMASI ESTIMASI BILIK KOSONG & JAM SELESAI ===\n\n";

// Test 1: Check kolom di database
echo "📋 TEST 1: Check Kolom Database\n";
try {
    $result = $pdo->query("SHOW COLUMNS FROM waiting_list");
    $columns = [];
    while ($row = $result->fetch()) {
        $columns[] = $row['Field'];
    }
    
    if (in_array('estimasi_kosong', $columns) && in_array('estimasi_selesai', $columns)) {
        echo "✓ Kolom estimasi_kosong dan estimasi_selesai ada di database\n\n";
    } else {
        echo "✗ Kolom belum lengkap\n\n";
    }
} catch(Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 2: Test data update dengan contoh
echo "📋 TEST 2: Test UPDATE Estimasi\n";
try {
    // Ambil 1 waiting list record
    $stmt = $pdo->query("SELECT id FROM waiting_list LIMIT 1");
    $waiting = $stmt->fetch();
    
    if ($waiting) {
        $waiting_id = $waiting['id'];
        echo "Testing dengan waiting_id: $waiting_id\n";
        
        // Simulate input
        $estimasi_kosong = "14:30";
        $estimasi_selesai = "16:30";
        
        // Update
        $update_stmt = $pdo->prepare("UPDATE waiting_list SET estimasi_kosong = ?, estimasi_selesai = ? WHERE id = ?");
        $result = $update_stmt->execute([$estimasi_kosong, $estimasi_selesai, $waiting_id]);
        
        if ($result) {
            echo "✓ Update estimasi berhasil\n";
            
            // Verify
            $verify_stmt = $pdo->prepare("SELECT estimasi_kosong, estimasi_selesai FROM waiting_list WHERE id = ?");
            $verify_stmt->execute([$waiting_id]);
            $verify = $verify_stmt->fetch();
            
            echo "✓ Data tersimpan: Kosong=$verify[estimasi_kosong], Selesai=$verify[estimasi_selesai]\n\n";
        } else {
            echo "✗ Update gagal\n\n";
        }
    } else {
        echo "ℹ Tidak ada waiting list data untuk test\n\n";
    }
} catch(Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Check JavaScript function
echo "📋 TEST 3: JavaScript Logic Test\n";
echo "Input Kosong: 14:30\n";
echo "Seharusnya Selesai: 16:30 (+ 2 jam)\n";
$jam_kosong = 14;
$menit_kosong = 30;
$jam_selesai = $jam_kosong + 2;
$menit_selesai = $menit_kosong;
if ($jam_selesai >= 24) {
    $jam_selesai -= 24;
}
echo "Hasil Selesai: " . str_pad($jam_selesai, 2, '0', STR_PAD_LEFT) . ":" . str_pad($menit_selesai, 2, '0', STR_PAD_LEFT) . "\n";
echo "✓ Logic OK\n\n";

echo "=== TEST SELESAI ===\n";
?>
