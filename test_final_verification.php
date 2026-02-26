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

echo "=== TEST KALKULASI ESTIMASI UNTUK SEMUA QUEUE ===\n\n";

try {
    // Setup
    $room_query = $pdo->query("
        SELECT r.id, r.room_name, MIN(ru.check_out) as earliest_checkout
        FROM rooms r
        LEFT JOIN room_users ru ON r.id = ru.room_id AND ru.status != 'done' AND ru.check_out > NOW()
        GROUP BY r.id
        HAVING earliest_checkout IS NOT NULL
        LIMIT 1
    ");
    
    $room = $room_query->fetch();
    if (!$room) {
        echo "⚠️  Tidak ada bilik dengan user aktif.\n";
        exit;
    }
    
    $room_id = $room['id'];
    $earliest_checkout = new DateTime($room['earliest_checkout']);
    $expected_q1_kosong = $earliest_checkout->format('H:i');
    
    // Hitung expected untuk semua queue
    $expected = [];
    for ($q = 1; $q <= 3; $q++) {
        $kosong = clone $earliest_checkout;
        $kosong->add(new DateInterval('PT' . (2 * ($q - 1)) . 'H'));
        $kosong_str = $kosong->format('H:i');
        
        $selesai = clone $kosong;
        $selesai->add(new DateInterval('PT2H'));
        $selesai_str = $selesai->format('H:i');
        
        $expected[$q] = ['kosong' => $kosong_str, 'selesai' => $selesai_str];
    }
    
    echo "📋 Setup\n";
    echo "---\n";
    echo "Bilik: {$room['room_name']}\n";
    echo "Earliest checkout user: {$room['earliest_checkout']}\n";
    echo "Expected Queue 1 (kosong): {$expected[1]['kosong']} → {$expected[1]['selesai']}\n\n";
    
    // Tambah 3 waiting list
    echo "📋 Menambah 3 user ke waiting list...\n";
    for ($i = 1; $i <= 3; $i++) {
        $test_user_id = "TESTCALC_" . time() . "_" . $i;
        $result = addToWaitingList($test_user_id, "Test Calc User $i", "999999999", $room_id);
        if ($result['success']) {
            echo "✓ User $i ditambahkan\n";
        }
    }
    
    // Ambil dan verifikasi
    echo "\n📋 Hasil Kalkulasi\n";
    echo "---\n";
    $result_query = $pdo->prepare("
        SELECT 
            ROW_NUMBER() OVER (ORDER BY created_at ASC) as queue_no,
            user_name,
            estimasi_kosong,
            estimasi_selesai
        FROM waiting_list
        WHERE room_id = ? AND user_name LIKE 'Test Calc%'
        ORDER BY created_at ASC
    ");
    $result_query->execute([$room_id]);
    $results = $result_query->fetchAll();
    
    $all_correct = true;
    foreach ($results as $result) {
        $q_no = $result['queue_no'];
        $kosong = $result['estimasi_kosong'];
        $selesai = $result['estimasi_selesai'];
        
        $expected_kosong = $expected[$q_no]['kosong'];
        $expected_selesai = $expected[$q_no]['selesai'];
        
        $match = ($kosong === $expected_kosong && $selesai === $expected_selesai) ? "✓" : "✗";
        
        echo "Queue $q_no: $kosong → $selesai  (Expected: $expected_kosong → $expected_selesai)  $match\n";
        
        if ($kosong !== $expected_kosong || $selesai !== $expected_selesai) {
            $all_correct = false;
        }
    }
    
    echo "\n📋 VERIFIKASI\n";
    echo "---\n";
    if ($all_correct) {
        echo "✓✓ SEMUA KALKULASI BENAR!\n";
        echo "   Queue 1: Ambil dari check_out user pertama\n";
        echo "   Queue 2: +2 jam dari Queue 1\n";
        echo "   Queue 3: +2 jam dari Queue 2\n";
        echo "   dst...\n";
    } else {
        echo "⚠️  Ada kalkulasi yang salah\n";
    }
    
    // Cleanup
    echo "\n📋 Cleanup\n";
    echo "---\n";
    $cleanup = $pdo->prepare("DELETE FROM waiting_list WHERE room_id = ? AND user_name LIKE 'Test Calc%'");
    $cleanup->execute([$room_id]);
    echo "✓ Test data dihapus\n";
    
    echo "\n=== TEST SELESAI ===\n";
    
} catch(Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
?>
