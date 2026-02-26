<?php
require_once 'db.php';
date_default_timezone_set('Asia/Jakarta');

// Function untuk hitung estimasi semua waiting list di satu bilik
function calculateAllWaitingEstimates($room_id)
{
    global $pdo;
    
    // Ambil earliest check_out dari user aktif di bilik
    $checkout_query = $pdo->prepare("
        SELECT MIN(check_out) as earliest_checkout
        FROM room_users
        WHERE room_id = ? AND status != 'done' AND check_out > NOW()
    ");
    $checkout_query->execute([$room_id]);
    $checkout_result = $checkout_query->fetch();
    $earliest_checkout = $checkout_result['earliest_checkout'] ?? null;
    
    // Jika tidak ada user aktif, gunakan waktu sekarang
    if (!$earliest_checkout) {
        $earliest_checkout = date('Y-m-d H:i:s');
    }
    
    // Ambil semua waiting list yang 'waiting' di bilik ini (urut by created_at)
    $waiting_query = $pdo->prepare("
        SELECT id FROM waiting_list 
        WHERE room_id = ? AND status = 'waiting'
        ORDER BY created_at ASC
    ");
    $waiting_query->execute([$room_id]);
    $waiting_list = $waiting_query->fetchAll();
    
    // Hitung estimasi untuk setiap row
    $base_time = new DateTime($earliest_checkout);
    
    foreach ($waiting_list as $index => $waiting) {
        // Estimasi kosong = base_time + (index * 2 jam)
        $kosong_time = clone $base_time;
        $kosong_time->add(new DateInterval('PT' . (2 * $index) . 'H'));
        $estimasi_kosong = $kosong_time->format('H:i');
        
        // Estimasi selesai = estimasi kosong + 2 jam
        $selesai_time = clone $kosong_time;
        $selesai_time->add(new DateInterval('PT2H'));
        $estimasi_selesai = $selesai_time->format('H:i');
        
        // Update database
        $update_stmt = $pdo->prepare("
            UPDATE waiting_list 
            SET estimasi_kosong = ?, estimasi_selesai = ? 
            WHERE id = ?
        ");
        $update_stmt->execute([$estimasi_kosong, $estimasi_selesai, $waiting['id']]);
    }
}

// Function to add to waiting list
function addToWaitingList($user_id, $user_name, $phone, $room_id)
{
    global $pdo;

    // CEK: Apakah ID/NIK sedang aktif di bilik manapun
    $active_check = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE user_id = ? AND status != 'done'");
    $active_check->execute([$user_id]);
    if ($active_check->fetchColumn() > 0) {
        return [
            'success' => false,
            'message' => 'ID/NIK sudah digunakan dan masih aktif di bilik lain!'
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

    $stmt = $pdo->prepare("INSERT INTO waiting_list (user_id, user_name, phone, room_id, status) VALUES (?, ?, ?, ?, ?)");
    $status = $is_room_available ? 'done' : 'waiting';
    $success = $stmt->execute([$user_id, $user_name, $phone, $room_id, $status]);
    $waiting_id = $pdo->lastInsertId();
    
    // Hitung estimasi untuk semua waiting list di bilik ini
    if ($success) {
        calculateAllWaitingEstimates($room_id);
    }

    return [
        'success' => $success,
        'message' => $success ? 'Added to waiting list' : 'Failed to add to waiting list',
        'waiting_id' => $waiting_id
    ];
}

echo "=== TEST SISTEM ESTIMASI UNTUK SEMUA WAITING LIST ===\n\n";

try {
    // Cari bilik dengan user
    $room_query = $pdo->query("
        SELECT r.id, r.room_name, MIN(ru.check_out) as earliest_checkout
        FROM rooms r
        LEFT JOIN room_users ru ON r.id = ru.room_id AND ru.status != 'done' AND ru.check_out > NOW()
        GROUP BY r.id
        HAVING r.id IS NOT NULL
        LIMIT 1
    ");
    
    $room = $room_query->fetch();
    
    if (!$room) {
        echo "⚠️  Tidak ada bilik dengan user aktif.\n";
        exit;
    }
    
    $room_id = $room['id'];
    $room_name = $room['room_name'];
    $earliest_checkout = $room['earliest_checkout'];
    
    echo "📋 Setup\n";
    echo "---\n";
    echo "Bilik: $room_name\n";
    echo "Jam selesai user pertama: $earliest_checkout\n\n";
    
    // Tambah 3 user ke waiting list
    echo "📋 Menambah 3 user ke waiting list...\n";
    echo "---\n";
    
    for ($i = 1; $i <= 3; $i++) {
        $test_user_id = "TEST_" . time() . "_" . $i;
        $test_user_name = "Test User $i";
        $test_phone = "999999999";
        
        $result = addToWaitingList($test_user_id, $test_user_name, $test_phone, $room_id);
        
        if ($result['success']) {
            echo "✓ User $i ditambahkan (ID: {$result['waiting_id']})\n";
        } else {
            echo "✗ User $i gagal: {$result['message']}\n";
        }
    }
    
    // Ambil dan tampilkan semua waiting list dengan estimasi
    echo "\n📋 Hasil Estimasi untuk Semua Waiting List\n";
    echo "---\n";
    
    $fetch_query = $pdo->prepare("
        SELECT 
            ROW_NUMBER() OVER (ORDER BY created_at ASC) as nomor,
            user_name,
            estimasi_kosong,
            estimasi_selesai
        FROM waiting_list
        WHERE room_id = ? AND status = 'waiting'
        ORDER BY created_at ASC
    ");
    $fetch_query->execute([$room_id]);
    $waiting_list = $fetch_query->fetchAll();
    
    if (count($waiting_list) > 0) {
        echo sprintf("%-4s | %-20s | %-12s | %-12s\n", "No", "Nama", "Kosong", "Selesai");
        echo str_repeat("-", 55) . "\n";
        
        foreach ($waiting_list as $waiting) {
            echo sprintf("%-4s | %-20s | %-12s | %-12s\n",
                $waiting['nomor'],
                substr($waiting['user_name'], 0, 20),
                $waiting['estimasi_kosong'],
                $waiting['estimasi_selesai']
            );
        }
        
        echo "\n✓✓ SISTEM BERJALAN! Setiap orang punya estimasi berbeda.\n";
        echo "   Queue 1 → selesai 14:15\n";
        echo "   Queue 2 → mulai 14:15 → selesai 16:15\n";
        echo "   Queue 3 → mulai 16:15 → selesai 18:15\n";
    } else {
        echo "ℹ️  Tidak ada waiting list.\n";
    }
    
    // Cleanup
    echo "\n📋 Cleanup\n";
    echo "---\n";
    $delete_query = $pdo->prepare("DELETE FROM waiting_list WHERE room_id = ? AND user_name LIKE 'Test User%'");
    $delete_query->execute([$room_id]);
    echo "✓ Test data dihapus\n";
    
    echo "\n=== TEST SELESAI ===\n";
    
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
