<?php
require_once 'db.php';

echo "=== TEST SISTEM ESTIMASI AUTO-CAPTURE CHECK_OUT ===\n\n";

try {
    echo "📋 Setup Test Data\n";
    echo "---\n";
    
    // 1. Cari bilik yang ada user
    $room_users_query = $pdo->query("
        SELECT r.id, r.room_name, COUNT(ru.id) as user_count, MIN(ru.check_out) as earliest_checkout
        FROM rooms r
        LEFT JOIN room_users ru ON r.id = ru.room_id AND ru.status != 'done' AND ru.check_out > NOW()
        GROUP BY r.id
        HAVING user_count > 0
        LIMIT 1
    ");
    
    $room_with_users = $room_users_query->fetch();
    
    if (!$room_with_users) {
        echo "⚠️  Tidak ada bilik dengan user aktif. Tambahkan user terlebih dahulu.\n";
        echo "(Silakan akses aplikasi, tambah user ke bilik, kemudian jalankan test ini lagi.)\n";
        exit;
    }
    
    $room_id = $room_with_users['id'];
    $room_name = $room_with_users['room_name'];
    $earliest_checkout = $room_with_users['earliest_checkout'];
    
    echo "✓ Bilik: $room_name\n";
    echo "✓ Jam selesai user pertama (check_out): $earliest_checkout\n";
    
    // Extract time (HH:mm)
    $checkout_datetime = new DateTime($earliest_checkout);
    $expected_kosong = $checkout_datetime->format('H:i');
    echo "  → Expected Estimasi Kosong: $expected_kosong\n";
    
    // Hitung expected estimasi selesai (+2 jam)
    $selesai_datetime = clone $checkout_datetime;
    $selesai_datetime->add(new DateInterval('PT2H'));
    $expected_selesai = $selesai_datetime->format('H:i');
    echo "  → Expected Estimasi Selesai: $expected_selesai\n\n";
    
    // 2. Cek data existing waiting list di bilik ini
    echo "📋 Cek Waiting List Existing\n";
    echo "---\n";
    $waiting_query = $pdo->prepare("
        SELECT id, user_name, estimasi_kosong, estimasi_selesai
        FROM waiting_list
        WHERE room_id = ? AND status = 'waiting'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $waiting_query->execute([$room_id]);
    $existing_waiting = $waiting_query->fetch();
    
    if ($existing_waiting) {
        echo "✓ Ada waiting list existing:\n";
        echo "  - Name: {$existing_waiting['user_name']}\n";
        echo "  - Estimasi Kosong: {$existing_waiting['estimasi_kosong']}\n";
        echo "  - Estimasi Selesai: {$existing_waiting['estimasi_selesai']}\n\n";
        
        if ($existing_waiting['estimasi_kosong'] == $expected_kosong && 
            $existing_waiting['estimasi_selesai'] == $expected_selesai) {
            echo "✓✓ VALUES MATCH! ✓✓\n\n";
        } else {
            echo "⚠️  Values tidak match\n";
            echo "   Expected: $expected_kosong → $expected_selesai\n";
            echo "   Actual: {$existing_waiting['estimasi_kosong']} → {$existing_waiting['estimasi_selesai']}\n\n";
        }
    } else {
        echo "ℹ️  Tidak ada waiting list 'waiting' di bilik ini.\n\n";
    }
    
    // 3. Simulasi test: buat waiting list baru
    echo "📋 Simulasi Test: Tambah Waiting List Baru\n";
    echo "---\n";
    
    $test_user_id = "TEST_" . time();
    $test_user_name = "Test User " . date('H:i:s');
    $test_phone = "999999999";
    
    echo "Input:\n";
    echo "  - User ID: $test_user_id\n";
    echo "  - User Name: $test_user_name\n";
    echo "  - Room ID: $room_id\n\n";
    
    // Call fungsi addToWaitingList
    require_once 'index.php'; // Include functions
    $result = addToWaitingList($test_user_id, $test_user_name, $test_phone, $room_id);
    
    if ($result['success']) {
        echo "✓ Berhasil ditambah ke waiting list (ID: {$result['waiting_id']})\n\n";
        
        // Ambil data yang baru ditambah
        $verify_query = $pdo->prepare("
            SELECT estimasi_kosong, estimasi_selesai
            FROM waiting_list
            WHERE id = ?
        ");
        $verify_query->execute([$result['waiting_id']]);
        $verify = $verify_query->fetch();
        
        echo "Hasil simpan ke database:\n";
        echo "  - Estimasi Kosong: {$verify['estimasi_kosong']}\n";
        echo "  - Estimasi Selesai: {$verify['estimasi_selesai']}\n\n";
        
        if ($verify['estimasi_kosong'] == $expected_kosong && 
            $verify['estimasi_selesai'] == $expected_selesai) {
            echo "✓✓ SISTEM BERJALAN DENGAN BENAR! ✓✓\n\n";
        } else {
            echo "⚠️  Ada perbedaan hasil\n";
            echo "   Expected: $expected_kosong → $expected_selesai\n";
            echo "   Actual: {$verify['estimasi_kosong']} → {$verify['estimasi_selesai']}\n\n";
        }
        
        // Cleanup: hapus test data
        $delete_query = $pdo->prepare("DELETE FROM waiting_list WHERE id = ?");
        $delete_query->execute([$result['waiting_id']]);
        echo "✓ Test data dibersihkan\n";
    } else {
        echo "✗ Gagal: {$result['message']}\n";
    }
    
    echo "\n=== TEST SELESAI ===\n";
    
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
