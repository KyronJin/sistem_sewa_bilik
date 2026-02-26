<?php
require_once 'db.php';
date_default_timezone_set('Asia/Jakarta');

// Include functions
function calculateAllWaitingEstimates($room_id)
{
    global $pdo;
    
    $checkout_query = $pdo->prepare("
        SELECT MIN(check_out) as earliest_checkout
        FROM room_users
        WHERE room_id = ? AND status != 'done' AND check_out > NOW()
    ");
    $checkout_query->execute([$room_id]);
    $checkout_result = $checkout_query->fetch();
    $earliest_checkout = $checkout_result['earliest_checkout'] ?? null;
    
    if (!$earliest_checkout) {
        $earliest_checkout = date('Y-m-d H:i:s');
    }
    
    $waiting_query = $pdo->prepare("
        SELECT id FROM waiting_list 
        WHERE room_id = ? AND status = 'waiting'
        ORDER BY created_at ASC
    ");
    $waiting_query->execute([$room_id]);
    $waiting_list = $waiting_query->fetchAll();
    
    $base_time = new DateTime($earliest_checkout);
    
    foreach ($waiting_list as $index => $waiting) {
        $kosong_time = clone $base_time;
        $kosong_time->add(new DateInterval('PT' . (2 * $index) . 'H'));
        $estimasi_kosong = $kosong_time->format('H:i');
        
        $selesai_time = clone $kosong_time;
        $selesai_time->add(new DateInterval('PT2H'));
        $estimasi_selesai = $selesai_time->format('H:i');
        
        $update_stmt = $pdo->prepare("
            UPDATE waiting_list 
            SET estimasi_kosong = ?, estimasi_selesai = ? 
            WHERE id = ?
        ");
        $update_stmt->execute([$estimasi_kosong, $estimasi_selesai, $waiting['id']]);
    }
}

function addToWaitingList($user_id, $user_name, $phone, $room_id)
{
    global $pdo;

    $active_check = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE user_id = ? AND status != 'done'");
    $active_check->execute([$user_id]);
    if ($active_check->fetchColumn() > 0) {
        return ['success' => false];
    }

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
    
    if ($success) {
        calculateAllWaitingEstimates($room_id);
    }

    return ['success' => $success, 'waiting_id' => $waiting_id];
}

echo "=== TEST RECALCULATE ESTIMASI KETIKA USER SELESAI ===\n\n";

try {
    // Cari bilik
    $room_query = $pdo->query("
        SELECT r.id, r.room_name
        FROM rooms r
        LIMIT 1
    ");
    
    $room = $room_query->fetch();
    if (!$room) {
        echo "⚠️  Tidak ada bilik.\n";
        exit;
    }
    
    $room_id = $room['id'];
    $room_name = $room['room_name'];
    
    echo "📋 Setup\n";
    echo "---\n";
    echo "Bilik: $room_name\n\n";
    
    // Tambah 2 user ke waiting list
    echo "📋 Menambah 2 user ke waiting list...\n";
    for ($i = 1; $i <= 2; $i++) {
        $test_user_id = "TESTDEL_" . time() . "_" . $i;
        $result = addToWaitingList($test_user_id, "Test Del User $i", "999999999", $room_id);
        if ($result['success']) {
            echo "✓ User $i ditambahkan (ID: {$result['waiting_id']})\n";
        }
    }
    
    echo "\n📋 SEBELUM: Estimasi semua user\n";
    echo "---\n";
    $before_query = $pdo->prepare("
        SELECT user_name, estimasi_kosong, estimasi_selesai
        FROM waiting_list
        WHERE room_id = ? AND user_name LIKE 'Test Del%'
        ORDER BY created_at ASC
    ");
    $before_query->execute([$room_id]);
    $before_list = $before_query->fetchAll();
    
    foreach ($before_list as $idx => $record) {
        echo ($idx+1) . ". {$record['user_name']}: {$record['estimasi_kosong']} → {$record['estimasi_selesai']}\n";
    }
    
    // Simulasi: hapus waiting list pertama
    echo "\n📋 ACTION: Menghapus waiting list pertama...\n";
    if (count($before_list) > 0) {
        $first_id = $pdo->query("
            SELECT id FROM waiting_list
            WHERE room_id = $room_id AND user_name LIKE 'Test Del User 1%'
            LIMIT 1
        ")->fetch()['id'];
        
        $delete_stmt = $pdo->prepare("DELETE FROM waiting_list WHERE id = ?");
        $delete_stmt->execute([$first_id]);
        
        // Trigger recalculate
        calculateAllWaitingEstimates($room_id);
        
        echo "✓ Waiting list pertama dihapus dan estimasi recalculated\n";
    }
    
    echo "\n📋 SESUDAH: Estimasi sisa user\n";
    echo "---\n";
    $after_query = $pdo->prepare("
        SELECT user_name, estimasi_kosong, estimasi_selesai
        FROM waiting_list
        WHERE room_id = ? AND user_name LIKE 'Test Del%'
        ORDER BY created_at ASC
    ");
    $after_query->execute([$room_id]);
    $after_list = $after_query->fetchAll();
    
    foreach ($after_list as $idx => $record) {
        echo ($idx+1) . ". {$record['user_name']}: {$record['estimasi_kosong']} → {$record['estimasi_selesai']}\n";
    }
    
    if (count($after_list) > 0) {
        echo "\n✓✓ SISTEM BERJALAN!\n";
        echo "   User ke-2 naik menjadi queue 1 dengan estimasi baru\n";
    }
    
    // Cleanup
    echo "\n📋 Cleanup\n";
    echo "---\n";
    $cleanup = $pdo->prepare("DELETE FROM waiting_list WHERE room_id = ? AND user_name LIKE 'Test Del%'");
    $cleanup->execute([$room_id]);
    echo "✓ Test data dihapus\n";
    
    echo "\n=== TEST SELESAI ===\n";
    
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
