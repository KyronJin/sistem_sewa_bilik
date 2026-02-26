<?php
require_once 'db.php';
date_default_timezone_set('Asia/Jakarta');

// Function to get earliest check_out per room
function getEarliestCheckOut()
{
    global $pdo;
    $stmt = $pdo->query("
        SELECT room_id, MIN(check_out) as earliest
        FROM room_users 
        WHERE status != 'done' AND check_out > NOW()
        GROUP BY room_id
    ");
    $earliest = [];
    while ($row = $stmt->fetch()) {
        $earliest[$row['room_id']] = $row['earliest'];
    }
    return $earliest;
}

// Function to add to waiting list (simplified)
function addToWaitingList($user_id, $user_name, $phone, $room_id)
{
    global $pdo;

    // CEK: Apakah ID/NIK sedang aktif di bilik manapun
    $active_check = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE user_id = ? AND status != 'done'");
    $active_check->execute([$user_id]);
    if ($active_check->fetchColumn() > 0) {
        return [
            'success' => false,
            'message' => 'ID/NIK sudah digunakan dan masih aktif di bilik lain! Tidak bisa masuk waiting list.',
            'duplicate_id' => true
        ];
    }

    // Check room capacity
    $capacity_check = $pdo->prepare("
        SELECT r.max_capacity, COUNT(ru.id) as current_count 
        FROM rooms r 
        LEFT JOIN room_users ru ON r.id = ru.room_id AND ru.status != 'done'
        WHERE r.id = ? 
        GROUP BY r.id
    ");
    $capacity_check->execute([$room_id]);
    $capacity = $capacity_check->fetch();

    $is_room_available = $capacity && $capacity['current_count'] < $capacity['max_capacity'];

    if ($is_room_available) {
        $user_check = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE room_id = ? AND status != 'done' AND check_out > NOW()");
        $user_check->execute([$room_id]);
        $still_active = $user_check->fetchColumn();
        if ($still_active > 0) {
            $is_room_available = false;
        }
    }

    // Ambil check_out dari user pertama yang aktif di bilik (untuk estimasi)
    $checkout_query = $pdo->prepare("
        SELECT MIN(check_out) as earliest_checkout
        FROM room_users
        WHERE room_id = ? AND status != 'done' AND check_out > NOW()
    ");
    $checkout_query->execute([$room_id]);
    $checkout_result = $checkout_query->fetch();
    $earliest_checkout = $checkout_result['earliest_checkout'] ?? null;
    
    // Hitung estimasi_kosong (HH:mm dari check_out user pertama)
    $estimasi_kosong = null;
    $estimasi_selesai = null;
    
    if ($earliest_checkout) {
        $checkout_time = new DateTime($earliest_checkout);
        $estimasi_kosong = $checkout_time->format('H:i');
        
        // Hitung estimasi selesai (+2 jam)
        $selesai_time = clone $checkout_time;
        $selesai_time->add(new DateInterval('PT2H'));
        $estimasi_selesai = $selesai_time->format('H:i');
    }

    $stmt = $pdo->prepare("INSERT INTO waiting_list (user_id, user_name, phone, room_id, status, estimasi_kosong, estimasi_selesai) VALUES (?, ?, ?, ?, ?, ?, ?)");
    $status = $is_room_available ? 'done' : 'waiting';
    $success = $stmt->execute([$user_id, $user_name, $phone, $room_id, $status, $estimasi_kosong, $estimasi_selesai]);
    $waiting_id = $pdo->lastInsertId();

    return [
        'success' => $success,
        'message' => $success ? 'Added to waiting list' : 'Failed to add to waiting list',
        'is_room_available' => $is_room_available,
        'waiting_id' => $waiting_id,
        'estimasi_kosong' => $estimasi_kosong,
        'estimasi_selesai' => $estimasi_selesai
    ];
}

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
    
    // 2. Simulasi test: buat waiting list baru
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
    $result = addToWaitingList($test_user_id, $test_user_name, $test_phone, $room_id);
    
    if ($result['success']) {
        echo "✓ Berhasil ditambah ke waiting list (ID: {$result['waiting_id']})\n\n";
        
        echo "Hasil dari fungsi:\n";
        echo "  - Estimasi Kosong: {$result['estimasi_kosong']}\n";
        echo "  - Estimasi Selesai: {$result['estimasi_selesai']}\n\n";
        
        // Ambil data yang baru ditambah dari database
        $verify_query = $pdo->prepare("
            SELECT estimasi_kosong, estimasi_selesai
            FROM waiting_list
            WHERE id = ?
        ");
        $verify_query->execute([$result['waiting_id']]);
        $verify = $verify_query->fetch();
        
        echo "Hasil dari database:\n";
        echo "  - Estimasi Kosong: {$verify['estimasi_kosong']}\n";
        echo "  - Estimasi Selesai: {$verify['estimasi_selesai']}\n\n";
        
        if (($result['estimasi_kosong'] == $expected_kosong && $result['estimasi_selesai'] == $expected_selesai) &&
            ($verify['estimasi_kosong'] == $expected_kosong && $verify['estimasi_selesai'] == $expected_selesai)) {
            echo "✓✓ SISTEM BERJALAN DENGAN BENAR! ✓✓\n";
            echo "Nilai hasil = Nilai expected\n";
            echo "→ $expected_kosong menjadi $expected_selesai\n\n";
        } else {
            echo "⚠️  Ada perbedaan hasil\n";
            echo "Expected: $expected_kosong → $expected_selesai\n";
            echo "Hasil: {$result['estimasi_kosong']} → {$result['estimasi_selesai']}\n\n";
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
