<?php
require_once 'db.php';
date_default_timezone_set('Asia/Jakarta');


// Function to add a new room
function addRoom($room_name, $max_capacity)
{
    global $pdo;
    $stmt = $pdo->prepare("INSERT INTO rooms (room_name, max_capacity) VALUES (?, ?)");
    return $stmt->execute([$room_name, $max_capacity]);
}

// Function to delete a room
function deleteRoom($room_id)
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM rooms WHERE id = ?");
    return $stmt->execute([$room_id]);
}

// Function to add a user to a room
function addUserToRoom($room_id, $user_id, $user_name, $from_waiting = false)
{
    global $pdo;

    // Cek apakah ID/NIK sedang aktif di bilik manapun
    $active_check = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE user_id = ? AND status != 'done'");
    $active_check->execute([$user_id]);
    if ($active_check->fetchColumn() > 0) {
        return 'duplicate_id'; // Sudah aktif, tidak boleh dipakai lagi
    }

    // Skip pengecekan waiting list jika berasal dari waiting list (karena kita sedang memindahkan)
    if (!$from_waiting) {
        // Check if there is a waiting list for this room
        $waiting_check = $pdo->prepare("SELECT COUNT(*) FROM waiting_list WHERE status = 'waiting' AND room_id = ?");
        $waiting_check->execute([$room_id]);
        if ($waiting_check->fetchColumn() > 0) {
            return false; // Cannot add due to existing waiting list
        }
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

    if ($capacity && $capacity['current_count'] >= $capacity['max_capacity']) {
        return false; // Room is full
    }

    $check_in = new DateTime();
    $check_out = clone $check_in;
    $check_out->add(new DateInterval('PT2H')); // Default 2 jam

    $stmt = $pdo->prepare("INSERT INTO room_users (room_id, user_id, user_name, check_in, check_out) VALUES (?, ?, ?, ?, ?)");
    return $stmt->execute([$room_id, $user_id, $user_name, $check_in->format('Y-m-d H:i:s'), $check_out->format('Y-m-d H:i:s')]);
}

// Function to extend time
function extendTime($user_id)
{
    global $pdo;

    // Ambil data user (room_id)
    $stmt = $pdo->prepare("SELECT room_id, is_extended, check_out FROM room_users WHERE id = ? AND status != 'done'");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    if (!$user) {
        return ['success' => false, 'message' => 'User not found'];
    }

    // Cek apakah ada waiting list status 'done' di bilik yang sama
    $waiting_done_check = $pdo->prepare("SELECT COUNT(*) FROM waiting_list WHERE room_id = ? AND status = 'done'");
    $waiting_done_check->execute([$user['room_id']]);
    if ($waiting_done_check->fetchColumn() > 0) {
        return ['success' => false, 'message' => 'Gagal memperpanjang: Ada waiting list dengan status DONE di bilik ini!'];
    }

    // Cek apakah ada waiting list status 'waiting' di bilik manapun
    $waiting_check = $pdo->prepare("SELECT COUNT(*) FROM waiting_list WHERE status = 'waiting'");
    $waiting_check->execute();
    if ($waiting_check->fetchColumn() > 0) {
        return ['success' => false, 'message' => 'Tidak dapat diperpanjang karena ada waiting list!'];
    }

    if ($user['is_extended']) {
        return ['success' => false, 'message' => 'Already extended'];
    }

    // Perpanjang 2 jam dari waktu check_out sebelumnya
    $new_check_out = new DateTime($user['check_out']);
    $new_check_out->add(new DateInterval('PT2H'));

    $update_stmt = $pdo->prepare("UPDATE room_users SET check_out = ?, is_extended = 1 WHERE id = ?");
    $success = $update_stmt->execute([$new_check_out->format('Y-m-d H:i:s'), $user_id]);

    return ['success' => $success, 'message' => $success ? 'Perpanjang Berhasil' : 'Perpanjangan Gagal'];
}

// Function to mark user as done
function markUserDone($user_id)
{
    global $pdo;
    // Ambil room_id dari user yang selesai
    $stmt = $pdo->prepare("SELECT room_id FROM room_users WHERE id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch();

    // Update status user menjadi done TANPA mengubah check_out
    $update = $pdo->prepare("UPDATE room_users SET status = 'done' WHERE id = ?");
    $result = $update->execute([$user_id]);
    
    return $result;
}

// Function to remove user from room
function removeUserFromRoom($user_id)
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM room_users WHERE id = ?");
    return $stmt->execute([$user_id]);
}

// Function to add to waiting list
function addToWaitingList($user_id, $user_name, $phone, $room_id)
{
    global $pdo;

    // CEK: Apakah ID/NIK sedang aktif di bilik manapun (status != 'done')
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

    // --- TARUH DI SINI ---
    $is_room_available = $capacity && $capacity['current_count'] < $capacity['max_capacity'];

    // Tambahkan pengecekan: slot kosong hanya jika current_count benar-benar < max_capacity DAN tidak ada user aktif dengan waktu OUT > sekarang
    if ($is_room_available) {
        // Cek apakah semua user aktif sudah OUT
        $user_check = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE room_id = ? AND status != 'done' AND check_out > NOW()");
        $user_check->execute([$room_id]);
        $still_active = $user_check->fetchColumn();
        if ($still_active > 0) {
            $is_room_available = false;
        }
    }
    // --- SAMPAI SINI ---

    $stmt = $pdo->prepare("INSERT INTO waiting_list (user_id, user_name, phone, room_id, status) VALUES (?, ?, ?, ?, ?)");
    // Jika room available, set status langsung ke 'done'
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
        'is_room_available' => $is_room_available,
        'waiting_id' => $waiting_id
    ];
}

// Function to move from waiting list to room
function moveFromWaitingToRoom($waiting_id, $new_room_id = null)
{
    global $pdo;

    $stmt = $pdo->prepare("SELECT * FROM waiting_list WHERE id = ? AND status = 'done'");
    $stmt->execute([$waiting_id]);
    $waiting_user = $stmt->fetch();

    if (!$waiting_user) {
        return false;
    }

    // Gunakan new_room_id jika ada, jika tidak gunakan room_id dari waiting list
    $target_room_id = $new_room_id ?? $waiting_user['room_id'];

    // Add to room_users dengan room_id yang baru, tandai sebagai from_waiting
    $result = addUserToRoom($target_room_id, $waiting_user['user_id'], $waiting_user['user_name'], true);

    if ($result) {
        // Remove from waiting list
        $delete_stmt = $pdo->prepare("DELETE FROM waiting_list WHERE id = ?");
        $delete_stmt->execute([$waiting_id]);
        return true;
    }

    return false;
}
// Function to mark waiting list as done
function markWaitingDone($waiting_id)
{
    global $pdo;
    
    // Ambil room_id dari waiting list (tidak perlu validasi bilik kosong untuk perubahan manual)
    $stmt = $pdo->prepare("SELECT room_id FROM waiting_list WHERE id = ?");
    $stmt->execute([$waiting_id]);
    $waiting = $stmt->fetch();
    
    if (!$waiting) {
        return ['success' => false, 'message' => 'Waiting list tidak ditemukan'];
    }
    
    // Langsung ubah status ke done tanpa validasi
    $update_stmt = $pdo->prepare("UPDATE waiting_list SET status = 'done' WHERE id = ?");
    $success = $update_stmt->execute([$waiting_id]);
    return ['success' => $success, 'message' => $success ? 'Status daftar tunggu diperbarui menjadi selesai' : 'Gagal memperbarui status'];
}

// Function to remove from waiting list
function removeFromWaitingList($waiting_id)
{
    global $pdo;
    $stmt = $pdo->prepare("DELETE FROM waiting_list WHERE id = ?");
    return $stmt->execute([$waiting_id]);
}

function moveWaitingRoom($waiting_id, $new_room_id)
{
    global $pdo;

    // Ambil data waiting list
    $stmt = $pdo->prepare("SELECT * FROM waiting_list WHERE id = ?");
    $stmt->execute([$waiting_id]);
    $waiting = $stmt->fetch();

    if (!$waiting) {
        return ['success' => false, 'message' => 'Data waiting list tidak ditemukan'];
    }

    // Check room capacity
    $capacity_check = $pdo->prepare("
        SELECT r.max_capacity, COUNT(ru.id) as current_count 
        FROM rooms r 
        LEFT JOIN room_users ru ON r.id = ru.room_id AND ru.status != 'done'
        WHERE r.id = ? 
        GROUP BY r.id
    ");
    $capacity_check->execute([$new_room_id]);
    $capacity = $capacity_check->fetch();

    // Jika bilik penuh
    if ($capacity && $capacity['current_count'] >= $capacity['max_capacity']) {
        return ['success' => false, 'message' => 'Bilik tujuan penuh'];
    }

    // Update room_id di waiting list
    $update = $pdo->prepare("UPDATE waiting_list SET room_id = ? WHERE id = ?");
    $success = $update->execute([$new_room_id, $waiting_id]);

    return [
        'success' => $success,
        'message' => $success ? 'Berhasil memindahkan user' : 'Gagal memindahkan user'
    ];
}

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


// ===== HANDLE POST REQUESTS =====

if ($_POST) {
    // Handle custom data fetch actions
    if ($_POST['action'] === 'get_rental_history') {
        $page = isset($_POST['page']) ? max(1, intval($_POST['page'])) : 1;
        $limit = 8;
        $offset = ($page - 1) * $limit;
        $room_id = isset($_POST['room_id']) && $_POST['room_id'] !== '' ? $_POST['room_id'] : null;
        $search = isset($_POST['search']) ? trim($_POST['search']) : '';

        $where = "WHERE ru.status = 'done'";
        $params = [];
        if ($room_id) {
            $where .= " AND ru.room_id = ?";
            $params[] = $room_id;
        }
        if ($search !== '') {
            $where .= " AND (ru.user_id LIKE ? OR ru.user_name LIKE ?)";
            $params[] = "%$search%";
            $params[] = "%$search%";
        }

        // Get total count for pagination
        $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM room_users ru $where");
        $count_stmt->execute($params);
        $total = $count_stmt->fetchColumn();

        // Get paginated data
        $sql = "
        SELECT ru.user_id, ru.user_name, ru.check_in, ru.check_out, r.room_name
        FROM room_users ru
        JOIN rooms r ON ru.room_id = r.id
        $where
        ORDER BY ru.check_in DESC 
        LIMIT $limit OFFSET $offset
        ";
        $history_stmt = $pdo->prepare($sql);
        $history_stmt->execute($params);
        $history = $history_stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($history as &$item) {
            $item['check_in'] = date('Y-m-d H:i', strtotime($item['check_in']));
            $item['check_out'] = date('Y-m-d H:i', strtotime($item['check_out']));
            $item['room_name'] = htmlspecialchars($item['room_name']);
            $item['user_id'] = htmlspecialchars($item['user_id']);
            $item['user_name'] = htmlspecialchars($item['user_name']);
        }

        echo json_encode([
            'history' => $history,
            'total' => $total,
            'limit' => $limit,
            'page' => $page
        ]);
        exit;
    }

    if ($_POST['action'] === 'get_summary_data') {
        // Data pengunjung per hari (Senin-Minggu, 30 hari terakhir)
        $weekly_stmt = $pdo->query("
            SELECT DAYOFWEEK(check_out) as day_num, COUNT(*) as count
            FROM room_users
            WHERE status = 'done' AND check_out >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY day_num
            ORDER BY day_num ASC
        ");
        $weekly_data = $weekly_stmt->fetchAll(PDO::FETCH_ASSOC);

        // Label hari (Senin-Minggu)
        $day_labels = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'];
        $weekly_counts = array_fill(0, 7, 0);

        foreach ($weekly_data as $row) {
            $idx = intval($row['day_num']) - 1; // DAYOFWEEK: 1=Sunday, 2=Monday, dst
            $weekly_counts[$idx] = (int) $row['count'];
        }

        // ...top_users code tetap...
        $top_users_stmt = $pdo->query("
            SELECT user_id, COUNT(*) as frequency
            FROM room_users 
            WHERE status = 'done'
            GROUP BY user_id 
            ORDER BY frequency DESC 
            LIMIT 10
        ");
        $top_users = $top_users_stmt->fetchAll(PDO::FETCH_ASSOC);

        $top_users_labels = [];
        $top_users_freq = [];
        foreach ($top_users as $user) {
            $top_users_labels[] = htmlspecialchars($user['user_id']);
            $top_users_freq[] = (int) $user['frequency'];
        }

        $room_counts_stmt = $pdo->query("
        SELECT r.room_name, COUNT(*) as count
        FROM room_users ru
        JOIN rooms r ON ru.room_id = r.id
        WHERE ru.status = 'done' AND ru.check_out >= DATE_SUB(NOW(), INTERVAL 30 DAY)
        GROUP BY ru.room_id
        ORDER BY count DESC
    ");
        $room_counts = $room_counts_stmt->fetchAll(PDO::FETCH_ASSOC);

        $room_labels = [];
        $room_data = [];
        foreach ($room_counts as $row) {
            $room_labels[] = htmlspecialchars($row['room_name']);
            $room_data[] = (int) $row['count'];
        }

        echo json_encode([
            'weekly_labels' => $day_labels,
            'weekly_data' => $weekly_counts,
            'top_users_labels' => $top_users_labels,
            'top_users_data' => $top_users_freq,
            'room_labels' => $room_labels,
            'room_data' => $room_data
        ]);
        exit;
    }

    // Handle other actions
    $response = ['success' => false, 'message' => ''];

    switch ($_POST['action']) {
        case 'add_room':
            $response['success'] = addRoom($_POST['room_name'], $_POST['max_capacity']);
            $response['message'] = $response['success'] ? 'Ruang berhasil ditambahkan' : 'Gagal menambahkan ruang';
            break;

        case 'delete_room':
            $response['success'] = deleteRoom($_POST['room_id']);
            $response['message'] = $response['success'] ? 'Ruang berhasil dihapus' : 'Gagal menghapus ruang';
            break;

        case 'add_user_to_room':
            $result = addUserToRoom($_POST['room_id'], $_POST['user_id'], $_POST['user_name']);
            if ($result === 'duplicate_id') {
                $response['success'] = false;
                $response['message'] = 'ID/NIK sudah digunakan dan masih aktif di bilik lain!';
            } else {
                $response['success'] = $result;
                $response['message'] = $result ? 'Pengguna berhasil ditambahkan ke ruang' : 'Gagal menambahkan pengguna (ruang mungkin penuh, ada daftar tunggu, atau ID/NIK sudah aktif)';
            }
            break;

        case 'extend_time':
            $response = extendTime($_POST['user_id']);
            break;

        case 'mark_user_done':
            $response['success'] = markUserDone($_POST['user_id']);
            $response['message'] = $response['success'] ? 'Status berhasil diperbarui menjadi selesai' : 'Gagal memperbarui status';
            break;

        case 'remove_user':
            $response['success'] = removeUserFromRoom($_POST['user_id']);
            $response['message'] = $response['success'] ? 'Pengguna berhasil dihapus' : 'Gagal menghapus pengguna';
            break;

        case 'add_to_waiting':
            $response = addToWaitingList($_POST['user_id'], $_POST['user_name'], $_POST['phone'], $_POST['room_id']);
            break;

        case 'move_to_room':
            $move_result = moveFromWaitingToRoom(
                $_POST['waiting_id'],
                isset($_POST['new_room_id']) ? $_POST['new_room_id'] : null
            );
            if ($move_result === false) {
                // Cek apakah karena ruangan penuh
                // Ambil room_id target
                $waiting_id = $_POST['waiting_id'];
                $new_room_id = isset($_POST['new_room_id']) ? $_POST['new_room_id'] : null;
                // Ambil data waiting_list
                $stmt = $pdo->prepare("SELECT * FROM waiting_list WHERE id = ?");
                $stmt->execute([$waiting_id]);
                $waiting_user = $stmt->fetch();
                $target_room_id = $new_room_id ?? ($waiting_user ? $waiting_user['room_id'] : null);

                // Cek kapasitas ruangan
                $capacity_check = $pdo->prepare("
                SELECT r.max_capacity, COUNT(ru.id) as current_count 
                FROM rooms r 
                LEFT JOIN room_users ru ON r.id = ru.room_id AND ru.status != 'done'
                WHERE r.id = ? 
                GROUP BY r.id
            ");
                $capacity_check->execute([$target_room_id]);
                $capacity = $capacity_check->fetch();

                if ($capacity && $capacity['current_count'] >= $capacity['max_capacity']) {
                    $response['success'] = false;
                    $response['message'] = 'Ruangan penuh. Tidak dapat memindahkan user ke bilik ini.';
                } else {
                    $response['success'] = false;
                    $response['message'] = 'Gagal memindahkan pengguna';
                }
            } else {
                $response['success'] = true;
                $response['message'] = 'Pengguna dipindahkan ke ruang';
            }
            break;

        case 'mark_waiting_done':
            $response = markWaitingDone($_POST['waiting_id']);
            break;

        case 'remove_from_waiting':
            $response['success'] = removeFromWaitingList($_POST['waiting_id']);
            $response['message'] = $response['success'] ? 'Berhasil dihapus dari daftar tunggu' : 'Gagal menghapus dari daftar tunggu';
            break;
        case 'edit_room':
            // Hitung jumlah user aktif di bilik sebelum update
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE room_id = ? AND status != 'done'");
            $count_stmt->execute([$_POST['room_id']]);
            $current_count = $count_stmt->fetchColumn();
        
            $max_capacity = intval($_POST['max_capacity']);
        
            // Jika kapasitas baru < jumlah user aktif, tolak update
            if ($max_capacity < $current_count) {
                $response['success'] = false;
                $response['message'] = 'Kapasitas tidak boleh kurang dari jumlah user aktif (' . $current_count . ')';
                break;
            }
        
            $stmt = $pdo->prepare("UPDATE rooms SET room_name = ?, max_capacity = ? WHERE id = ?");
            $success = $stmt->execute([$_POST['room_name'], $_POST['max_capacity'], $_POST['room_id']]);
            $response['success'] = $success;
            $response['message'] = $success ? 'Data bilik berhasil dikoreksi' : 'Gagal mengoreksi data bilik';
        
            // Jika sukses update, cek waiting list
            if ($success) {
                // Jika ada slot kosong, cek apakah bilik benar-benar kosong (tidak ada user aktif dengan check_out > NOW())
                if ($current_count < $max_capacity) {
                    // Cek apakah semua user aktif sudah OUT (mirip dengan addToWaitingList)
                    $user_check = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE room_id = ? AND status != 'done' AND check_out > NOW()");
                    $user_check->execute([$_POST['room_id']]);
                    $still_active = $user_check->fetchColumn();
                    
                    // Jika tidak ada user aktif lagi, baru set waiting list ke 'done'
                    if ($still_active == 0) {
                        $waiting_stmt = $pdo->prepare("SELECT id FROM waiting_list WHERE room_id = ? AND status = 'waiting' ORDER BY created_at ASC LIMIT 1");
                        $waiting_stmt->execute([$_POST['room_id']]);
                        $waiting = $waiting_stmt->fetch();
                        if ($waiting) {
                            $done_stmt = $pdo->prepare("UPDATE waiting_list SET status = 'done' WHERE id = ?");
                            $done_stmt->execute([$waiting['id']]);
                        }
                    }
                }
            }
            break;

        case 'move_waiting_room':
            $result = moveWaitingRoom($_POST['waiting_id'], $_POST['new_room_id']);
            $response['success'] = $result['success'];
            $response['message'] = $result['message'];
            break;

        case 'move_user_room':
            // Cek kapasitas bilik baru
            $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM room_users WHERE room_id = ? AND status != 'done'");
            $count_stmt->execute([$_POST['new_room_id']]);
            $current_count = $count_stmt->fetchColumn();

            $max_stmt = $pdo->prepare("SELECT max_capacity FROM rooms WHERE id = ?");
            $max_stmt->execute([$_POST['new_room_id']]);
            $max_capacity = $max_stmt->fetchColumn();

            if ($current_count >= $max_capacity) {
                $response['success'] = false;
                $response['message'] = 'Bilik tujuan penuh!';
                break;
            }

            // Update room_id user
            $stmt = $pdo->prepare("UPDATE room_users SET room_id = ? WHERE id = ?");
            $success = $stmt->execute([$_POST['new_room_id'], $_POST['user_id']]);
            $response['success'] = $success;
            $response['message'] = $success ? 'User berhasil dipindahkan ke bilik baru' : 'Gagal memindahkan user';
            break;

    }

    echo json_encode($response);
    exit;
}


// ===== FETCH DATA FOR DISPLAY =====

// Fetch rooms
$rooms_stmt = $pdo->query("SELECT * FROM rooms ORDER BY id");
$rooms = $rooms_stmt->fetchAll();

// Fetch room users with additional info
$room_users_stmt = $pdo->query("
    SELECT ru.*, r.room_name 
    FROM room_users ru 
    JOIN rooms r ON ru.room_id = r.id 
    WHERE ru.status != 'done' 
    ORDER BY ru.room_id, ru.id
");
$room_users = $room_users_stmt->fetchAll();

// Group users per room
$users_per_room = [];
foreach ($room_users as $user) {
    $users_per_room[$user['room_id']][] = $user;
}

// Fetch waiting list
$waiting_stmt = $pdo->query("
    SELECT wl.*, r.room_name 
    FROM waiting_list wl 
    JOIN rooms r ON wl.room_id = r.id 
    ORDER BY wl.room_id, wl.created_at
");
$waiting_list = $waiting_stmt->fetchAll();

// Group waiting per room
$waiting_per_room = [];
foreach ($waiting_list as $waiting) {
    $waiting_per_room[$waiting['room_id']][] = $waiting;
}

// Get earliest check_out per room
$earliest_check_out = getEarliestCheckOut();

// Calculate estimated finish time for each waiting person
$waiting_estimates = []; // array dengan key = waiting_id, value = estimated_finish_time
foreach ($waiting_per_room as $room_id => $waitings) {
    $base_time = $earliest_check_out[$room_id] ?? null;
    if (!$base_time) {
        $base_time = date('Y-m-d H:i:s'); // Jika tidak ada user aktif, gunakan waktu sekarang
    }
    
    $current_estimate = $base_time;
    foreach ($waitings as $index => $waiting) {
        if ($waiting['status'] == 'done') {
            // Yang status done tidak perlu estimasi
            continue;
        }
        // Hitung estimasi: base_time + (index * 2 jam)
        // Index 0 = first in queue = base_time
        // Index 1 = second in queue = base_time + 2 jam
        // dst
        $estimate_time = new DateTime($base_time);
        $estimate_time->add(new DateInterval('PT' . (2 * $index) . 'H'));
        $waiting_estimates[$waiting['id']] = $estimate_time->format('Y-m-d H:i:s');
    }
}

?>

<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bilik Dialog - Perpustakaan Jakarta</title>
    <link href="https://cdn.jsdelivr.net/npm/tailwindcss@2.2.19/dist/tailwind.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/icon?family=Material+Icons" rel="stylesheet">
    <link rel="icon" type="image/png" href="assets/Perpustakaan-Jakarta-Logo.png">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/sweetalert2@11/dist/sweetalert2.min.css">
    <script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        /* Tombol confirm */
        .swal2-confirm {
            background-color: #fff !important;
            color: #3b82f6 !important;
            border: 2px solid #3b82f6 !important;
            box-shadow: none !important;
            transition: all 0.2s ease-in-out;
        }

        .swal2-confirm:hover {
            background-color: #3b82f6 !important;
            color: #fff !important;
        }

        /* Tombol cancel */
        .swal2-cancel {
            background-color: #fff !important;
            color: #6b7280 !important;
            /* abu-abu teks */
            border: 2px solid #9ca3af !important;
            box-shadow: none !important;
            transition: all 0.2s ease-in-out;
        }

        .swal2-cancel:hover {
            background-color: #9ca3af !important;
            color: #fff !important;
        }

        .material-icons {
            font-size: 1.2rem;
            vertical-align: middle;
        }

        table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
        }

        th,
        td {
            border: 1px solid #e5e7eb;
        }

        th {
            background-color: #3b82f6;
            color: white;
        }

        tr:nth-child(even) {
            background-color: #f9fafb;
        }

        tr:hover {
            background-color: #f3f4f6;
        }

        .draggable {
            cursor: move;
        }

        .drag-over {
            border: 2px dashed #3b82f6;
            background-color: rgba(59, 130, 246, 0.1);
        }

        .ghost {
            opacity: 0.5;
            background-color: #e5e7eb;
        }

        .scroll-indicator {
            position: fixed;
            width: 50px;
            height: 50px;
            background-color: rgba(59, 130, 246, 0.3);
            border-radius: 50%;
            display: none;
            justify-content: center;
            align-items: center;
            z-index: 1000;
            pointer-events: none;
        }

        .scroll-indicator i {
            color: white;
            font-size: 24px;
        }

        #scrollUpIndicator {
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
        }

        #scrollDownIndicator {
            left: 20px;
            top: 50%;
            transform: translateY(-50%);
        }
    </style>
    <script>
        // Auto-refresh setiap 30 detik, selalu mulai dari 30 setiap reload
        let countdownInterval;
        let targetTime;

        function startCountdown() {
            // Set target 30 detik dari sekarang
            targetTime = Date.now() + 30000; // 30 detik dalam milidetik

            // Clear interval yang lama jika ada
            if (countdownInterval) {
                clearInterval(countdownInterval);
            }

            // Update setiap 100ms untuk akurasi lebih baik
            countdownInterval = setInterval(() => {
                const now = Date.now();
                const diff = targetTime - now;

                if (diff <= 0) {
                    clearInterval(countdownInterval);
                    location.reload();
                    return;
                }

                // Convert ke detik dan tampilkan
                const secondsLeft = Math.ceil(diff / 1000);
                document.getElementById('auto-refresh-countdown').textContent = secondsLeft;
            }, 100); // Update lebih sering (100ms) untuk presisi lebih baik
        }

        // Start countdown saat halaman dimuat
        document.addEventListener('DOMContentLoaded', startCountdown);

        // Untuk melacak notifikasi yang sudah ditampilkan
        let shownNotifications = new Set();

        function updateCountdowns() {
            const countdowns = document.querySelectorAll('[data-countdown]');
            countdowns.forEach(function (element) {
                const targetTime = new Date(element.dataset.countdown).getTime();
                const now = new Date().getTime();
                const difference = targetTime - now;

                // Cari informasi user dari baris tabel
                const row = element.closest('tr');
                if (!row) return;

                const userId = row.dataset.userId;
                const waitingId = row.dataset.waitingId;
                if (!userId && !waitingId) return; // Skip jika bukan user/waiting

                const userName = row.querySelector('td:nth-child(2)').textContent;
                const roomName = row.closest('.bg-white').querySelector('h3').textContent;

                if (difference > 0) {
                    const hours = Math.floor(difference / (1000 * 60 * 60));
                    const minutes = Math.floor((difference % (1000 * 60 * 60)) / (1000 * 60));
                    const seconds = Math.floor((difference % (1000 * 60)) / 1000);

                    element.textContent = String(hours).padStart(2, '0') + ':' +
                        String(minutes).padStart(2, '0') + ':' +
                        String(seconds).padStart(2, '0');

                    // Notifikasi 5 menit sebelum waktu habis
                    if (difference <= 300000 && difference > 299000 && !shownNotifications.has(`warning_${userId}`)) { // 5 menit = 300000ms
                        shownNotifications.add(`warning_${userId}`);
                        Swal.fire({
                            title: 'Peringatan!',
                            html: `Waktu penggunaan <strong>${roomName}</strong> oleh <strong>${userName}</strong> akan habis dalam 5 menit!`,
                            icon: 'warning',
                            timer: 5000,
                            timerProgressBar: true
                        });
                    }
                } else {
                    element.textContent = '00:00:00';

                    // Notifikasi waktu habis (hanya sekali)
                    if (!shownNotifications.has(`timeout_${userId}`)) {
                        shownNotifications.add(`timeout_${userId}`);
                        Swal.fire({
                            title: 'Waktu Habis!',
                            html: `Waktu penggunaan <strong>${roomName}</strong> oleh <strong>${userName}</strong> telah habis!<br>
                                   Silakan selesaikan sesi atau perpanjang waktu.`,
                            icon: 'error',
                            showCancelButton: true,
                            showCloseButton: true, // <-- Tambahkan ini
                            confirmButtonText: 'Selesaikan',
                            cancelButtonText: 'Perpanjang',
                            allowOutsideClick: false
                        }).then((result) => {
                            if (result.isConfirmed) {
                                // Selesaikan sesi
                                submitForm({
                                    action: 'mark_user_done',
                                    user_id: userId
                                });
                            } else if (result.dismiss === Swal.DismissReason.cancel) {
                                // Perpanjang waktu
                                extendTime(userId);
                            }
                        });
                    }
                }
            });
        }

        // Reset notifikasi setiap halaman di-refresh
        window.addEventListener('beforeunload', () => {
            shownNotifications.clear();
        });

        // Update countdown every second
        setInterval(updateCountdowns, 1000);

        // Function to submit form via AJAX
        function submitForm(formData, silent = false) {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(formData)
            })
                .then(response => response.json())
                .then(data => {
                    // Cek jika gagal menambah user ke bilik karena penuh
                    if (
                        formData.action === 'add_user_to_room' &&
                        !data.success &&
                        data.message.includes('Gagal menambahkan pengguna')
                    ) {
                        Swal.fire({
                            title: 'Gagal',
                            text: data.message + '\nIngin memasukkan ke daftar waiting list?',
                            icon: 'warning',
                            showCancelButton: true,
                            confirmButtonText: 'Ya',
                            cancelButtonText: 'Tidak'
                        }).then((result) => {
                            if (result.isConfirmed) {
                                // Kirim form ke waiting list
                                submitForm({
                                    action: 'add_to_waiting',
                                    user_id: formData.user_id,
                                    user_name: formData.user_name,
                                    phone: '', // Bisa tambahkan input phone jika perlu
                                    room_id: formData.room_id
                                });
                            }
                        });
                    } else if (!silent) {
                        Swal.fire({
                            title: data.success ? 'Sukses' : 'Gagal',
                            text: data.message,
                            icon: data.success ? 'success' : 'error',
                            confirmButtonText: 'OK'
                        }).then(() => {
                            if (data.success) {
                                location.reload();
                            }
                        });
                    } else {
                        // silent mode: reload jika sukses, tanpa popup
                        if (data.success) location.reload();
                    }
                })
                .catch(error => {
                    if (!silent) {
                        Swal.fire({
                            title: 'Error',
                            text: 'Terjadi kesalahan',
                            icon: 'error',
                            confirmButtonText: 'OK'
                        });
                    }
                });
        }

        // Function to extend time
        function extendTime(userId) {
            Swal.fire({
                title: 'Perpanjang Waktu',
                text: 'Apakah Anda yakin ingin memperpanjang waktu untuk user ini?',
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm({ action: 'extend_time', user_id: userId });
                }
            });
        }

        // Function to confirm user removal
        function confirmRemoveUser(userId, userName) {
            Swal.fire({
                title: 'Hapus User',
                text: `Apakah Anda yakin ingin menghapus user ${userName} dari bilik?`,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm({ action: 'remove_user', user_id: userId });
                }
            });
        }

        // Function to move from waiting list to room
        function moveToRoom(waitingId, userName) {
            Swal.fire({
                title: 'Pindahkan User',
                text: `Apakah Anda yakin ingin memindahkan ${userName} ke bilik?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm({ action: 'move_to_room', waiting_id: waitingId });
                }
            });
        }

        // Function to confirm room deletion
        function confirmDeleteRoom(roomId) {
            // Cek apakah masih ada user aktif di bilik ini
            const usersTable = document.querySelector(`.room-users-table[data-room-id="${roomId}"]`);
            let hasActiveUser = false;
            if (usersTable) {
                const rows = usersTable.querySelectorAll('tbody tr[data-user-id]');
                rows.forEach(row => {
                    // Cek status user, hanya yang status != 'done'
                    const statusTd = row.querySelector('td:nth-child(3)');
                    if (statusTd) {
                        // Cek apakah status berupa <select> (user aktif) atau <span> (done)
                        if (statusTd.querySelector('select')) {
                            hasActiveUser = true;
                        } else if (statusTd.textContent && !statusTd.textContent.toLowerCase().includes('done')) {
                            hasActiveUser = true;
                        }
                    }
                });
            }

            let pesan = 'Apakah Anda yakin ingin menghapus bilik ini?';
            if (hasActiveUser) {
                pesan = 'Bilik ini masih memiliki user aktif! Menghapus bilik akan menghapus semua user di dalamnya. Lanjutkan?';
            }

            Swal.fire({
                title: 'Hapus Bilik',
                text: pesan,
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm({ action: 'delete_room', room_id: roomId });
                }
            });
        }

        function showEditRoomForm(roomId, roomName, maxCapacity) {
            Swal.fire({
                title: 'Koreksi Data Bilik',
                html: `
            <form id="editRoomForm" class="flex flex-col gap-4 mt-2">
                <input type="hidden" name="action" value="edit_room">
                <input type="hidden" name="room_id" value="${roomId}">
                <label class="text-left text-sm font-medium text-gray-700">Nama Bilik:</label>
                <input type="text" name="room_name" value="${roomName}" required
                    class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <label class="text-left text-sm font-medium text-gray-700">Kapasitas Maksimum:</label>
                <input type="number" name="max_capacity" value="${maxCapacity}" min="1" required
                    class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
            </form>
        `,
                showCancelButton: true,
                confirmButtonText: 'Simpan',
                cancelButtonText: 'Batal',
                preConfirm: () => {
                    const form = Swal.getPopup().querySelector('#editRoomForm');
                    const formData = new FormData(form);
                    return Object.fromEntries(formData);
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm(result.value);
                }
            });
        }

        // Function to confirm removal from waiting list
        function confirmRemoveFromWaiting(waitingId) {
            Swal.fire({
                title: 'Hapus dari Waiting List',
                text: 'Apakah Anda yakin ingin menghapus user ini dari waiting list?',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm({ action: 'remove_from_waiting', waiting_id: waitingId });
                }
            });
        }

        function showEditRoomForm(roomId, roomName, maxCapacity) {
            Swal.fire({
                title: 'Koreksi Data Bilik',
                html: `
                    <form id="editRoomForm" class="flex flex-col gap-4 mt-2">
                        <input type="hidden" name="action" value="edit_room">
                        <input type="hidden" name="room_id" value="${roomId}">
                        <label class="text-left text-sm font-medium text-gray-700">Nama Bilik:</label>
                        <input type="text" name="room_name" value="${roomName}" required
                            class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                        <label class="text-left text-sm font-medium text-gray-700">Kapasitas Maksimum:</label>
                        <input type="number" name="max_capacity" value="${maxCapacity}" min="1" required
                            class="border border-gray-300 rounded-lg px-4 py-2 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    </form>
                `,
                showCancelButton: true,
                confirmButtonText: 'Simpan',
                cancelButtonText: 'Batal',
                preConfirm: () => {
                    const form = Swal.getPopup().querySelector('#editRoomForm');
                    const formData = new FormData(form);
                    return Object.fromEntries(formData);
                }
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm(result.value);
                }
            });
        }

        function showUsageGuide() {
            Swal.fire({
                title: 'Panduan Penggunaan',
                html: `
            <div class="text-left text-gray-700 max-h-[60vh] overflow-y-auto pr-4">
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    
                    <!-- Kolom kiri -->
                    <div>
                        <p class="mb-2"><strong>1. Kelola Bilik:</strong></p>
                        <ul class="list-disc list-inside mb-3 text-sm">
                            <li>Tambah bilik baru dengan nama dan kapasitas maksimum menggunakan form "Kelola Bilik".</li>
                            <li>Edit bilik dengan tombol <span class="material-icons align-middle text-yellow-500">edit</span> untuk mengubah nama atau kapasitas (tidak boleh kurang dari jumlah pengguna aktif).</li>
                            <li>Hapus bilik dengan tombol <span class="material-icons align-middle text-red-500">delete</span> jika tidak diperlukan.</li>
                        </ul>

                        <p class="mb-2"><strong>2. Tambah Pengguna ke Bilik:</strong></p>
                        <ul class="list-disc list-inside mb-3 text-sm">
                            <li>Pilih bilik, masukkan ID/NIK dan nama pengguna, lalu klik "Tambah ke Bilik".</li>
                            <li>Pengguna tidak dapat ditambahkan jika bilik penuh, ada waiting list, atau ID/NIK sudah aktif di bilik lain.</li>
                            <li>Jika bilik penuh, Anda akan ditanya apakah ingin menambahkan ke waiting list.</li>
                        </ul>

                        <p class="mb-2"><strong>3. Kelola Pengguna Bilik:</strong></p>
                        <ul class="list-disc list-inside mb-3 text-sm">
                            <li>Lihat daftar pengguna per bilik dengan status (Active, Warning, Overtime), waktu masuk, keluar, dan countdown.</li>
                            <li>Klik ID/NIK untuk menghapus pengguna. Pilih status "Done" untuk menandai selesai, atau klik "Perpanjang" untuk menambah 2 jam (hanya sekali, jika tidak ada waiting list).</li>
                        </ul>
                    </div>

                    <!-- Kolom kanan -->
                    <div>
                        <p class="mb-2"><strong>4. Waiting List:</strong></p>
                        <ul class="list-disc list-inside mb-3 text-sm">
                            <li>Tambah pengguna ke waiting list dengan ID/NIK, nama, nomor HP, dan bilik tujuan jika bilik penuh.</li>
                            <li>Jika bilik tersedia, pengguna di waiting list otomatis berstatus "Done" dan dapat dipindahkan ke bilik dengan mengklik ID/NIK.</li>
                            <li>Hapus pengguna dari waiting list dengan tombol <span class="material-icons align-middle text-red-500">delete</span>.</li>
                        </ul>

                        <p class="mb-2 flex items-center gap-2">
                            <strong>5. Drag & Drop User & Waiting List
                                <span class="material-icons align-middle text-blue-500" style="font-size:1rem;">touch_app</span>
                                <button onclick="showDragDropVideo()" type="button" class="ml-2 bg-blue-500 mt-1 hover:bg-blue-600 text-white px-3 py-1 rounded text-xs flex items-center">
                                    <span class="material-icons mr-1" style="font-size:1rem;">play_circle</span> Cara Penggunaan
                                </button>
                            </strong>
                        </p>
                        <ul class="list-disc list-inside mb-3 text-sm">
                            <li>
                                <b>Drag & drop user antar bilik:</b> Anda dapat memindahkan user dari satu bilik ke bilik lain dengan cara drag & drop baris user pada tabel "Pengguna Bilik" ke tabel bilik tujuan.
                            </li>
                            <li>
                                <b>Drag & drop waiting list ke bilik:</b> Anda juga dapat memindahkan user dari waiting list ke bilik lain dengan drag & drop baris user pada tabel waiting list ke tabel bilik tujuan.
                            </li>
                            <li>
                                Jika bilik tujuan penuh, akan muncul pesan bahwa ruangan penuh dan user tidak akan dipindahkan.
                            </li>
                            <li>
                                Drag & drop waiting list ke bilik hanya bisa dilakukan untuk user dengan status <b>Done</b> pada waiting list.
                            </li>
                        </ul>

                        <p class="mb-2"><strong>6. Riwayat Penyewa:</strong></p>
                        <ul class="list-disc list-inside mb-3 text-sm">
                            <li>Klik "Daftar Riwayat Penyewa" untuk melihat riwayat pengguna (status "Done") per bilik.</li>
                            <li>Gunakan filter bilik dan navigasi halaman untuk melihat data tertentu.</li>
                        </ul>

                        <p class="mb-2"><strong>7. Rekapan:</strong></p>
                        <ul class="list-disc list-inside mb-3 text-sm">
                            <li>Klik "Rekapan" untuk melihat diagram: perbandingan pengunjung Senin-Minggu (polar area), perbandingan antar bilik (donut), dan pengguna paling sering menyewa (line).</li>
                        </ul>

                        <p class="text-sm"><strong>Catatan:</strong> Halaman otomatis refresh setiap 30 detik untuk memperbarui data dan waktu Indonesia.</p>
                    </div>

                </div>
            </div>
        `,
                icon: 'info',
                confirmButtonText: 'Tutup',
                width: '800px',
                padding: '1.5rem',
                customClass: {
                    confirmButton: 'bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition',
                    htmlContainer: '!mt-2'
                }
            });
        }

        // Tambahkan fungsi popup video tutorial drag & drop
        function showDragDropVideo() {
            Swal.fire({
                title: 'Video Tutorial Drag & Drop Waiting List',
                html: `
            <div class="w-full flex flex-col items-center">
                <video controls style="max-width:100%;border-radius:8px;box-shadow:0 2px 8px #0001;">
                    <source src="assets/dragdrop-tutorial.mp4" type="video/mp4">
                    Browser Anda tidak mendukung video.
                </video>
            </div>
        `,
                width: 600,
                showConfirmButton: true,
                confirmButtonText: 'Tutup',
                customClass: {
                    confirmButton: 'bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition'
                }
            });
        }


        function showRentalHistory(page = 1, roomId = '', search = '') {
            // Ambil daftar bilik untuk filter
            let roomOptions = `<option value="">Semua Bilik</option>`;
            <?php foreach ($rooms as $room): ?>
                roomOptions += `<option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['room_name']) ?></option>`;
            <?php endforeach; ?>

            let selectedRoom = roomId;
            let searchValue = search || '';

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({
                    action: 'get_rental_history',
                    page: page,
                    room_id: selectedRoom,
                    search: searchValue
                })
            })
                .then(response => response.json())
                .then(data => {
                    let historyHtml = `
            <div class="mb-4 flex flex-col sm:flex-row gap-2 items-center">
                <label class="text-sm font-medium text-gray-700">Filter Bilik:</label>
                <select id="historyRoomFilter" class="border border-gray-300 rounded px-2 py-1">
                    ${roomOptions}
                </select>
                <input id="historySearchInput" type="text" placeholder="Cari Nama/ID/NIK" value="${searchValue.replace(/"/g, '&quot;')}" class="border border-gray-300 rounded px-2 py-1 ml-2" style="min-width:180px;">
                <button onclick="showRentalHistory(1, document.getElementById('historyRoomFilter').value, document.getElementById('historySearchInput').value)" class="bg-blue-500 text-white px-3 py-1 rounded ml-1">Cari</button>
                <button onclick="showRentalHistory(1, '', '')" class="bg-gray-300 text-gray-700 px-3 py-1 rounded ml-1">Reset</button>
            </div>
            <div class="text-left text-gray-700 max-h-[60vh] overflow-y-auto pr-4 text-sm">
        `;
                    if (data.history && data.history.length > 0) {
                        historyHtml += '<table class="w-full border-collapse border border-gray-300">';
                        historyHtml += '<thead><tr class="bg-blue-500 text-white">';
                        historyHtml += '<th class="border border-gray-300 px-3 py-2">Bilik</th>';
                        historyHtml += '<th class="border border-gray-300 px-3 py-2">ID/NIK</th>';
                        historyHtml += '<th class="border border-gray-300 px-3 py-2">Nama</th>';
                        historyHtml += '<th class="border border-gray-300 px-3 py-2">Masuk</th>';
                        historyHtml += '<th class="border border-gray-300 px-3 py-2">Keluar</th>';
                        historyHtml += '</tr></thead><tbody>';
                        data.history.forEach(item => {
                            historyHtml += '<tr class="hover:bg-gray-100">';
                            historyHtml += `<td class="border border-gray-300 px-3 py-2">${item.room_name}</td>`;
                            historyHtml += `<td class="border border-gray-300 px-3 py-2">${item.user_id}</td>`;
                            historyHtml += `<td class="border border-gray-300 px-3 py-2">${item.user_name}</td>`;
                            historyHtml += `<td class="border border-gray-300 px-3 py-2">${item.check_in}</td>`;
                            historyHtml += `<td class="border border-gray-300 px-3 py-2">${item.check_out}</td>`;
                            historyHtml += '</tr>';
                        });
                        historyHtml += '</tbody></table>';
                        // Pagination
                        let totalPages = Math.ceil(data.total / data.limit);
                        historyHtml += `<div class="mt-4 flex flex-wrap gap-2 justify-center">`;
                        for (let i = 1; i <= totalPages; i++) {
                            historyHtml += `<button class="px-3 py-1 rounded ${i === data.page ? 'bg-blue-500 text-white' : 'bg-gray-200 text-gray-700'}"
                    onclick="showRentalHistory(${i}, document.getElementById('historyRoomFilter').value, document.getElementById('historySearchInput').value)">
                    ${i}
                </button>`;
                        }
                        historyHtml += `</div>`;
                    } else {
                        historyHtml += '<p class="text-center text-gray-500">Tidak ada riwayat penyewa.</p>';
                    }
                    historyHtml += '</div>';

                    Swal.fire({
                        title: 'Riwayat Penyewa Bilik',
                        html: historyHtml,
                        icon: 'info',
                        confirmButtonText: 'Tutup',
                        width: '90%',
                        Width: '800px',
                        padding: '1.5rem',
                        customClass: {
                            confirmButton: 'bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition',
                            htmlContainer: '!mt-2'
                        },
                        didOpen: () => {
                            const filter = document.getElementById('historyRoomFilter');
                            const searchInput = document.getElementById('historySearchInput');
                            if (filter) {
                                filter.value = selectedRoom;
                                filter.onchange = function () {
                                    showRentalHistory(1, this.value, searchInput.value);
                                };
                            }
                            if (searchInput) {
                                searchInput.addEventListener('keydown', function (e) {
                                    if (e.key === 'Enter') {
                                        showRentalHistory(1, filter.value, this.value);
                                    }
                                });
                            }
                        }
                    });
                })
                .catch(error => {
                    Swal.fire({
                        title: 'Error',
                        text: 'Terjadi kesalahan saat mengambil riwayat',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                });
        }
    </script>
</head>

<body class="bg-gray-50 font-sans">
    <div id="scrollUpIndicator" class="scroll-indicator">
        <i class="material-icons">arrow_upward</i>
    </div>
    <div id="scrollDownIndicator" class="scroll-indicator">
        <i class="material-icons">arrow_downward</i>
    </div>
    <div class="container mx-auto p-6 max-w-7xl">
        <div class="flex flex-col md:flex-row md:items-center md:justify-between mb-8 space-y-4 md:space-y-0">
            <!-- Judul -->
            <div class="flex flex-col items-start">
                <h1 class="text-2xl font-bold text-blue-500 flex items-center">
                    <svg class="w-6 h-6 mr-2 fill-current text-blue-500" viewBox="-13.22 0 122.88 122.88"
                        xmlns="http://www.w3.org/2000/svg">
                        <path d="M0,115.27h4.39V1.99V0h1.99h82.93h1.99v1.99v113.28h5.14v7.61H0V115.27L0,115.27z M13.88,8.32H81.8h0.83v0.83
        v104.89h4.69V3.97H8.36v111.3h4.69V9.15V8.32H13.88L13.88,8.32z M15.94,114.04H75.1l-0.38-0.15l-27.76-3.79V33.9l32.79-20.66v-2.04
        H15.94V114.04L15.94,114.04z M51.7,59.66l4.23-1.21v15.81l-4.23-1.53V59.66L51.7,59.66z" />
                    </svg>
                    Bilik Dialog
                </h1>
                <span class="text-sm text-gray-600 font-bold ml-8">Perpustakaan Jakarta</span>
            </div>

            <!-- Tombol kanan -->
            <div class="flex flex-wrap gap-2">
                <button onclick="showRentalHistory()"
                    class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition flex items-center">
                    <span class="material-icons mr-2">history</span>
                    Daftar Riwayat Penyewa
                </button>

                <button onclick="showUsageGuide()"
                    class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition flex items-center">
                    <span class="material-icons mr-2">info</span>
                    Panduan Penggunaan
                </button>

                <button onclick="showSummaryCharts()"
                    class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition flex items-center">
                    <span class="material-icons mr-2">bar_chart</span>
                    Rekapan
                </button>
            </div>



            <!-- Info auto-refresh -->
            <span class="text-sm text-gray-500">
                Auto-refresh dalam: <span id="auto-refresh-countdown"></span> detik
            </span>
        </div>

        <!-- Waktu Indonesia -->
        <div class="absolute top-6 right-8 z-10">
            <span id="waktu-indonesia"
                class="bg-white text-blue-600 font-semibold px-3 py-1 rounded shadow border border-blue-100 text-sm"></span>
        </div>

        <div class="max-w-7xl mx-auto mt-4 mb-6 px-4">
            <div
                class="bg-blue-100 border border-blue-300 rounded-lg p-4 flex flex-col md:flex-row md:items-center md:justify-between shadow">
                <div class="flex items-center gap-3">
                    <span class="material-icons text-blue-500" style="font-size:2rem;">info</span>
                    <span class="font-bold text-blue-700 text-lg">Papan Informasi Bilik Dialog</span>
                </div>
                <ul class="mt-2 md:mt-0 md:ml-8 text-blue-900 text-sm font-semibold list-disc list-inside">
                    <li>Kapasitas: <span class="font-bold">Min 3 org - Max 5 org</span></li>
                    <li>Durasi per sesi: <span class="font-bold">2:00</span></li>
                    <li>Jam Tutup Weekdays: <span class="font-bold">17:00</span></li>
                    <li>Jam Tutup Weekend: <span class="font-bold">20:00</span></li>
                </ul>
            </div>
        </div>

        <!-- Form Tambah Bilik -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <h2 class="text-lg font-semibold text-blue-500 mb-4 flex items-center">
                <span class="material-icons mr-2">add_circle</span>Kelola Bilik
            </h2>
            <form id="addRoomForm" class="flex flex-col sm:flex-row gap-4 mb-6">
                <input type="hidden" name="action" value="add_room">
                <input type="text" name="room_name" placeholder="Nama Bilik" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <input type="number" name="max_capacity" placeholder="Kapasitas Maksimum" min="1" required
                    class="border border-gray-300 rounded-lg px-4 py-2 w-32 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <button type="submit"
                    class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition flex items-center">
                    <span class="material-icons mr-2">add</span>Tambah Bilik
                </button>
            </form>

            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4">
                <?php foreach ($rooms as $room): ?>
                <div class="bg-gray-100 rounded-lg p-4 flex justify-between items-center">
                    <span class="text-gray-700"><?= htmlspecialchars($room['room_name']) ?> (Max:
                        <?= $room['max_capacity'] ?>)</span>
                    <div class="flex gap-2">
                        <button
                            onclick="showEditRoomForm(<?= $room['id'] ?>, '<?= htmlspecialchars($room['room_name']) ?>', <?= $room['max_capacity'] ?>)"
                            class="bg-yellow-500 text-white px-3 py-1 rounded-lg hover:bg-yellow-600 transition flex items-center">
                            <span class="material-icons">edit</span>
                        </button>
                        <button onclick="confirmDeleteRoom(<?= $room['id'] ?>)"
                            class="bg-red-500 text-white px-3 py-1 rounded-lg hover:bg-red-600 transition flex items-center">
                            <span class="material-icons">delete</span>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Form Tambah User ke Bilik -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <h2 class="text-lg font-semibold text-blue-500 mb-4 flex items-center">
                <span class="material-icons mr-2">person_add</span>Tambah User ke Bilik
            </h2>
            <form id="addUserForm" class="flex flex-col sm:flex-row gap-4">
                <input type="hidden" name="action" value="add_user_to_room">
                <select name="room_id" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Pilih Bilik</option>
                    <?php foreach ($rooms as $room): ?>
                    <option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="user_id" placeholder="ID/NIK" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <input type="text" name="user_name" placeholder="Nama" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <button type="submit"
                    class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition flex items-center">
                    <span class="material-icons mr-2">add</span>Tambah ke Bilik
                </button>
            </form>
        </div>

        <!-- Tabel Pengguna Bilik per Bilik -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <h2 class="text-lg font-semibold text-blue-500 mb-4 flex items-center">
                <span class="material-icons mr-2">group</span>Pengguna Bilik
            </h2>
            <?php foreach ($rooms as $room): ?>
            <h3 class="text-md font-medium text-gray-700 mb-3"><?= htmlspecialchars($room['room_name']) ?></h3>
            <div class="overflow-x-auto mb-6">
                <table class="min-w-full room-users-table" data-room-id="<?= $room['id'] ?>">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left">ID/NIK</th>
                            <th class="px-4 py-2 text-left">Nama</th>
                            <th class="px-4 py-2 text-left">Status</th>
                            <th class="px-4 py-2 text-left">IN</th>
                            <th class="px-4 py-2 text-left">OUT</th>
                            <th class="px-4 py-2 text-left">Countdown</th>
                            <th class="px-4 py-2 text-left">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $users = $users_per_room[$room['id']] ?? [];
                        foreach ($users as $user):
                            $now = new DateTime();
                            $check_out = new DateTime($user['check_out']);
                            $diff = $now->diff($check_out);

                            $status_class = 'bg-green-500';
                            $status_text = 'Active';

                            if ($user['status'] == 'done') {
                                $status_class = 'bg-green-500';
                                $status_text = 'Done';
                            } elseif ($check_out <= $now) {
                                $status_class = 'bg-red-500';
                                $status_text = 'Overtime';
                            } elseif ($diff->h == 0 && $diff->i <= 10) {
                                $status_class = 'bg-yellow-500';
                                $status_text = 'Warning';
                            }
                            ?>
                        <tr draggable="true" class="draggable hover:bg-gray-100" data-user-id="<?= $user['id'] ?>"
                            data-user-name="<?= htmlspecialchars($user['user_name']) ?>"
                            data-current-room="<?= $user['room_id'] ?>">
                            <td class="px-4 py-2 cursor-pointer hover:underline"
                                onclick="confirmRemoveUser(<?= $user['id'] ?>, '<?= htmlspecialchars($user['user_name']) ?>')">
                                <?= htmlspecialchars($user['user_id']) ?>
                            </td>
                            <td class="px-4 py-2"><?= htmlspecialchars($user['user_name']) ?></td>
                            <td class="px-4 py-2">
                                <?php if ($user['status'] == 'done'): ?>
                                <span
                                    class="<?= $status_class ?> text-white px-2 py-1 rounded text-sm"><?= $status_text ?></span>
                                <?php else: ?>
                                <select
                                    onchange="if(this.value === 'done') submitForm({action: 'mark_user_done', user_id: <?= $user['id'] ?>})"
                                    class="<?= $status_class ?> text-white px-2 py-1 rounded text-sm focus:ring-2 focus:ring-blue-500">
                                    <option value="active"><?= $status_text ?></option>
                                    <option value="done">Done</option>
                                </select>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2">
                                <?= $user['status'] != 'done' ? date('H:i', strtotime($user['check_in'])) : '' ?>
                            </td>
                            <td class="px-4 py-2">
                                <?= $user['status'] != 'done' ? date('H:i', strtotime($user['check_out'])) : '' ?>
                            </td>
                            <td class="px-4 py-2">
                                <?php if ($user['status'] != 'done'): ?>
                                <span data-countdown="<?= $user['check_out'] ?>" class="font-mono">00:00:00</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-2">
                                <?php if ($user['status'] != 'done' && !$user['is_extended']): ?>
                                <button onclick="extendTime(<?= $user['id'] ?>)"
                                    class="bg-blue-500 text-white px-3 py-1 rounded-lg hover:bg-blue-600 transition flex items-center">
                                    <span class="material-icons mr-1">schedule</span>Perpanjang
                                </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-2 text-center text-gray-500">Tidak ada pengguna</td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Form Tambah ke Waiting List -->
        <div class="bg-white p-6 rounded-lg shadow-md mb-8">
            <h2 class="text-lg font-semibold text-blue-500 mb-4 flex items-center">
                <span class="material-icons mr-2">hourglass_empty</span>Tambah ke Waiting List
            </h2>
            <form id="addWaitingForm" class="flex flex-col sm:flex-row gap-4">
                <input type="hidden" name="action" value="add_to_waiting">
                <input type="text" name="user_id" placeholder="ID/NIK" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <input type="text" name="user_name" placeholder="Nama" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <input type="text" name="phone" placeholder="No HP" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                <select name="room_id" required
                    class="border border-gray-300 rounded-lg px-4 py-2 flex-1 focus:ring-2 focus:ring-blue-500 focus:border-transparent">
                    <option value="">Pilih Bilik</option>
                    <?php foreach ($rooms as $room): ?>
                    <option value="<?= $room['id'] ?>"><?= htmlspecialchars($room['room_name']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit"
                    class="bg-blue-500 text-white px-4 py-2 rounded-lg hover:bg-blue-600 transition flex items-center">
                    <span class="material-icons mr-2">add</span>Tambah ke Waiting List
                </button>
            </form>
        </div>


        <!-- Tabel Waiting List per Bilik -->
        <div class="bg-white p-6 rounded-lg shadow-md">
            <h2 class="text-lg font-semibold text-blue-500 mb-4 flex items-center">
                <span class="material-icons mr-2">list_alt</span>Waiting List
            </h2>
            <?php foreach ($rooms as $room): ?>
            <h3 class="text-md font-medium text-gray-700 mb-3"><?= htmlspecialchars($room['room_name']) ?></h3>
            <div class="overflow-x-auto mb-6">
                <table class="min-w-full">
                    <thead>
                        <tr>
                            <th class="px-4 py-2 text-left">No</th> <!-- Tambahkan ini -->
                            <th class="px-4 py-2 text-left">ID/NIK</th>
                            <th class="px-4 py-2 text-left">Nama</th>
                            <th class="px-4 py-2 text-left">No HP</th>
                            <th class="px-4 py-2 text-left">Keterangan</th>
                            <th class="px-4 py-2 text-left">Estimasi Bilik Kosong</th>
                            <th class="px-4 py-2 text-left">Estimasi Jam Selesai</th>
                            <th class="px-4 py-2 text-left">Aksi</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $waitings = $waiting_per_room[$room['id']] ?? [];
                        $no = 1; // Inisialisasi nomor urut
                        $first_waiting = null; // Track yang pertama dalam antrian (belum done)
                        foreach ($waitings as $waiting):
                            $earliest = $earliest_check_out[$waiting['room_id']] ?? null;
                            $estimated_finish = $waiting_estimates[$waiting['id']] ?? null;
                            
                            // Tentukan apakah ini yang pertama dalam antrian (berdasarkan urutan, dan status != 'done')
                            $is_first_in_queue = ($waiting['status'] != 'done' && $first_waiting === null);
                            if ($is_first_in_queue) {
                                $first_waiting = $waiting['id'];
                            }
                            
                            // Tampilkan estimasi untuk semua yang tidak done
                            $show_estimate = ($waiting['status'] != 'done') || ($waiting['status'] == 'done');
                            ?>
                            <tr <?php if ($waiting['status'] == 'done'): ?> draggable="true" class="draggable hover:bg-gray-100" <?php else: ?> class="hover:bg-gray-100" <?php endif; ?>
                                data-waiting-id="<?= $waiting['id'] ?>"
                                data-user-name="<?= htmlspecialchars($waiting['user_name']) ?>"
                                data-current-room="<?= $waiting['room_id'] ?>">
                                <td class="px-4 py-2"><?= $no++ ?></td>
                                <td class="px-4 py-2 <?= ($waiting['status'] == 'done') ? 'bg-green-100 cursor-pointer hover:underline' : 'cursor-default' ?>"
                                    onclick="<?= $waiting['status'] == 'done' ? 'moveToRoom(' . $waiting['id'] . ', \'' . htmlspecialchars($waiting['user_name']) . '\')' : '' ?>">
                                    <?= htmlspecialchars($waiting['user_id']) ?>
                                </td>
                                <td class="px-4 py-2"><?= htmlspecialchars($waiting['user_name']) ?></td>
                                <td class="px-4 py-2"><?= htmlspecialchars($waiting['phone']) ?></td>
                                <td class="px-4 py-2">
                                    <?php if ($waiting['status'] == 'done'): ?>
                                    <span class="bg-green-500 text-white px-2 py-1 rounded text-sm">Done</span>
                                    <?php else: ?>
                                    <select onchange="if(this.value === 'done') submitForm({action: 'mark_waiting_done', waiting_id: <?= $waiting['id'] ?>})"
                                        class="bg-gray-500 text-white px-2 py-1 rounded text-sm focus:ring-2 focus:ring-blue-500">
                                        <option value="waiting">Menunggu</option>
                                        <option value="done">Done</option>
                                    </select>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-2">
                                    <input type="time" 
                                        class="estimasi-kosong-input border border-gray-300 rounded px-2 py-1 bg-gray-100" 
                                        data-waiting-id="<?= $waiting['id'] ?>"
                                        value="<?= isset($waiting['estimasi_kosong']) ? htmlspecialchars($waiting['estimasi_kosong']) : '' ?>"
                                        readonly
                                        placeholder="HH:mm"
                                        title="Auto-capture dari jam selesai pengguna bilik">
                                </td>
                                <td class="px-4 py-2">
                                    <input type="time" 
                                        class="estimasi-selesai-input border border-gray-300 rounded px-2 py-1 bg-gray-100" 
                                        data-waiting-id="<?= $waiting['id'] ?>"
                                        value="<?= isset($waiting['estimasi_selesai']) ? htmlspecialchars($waiting['estimasi_selesai']) : '' ?>"
                                        readonly
                                        placeholder="HH:mm"
                                        title="Auto-hitung: estimasi kosong + 2 jam">
                                </td>
                                <td class="px-4 py-2">
                                    <button onclick="confirmRemoveFromWaiting(<?= $waiting['id'] ?>)"
                                        class="bg-red-500 text-white px-3 py-1 rounded-lg hover:bg-red-600 transition flex items-center">
                                        <span class="material-icons mr-1">delete</span>Hapus
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <script>
        // Auto-refresh countdown yang persist saat reload
        const AUTO_REFRESH_INTERVAL = 30; // detik
        const AUTO_REFRESH_KEY = 'autoRefreshTarget';

        function getTargetTime() {
            let target = localStorage.getItem(AUTO_REFRESH_KEY);
            if (!target || isNaN(Number(target)) || Number(target) < Date.now()) {
                // Set target baru jika belum ada atau sudah lewat
                target = Date.now() + AUTO_REFRESH_INTERVAL * 1000;
                localStorage.setItem(AUTO_REFRESH_KEY, target);
            }
            return Number(target);
        }

        function updateAutoRefreshCountdown() {
            const target = getTargetTime();
            const now = Date.now();
            let secondsLeft = Math.ceil((target - now) / 1000);
            if (secondsLeft < 0) secondsLeft = 0;
            document.getElementById('auto-refresh-countdown').textContent = secondsLeft;
            if (secondsLeft <= 0) {
                // Set target baru untuk refresh berikutnya
                localStorage.setItem(AUTO_REFRESH_KEY, Date.now() + AUTO_REFRESH_INTERVAL * 1000);
                location.reload();
            }
        }
        setInterval(updateAutoRefreshCountdown, 1000);
        document.addEventListener('DOMContentLoaded', updateAutoRefreshCountdown);
        // Auto-save & restore typed input for forms to survive refresh
        function autoSaveForm(formId, storageKey) {
            document.addEventListener('DOMContentLoaded', function () {
                const form = document.getElementById(formId);
                if (!form) return;

                function save() {
                    const data = {};
                    Array.from(form.elements).forEach(el => {
                        if (!el.name || el.type === 'hidden' || el.name === 'action') return;
                        if (el.type === 'checkbox') {
                            data[el.name] = el.checked;
                        } else if (el.type === 'radio') {
                            if (el.checked) data[el.name] = el.value;
                        } else {
                            data[el.name] = el.value;
                        }
                    });
                    try { localStorage.setItem(storageKey, JSON.stringify(data)); } catch (e) {}
                }

                // Restore
                try {
                    const stored = localStorage.getItem(storageKey);
                    if (stored) {
                        const obj = JSON.parse(stored);
                        Object.keys(obj).forEach(name => {
                            const el = form.elements[name];
                            if (!el) return;
                            if (el.type === 'checkbox') {
                                el.checked = !!obj[name];
                            } else if (el.type === 'radio') {
                                if (form.elements[name].length) {
                                    Array.from(form.elements[name]).forEach(r => { if (r.value == obj[name]) r.checked = true; });
                                } else {
                                    if (form.elements[name].value == obj[name]) form.elements[name].checked = true;
                                }
                            } else {
                                el.value = obj[name];
                            }
                        });
                    }
                } catch (e) {}

                Array.from(form.elements).forEach(el => {
                    if (!el.name || el.type === 'hidden' || el.name === 'action') return;
                    el.addEventListener('input', save);
                    el.addEventListener('change', save);
                });

                form.addEventListener('submit', function () { try { localStorage.removeItem(storageKey); } catch (e) {} });
            });
        }

        autoSaveForm('addUserForm', 'form:addUserForm');
        autoSaveForm('addWaitingForm', 'form:addWaitingForm');
        autoSaveForm('addRoomForm', 'form:addRoomForm');

        // Event listeners for forms
        document.getElementById('addRoomForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            submitForm(Object.fromEntries(formData));
        });

        document.getElementById('addUserForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);
            submitForm(Object.fromEntries(formData));
        });

        // Dalam event handler form addWaitingForm
        document.getElementById('addWaitingForm').addEventListener('submit', function (e) {
            e.preventDefault();
            const formData = new FormData(this);

            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams(Object.fromEntries(formData))
            })
                .then(response => response.json())
                .then(data => {
                    // Tambahan: jika duplicate_id
                    if (data.duplicate_id) {
                        Swal.fire({
                            title: 'Gagal',
                            text: data.message,
                            icon: 'error'
                        });
                        return;
                    }
                    // Langsung tampil sukses/gagal tanpa konfirmasi tambahan
                    Swal.fire({
                        title: data.success ? 'Sukses' : 'Gagal',
                        text: data.message,
                        icon: data.success ? 'success' : 'error'
                    }).then(() => {
                        if (data.success) location.reload();
                    });
                })
                .catch(error => {
                    Swal.fire({
                        title: 'Error',
                        text: 'Terjadi kesalahan',
                        icon: 'error'
                    });
                });
        });

        // Initialize countdown when page loads
        document.addEventListener('DOMContentLoaded', function () {
            updateCountdowns();
        });

        function showSummaryCharts() {
            fetch('', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: new URLSearchParams({ action: 'get_summary_data' })
            })
                .then(response => response.json())
                .then(data => {
                    let chartsHtml = `
            <div class="text-left text-gray-700 max-h-[75vh] overflow-y-auto pr-2 grid grid-cols-1 md:grid-cols-2 gap-6">
                <!-- Weekly Chart Container -->
                <div class="mb-8 bg-white rounded-lg border border-gray-100 shadow-sm p-4">
                    <div class="flex items-center mb-4">
                        <div class="w-1 h-6 bg-blue-500 rounded-full mr-3"></div>
                        <h3 class="text-lg font-semibold text-gray-800">Perbandingan Pengunjung Senin-Minggu</h3>
                        <span class="ml-2 text-sm text-gray-500 bg-blue-50 px-2 py-1 rounded-full">Polar Area Chart</span>
                    </div>
                    <div class="relative bg-gray-50 rounded-lg p-3" style="height: 280px;">
                        <canvas id="weeklyChart" class="w-full h-full"></canvas>
                    </div>
                </div>
                <!-- Donut Chart Container -->
                <div class="mb-8 bg-white rounded-lg border border-gray-100 shadow-sm p-4">
                    <div class="flex items-center mb-4">
                        <div class="w-1 h-6 bg-green-500 rounded-full mr-3"></div>
                        <h3 class="text-lg font-semibold text-gray-800">Perbandingan Pengunjung Antar Bilik</h3>
                        <span class="ml-2 text-sm text-gray-500 bg-green-50 px-2 py-1 rounded-full">Donut Chart</span>
                    </div>
                    <div class="relative bg-gray-50 rounded-lg p-3" style="height: 280px;">
                        <canvas id="roomDonutChart" class="w-full h-full"></canvas>
                    </div>
                </div>
                <!-- Top Users Chart Container (full width) -->
                <div class="md:col-span-2 bg-white rounded-lg border border-gray-100 shadow-sm p-4">
                    <div class="flex items-center mb-4">
                        <div class="w-1 h-6 bg-blue-500 rounded-full mr-3"></div>
                        <h3 class="text-lg font-semibold text-gray-800">User Paling Sering Menyewa</h3>
                        <span class="ml-2 text-sm text-gray-500 bg-blue-50 px-2 py-1 rounded-full">Line Chart</span>
                    </div>
                    <div class="relative bg-gray-50 rounded-lg p-3" style="height: 280px;">
                        <canvas id="topUsersChart" class="w-full h-full"></canvas>
                    </div>
                </div>
            </div>
        `;

                    Swal.fire({
                        title: '<div class="flex items-center justify-center"><span class="text-blue-600 mr-2">📊</span>Rekapan Penyewaan Bilik</div>',
                        html: chartsHtml,
                        icon: false,
                        confirmButtonText: 'Tutup',
                        width: '85%',
                        padding: '1.25rem',
                        didOpen: () => {
                            // Weekly chart (polar area)
                            const weeklyCanvas = document.getElementById('weeklyChart');
                            if (weeklyCanvas) {
                                new Chart(weeklyCanvas.getContext('2d'), {
                                    type: 'polarArea',
                                    data: {
                                        labels: data.weekly_labels || [],
                                        datasets: [{
                                            label: 'Jumlah Pengunjung',
                                            data: data.weekly_data || [],
                                            backgroundColor: [
                                                'rgba(59,130,246,0.7)',
                                                'rgba(37,99,235,0.7)',
                                                'rgba(96,165,250,0.7)',
                                                'rgba(191,219,254,0.7)',
                                                'rgba(30,64,175,0.7)',
                                                'rgba(16,185,129,0.7)',
                                                'rgba(239,68,68,0.7)'
                                            ],
                                            borderColor: '#fff',
                                            borderWidth: 2
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: {
                                            legend: {
                                                display: true,
                                                position: 'right',
                                                labels: {
                                                    color: '#374151',
                                                    font: { size: 12, weight: '500' }
                                                }
                                            },
                                            tooltip: {
                                                backgroundColor: 'rgba(0,0,0,0.8)',
                                                titleColor: '#fff',
                                                bodyColor: '#fff'
                                            }
                                        }
                                    }
                                });
                            }

                            // Donut chart (perbandingan antar bilik)
                            const roomDonutCanvas = document.getElementById('roomDonutChart');
                            if (roomDonutCanvas) {
                                new Chart(roomDonutCanvas.getContext('2d'), {
                                    type: 'doughnut',
                                    data: {
                                        labels: data.room_labels || [],
                                        datasets: [{
                                            label: 'Jumlah Pengunjung',
                                            data: data.room_data || [],
                                            backgroundColor: [
                                                'rgba(59,130,246,0.7)',
                                                'rgba(16,185,129,0.7)',
                                                'rgba(239,68,68,0.7)',
                                                'rgba(253,224,71,0.7)',
                                                'rgba(139,92,246,0.7)',
                                                'rgba(34,197,94,0.7)',
                                                'rgba(251,191,36,0.7)',
                                                'rgba(244,63,94,0.7)',
                                                'rgba(59,130,246,0.4)',
                                                'rgba(16,185,129,0.4)'
                                            ],
                                            borderColor: '#fff',
                                            borderWidth: 2
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        plugins: {
                                            legend: {
                                                display: true,
                                                position: 'right',
                                                labels: {
                                                    color: '#374151',
                                                    font: { size: 12, weight: '500' }
                                                }
                                            },
                                            tooltip: {
                                                backgroundColor: 'rgba(0,0,0,0.8)',
                                                titleColor: '#fff',
                                                bodyColor: '#fff'
                                            }
                                        }
                                    }
                                });
                            }

                            // Top users chart (line)
                            const topUsersCanvas = document.getElementById('topUsersChart');
                            if (topUsersCanvas) {
                                new Chart(topUsersCanvas.getContext('2d'), {
                                    type: 'line', // ganti dari 'bar' ke 'line'
                                    data: {
                                        labels: data.top_users_labels || [],
                                        datasets: [{
                                            label: 'Frekuensi Sewa',
                                            data: data.top_users_data || [],
                                            backgroundColor: 'rgba(59, 130, 246, 0.2)',
                                            borderColor: 'rgb(59, 130, 246)',
                                            borderWidth: 2,
                                            fill: true,
                                            tension: 0.3
                                        }]
                                    },
                                    options: {
                                        responsive: true,
                                        maintainAspectRatio: false,
                                        scales: {
                                            y: { beginAtZero: true }
                                        }
                                    }
                                });
                            }
                        }
                    });
                })
                .catch(error => {
                    Swal.fire({
                        title: '⚠️ Terjadi Kesalahan',
                        text: 'Tidak dapat mengambil data rekapan. Silakan coba lagi.',
                        icon: 'error',
                        confirmButtonText: 'OK'
                    });
                });
        }

        function updateWaktuIndonesia() {
            const now = new Date();
            // Format: hari bulan tahun, jam:menit:detik
            const hari = ['Minggu', 'Senin', 'Selasa', 'Rabu', 'Kamis', 'Jumat', 'Sabtu'][now.getDay()];
            const bulan = [
                'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni',
                'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'
            ][now.getMonth()];
            const tanggal = now.getDate();
            const tahun = now.getFullYear();
            const jam = String(now.getHours()).padStart(2, '0');
            const menit = String(now.getMinutes()).padStart(2, '0');
            const detik = String(now.getSeconds()).padStart(2, '0');
            const waktuStr = `${hari}, ${tanggal} ${bulan} ${tahun}, ${jam}:${menit}:${detik} WIB`;
            document.getElementById('waktu-indonesia').textContent = waktuStr;
        }
        setInterval(updateWaktuIndonesia, 1000);
        document.addEventListener('DOMContentLoaded', updateWaktuIndonesia);

        // Add this to your existing JavaScript
        document.addEventListener('DOMContentLoaded', function () {
            // --- Drag & drop waiting list ke bilik (room-users-table) ---
            const waitingDraggables = document.querySelectorAll('table tbody tr[data-waiting-id]');
            const roomDropZones = document.querySelectorAll('.room-users-table');

            waitingDraggables.forEach(draggable => {
                draggable.addEventListener('dragstart', handleWaitingDragStart);
                draggable.addEventListener('dragend', handleWaitingDragEnd);
            });
            roomDropZones.forEach(zone => {
                zone.addEventListener('dragover', handleWaitingDragOver);
                zone.addEventListener('dragleave', handleWaitingDragLeave);
                zone.addEventListener('drop', handleWaitingDrop);
            });

            // --- Drag & drop pengguna bilik antar bilik ---
            const userDraggables = document.querySelectorAll('.room-users-table tbody tr[data-user-id]');
            const userDropZones = document.querySelectorAll('.room-users-table');

            userDraggables.forEach(draggable => {
                draggable.addEventListener('dragstart', handleUserDragStart);
                draggable.addEventListener('dragend', handleUserDragEnd);
            });
            userDropZones.forEach(zone => {
                zone.addEventListener('dragover', handleUserDragOver);
                zone.addEventListener('dragleave', handleUserDragLeave);
                zone.addEventListener('drop', handleUserDrop);
            });
        });

        // === DRAG & DROP WAITING LIST KE BILIK ===
        function handleWaitingDragStart(e) {
            const el = e.currentTarget;
            // Cari status dari kolom keterangan (td ke-5, index 4)
            const tds = el.querySelectorAll('td');
            if (tds.length >= 5) {
                const status = tds[4].textContent.trim().toLowerCase();
                if (!status.includes('done')) {
                    e.preventDefault(); // Cegah drag
                    Swal.fire({
                        title: 'Tidak dapat drag',
                        text: 'User masih dalam status menunggu. Tunggu sampai status menjadi Done.',
                        icon: 'warning'
                    });
                    return;
                }
            }
            el.classList.add('ghost');
            e.dataTransfer.setData('text/plain', JSON.stringify({
                waitingId: el.dataset.waitingId,
                userName: el.dataset.userName,
                currentRoom: el.dataset.currentRoom
            }));
            startAutoScroll();
        }
        function handleWaitingDragEnd(e) {
            const el = e.currentTarget;
            el.classList.remove('ghost');
            if (autoScrollInterval) {
                clearInterval(autoScrollInterval); // <-- Tambahkan ini
                autoScrollInterval = null;
            }
        }
        function handleWaitingDragOver(e) {
            e.preventDefault();
            e.currentTarget.classList.add('drag-over');
        }
        function handleWaitingDragLeave(e) {
            e.currentTarget.classList.remove('drag-over');
        }
        function handleWaitingDrop(e) {
            e.preventDefault();
            e.currentTarget.classList.remove('drag-over');
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            const newRoomId = e.currentTarget.dataset.roomId;

            // Jangan pindahkan ke room yang sama
            if (String(data.currentRoom) === String(newRoomId)) {
                Swal.fire({
                    title: 'Tidak dapat memindahkan',
                    text: 'User sudah berada dalam waiting list bilik ini',
                    icon: 'warning'
                });
                return;
            }

            // Cari elemen tr waiting list yang di-drag
            const row = document.querySelector(`tr[data-waiting-id="${data.waitingId}"]`);
            let status = null;
            if (row) {
                // Kolom status ada di td ke-5 (index 4) setelah penambahan kolom No
                const tds = row.querySelectorAll('td');
                if (tds.length >= 5) {
                    status = tds[4].textContent.trim().toLowerCase();
                }
            }

            // Tambahkan pengecekan: Jika status bukan 'done', cegah drag
            if (!status || !status.includes('done')) {
                Swal.fire({
                    title: 'Tidak dapat memindahkan',
                    text: 'User masih dalam status menunggu. Tunggu sampai status menjadi Done.',
                    icon: 'warning'
                });
                return;
            }

            Swal.fire({
                title: 'Pindahkan User dari Waiting List',
                text: `Apakah Anda yakin ingin memindahkan ${data.userName} ke bilik ini?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    // Jika status done, langsung masukkan ke room_users
                    submitForm({
                        action: 'move_to_room',
                        waiting_id: data.waitingId,
                        new_room_id: newRoomId
                    });
                }
            });
        }


        // === DRAG & DROP PENGGUNA BILIK ANTAR BILIK ===
        function handleUserDragStart(e) {
            const el = e.currentTarget;
            el.classList.add('ghost');
            e.dataTransfer.setData('text/plain', JSON.stringify({
                userId: el.dataset.userId,
                userName: el.dataset.userName,
                currentRoom: el.dataset.currentRoom
            }));
            startAutoScroll(); // <-- Tambahkan ini
        }
        function handleUserDragEnd(e) {
            const el = e.currentTarget;
            el.classList.remove('ghost');
            if (autoScrollInterval) {
                clearInterval(autoScrollInterval); // <-- Tambahkan ini
                autoScrollInterval = null;
            }
        }
        function handleUserDragOver(e) {
            e.preventDefault();
            e.currentTarget.classList.add('drag-over');
        }
        function handleUserDragLeave(e) {
            e.currentTarget.classList.remove('drag-over');
        }
        function handleUserDrop(e) {
            e.preventDefault();
            e.currentTarget.classList.remove('drag-over');
            const data = JSON.parse(e.dataTransfer.getData('text/plain'));
            const newRoomId = e.currentTarget.dataset.roomId;

            // Tambahan: Jika tidak ada userId, JANGAN proses (berarti ini drag waiting list)
            if (!data.userId) return;

            // Jangan pindahkan ke room yang sama
            if (String(data.currentRoom) === String(newRoomId)) {
                Swal.fire({
                    title: 'Tidak dapat memindahkan',
                    text: 'User sudah berada di bilik ini',
                    icon: 'warning'
                });
                return;
            }

            Swal.fire({
                title: 'Pindahkan User',
                text: `Pindahkan ${data.userName} ke bilik ini?`,
                icon: 'question',
                showCancelButton: true,
                confirmButtonText: 'Ya',
                cancelButtonText: 'Batal'
            }).then((result) => {
                if (result.isConfirmed) {
                    submitForm({
                        action: 'move_user_room',
                        user_id: data.userId,
                        new_room_id: newRoomId
                    });
                }
            });
        }

        // global mouse position used by auto-scroll
        let lastKnownMousePosition = { x: 0, y: 0 };

        // update mouse position also during drag (some browsers don't fire mousemove continuously)
        document.addEventListener('dragover', (e) => {
            lastKnownMousePosition.x = e.clientX;
            lastKnownMousePosition.y = e.clientY;
        });

        let autoScrollInterval = null;
        const SCROLL_SPEED = 12; // px per tick
        const SCROLL_THRESHOLD = 80; // px from top/bottom to start auto-scroll
        const SCROLL_TICK = 16; // ms

        function startAutoScroll() {
            if (autoScrollInterval) {
                clearInterval(autoScrollInterval);
                autoScrollInterval = null;
            }

            const upIndicator = document.getElementById('scrollUpIndicator');
            const downIndicator = document.getElementById('scrollDownIndicator');

            // Majukan threshold supaya tidak harus benar-benar mentok pojok
            const SCROLL_THRESHOLD_X = 180; // px dari kiri/kanan (lebih besar dari sebelumnya)

            autoScrollInterval = setInterval(() => {
                const x = lastKnownMousePosition.x;
                const vw = window.innerWidth;

                upIndicator.style.display = 'none';
                downIndicator.style.display = 'none';

                // Jika mouse di kanan (dalam 180px dari kanan) → scroll ke atas
                if (x >= vw - SCROLL_THRESHOLD_X) {
                    window.scrollBy({ top: -SCROLL_SPEED, left: 0, behavior: 'auto' });
                    upIndicator.style.display = 'flex';
                }
                // Jika mouse di kiri (dalam 180px dari kiri) → scroll ke bawah
                else if (x <= SCROLL_THRESHOLD_X) {
                    window.scrollBy({ top: SCROLL_SPEED, left: 0, behavior: 'auto' });
                    downIndicator.style.display = 'flex';
                }
                // Tidak di kiri/kanan, indikator hilang
            }, SCROLL_TICK);
        }

        function autoMarkWaitingDone() {
            // Loop semua baris waiting list
            document.querySelectorAll('table tbody tr').forEach(function (row) {
                // Cari kolom estimasi bilik kosong (td ke-5, index 4)
                const tds = row.querySelectorAll('td');
                if (tds.length < 7) return; // skip jika bukan baris data

                // Kolom estimasi bilik kosong
                const estimasiCell = tds[4];
                const statusCell = tds[3];
                const waitingId = row.dataset.waitingId;

                // Hanya proses jika ada waitingId dan status masih "Menunggu"
                if (
                    waitingId &&
                    statusCell &&
                    statusCell.textContent.trim().toLowerCase().includes('menunggu')
                ) {
                    // Jika estimasi sudah 00:00:00 (atau 0)
                    if (
                        estimasiCell.textContent.trim() === '00:00:00' ||
                        estimasiCell.textContent.trim() === '0'
                    ) {
                        // Otomatis ubah status ke done via AJAX tanpa popup
                        submitForm({ action: 'mark_waiting_done', waiting_id: waitingId }, true);
                    }
                }
            });
        }

        // Panggil autoMarkWaitingDone setiap detik setelah updateCountdowns
        setInterval(autoMarkWaitingDone, 1000);
    </script>
</body>

</html>