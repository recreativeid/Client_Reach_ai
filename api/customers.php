<?php
/**
 * Client Reach AI - Customer Management API (Admin Only)
 * CRUD operations for Customer Database.
 */
require_once __DIR__ . '/../config.php';

// Verify Admin Access
$currentUser = getAuthUser($pdo, 'admin');

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$input = !empty($jsonInput) ? $jsonInput : $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

// 1. LIST CUSTOMERS
if ($action === 'list') {
    $search = trim($_GET['q'] ?? '');
    $status = trim($_GET['status'] ?? '');
    $verified = isset($_GET['verified']) && $_GET['verified'] !== '' ? (int)$_GET['verified'] : null;

    $sql = "SELECT u.id, u.role, u.name, u.email, u.phone, u.status, u.is_verified, u.last_login, u.created_at,
                   (SELECT COUNT(*) FROM archives a WHERE a.user_id = u.id) as total_archives,
                   (SELECT COUNT(*) FROM scraping_history s WHERE s.user_id = u.id) as total_scraping
            FROM users u
            WHERE u.role = 'customer'";
    $params = [];

    if (!empty($search)) {
        $sql .= " AND (u.name LIKE ? OR u.email LIKE ? OR u.phone LIKE ?)";
        $term = "%{$search}%";
        $params[] = $term;
        $params[] = $term;
        $params[] = $term;
    }

    if (!empty($status) && in_array($status, ['active', 'suspended'])) {
        $sql .= " AND u.status = ?";
        $params[] = $status;
    }

    if ($verified !== null) {
        $sql .= " AND u.is_verified = ?";
        $params[] = $verified;
    }

    $sql .= " ORDER BY u.created_at DESC";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $customers = $stmt->fetchAll();

    jsonResponse([
        'success' => true,
        'total' => count($customers),
        'customers' => $customers
    ]);
}

// 2. GET SINGLE CUSTOMER
if ($action === 'get') {
    $id = (int)($_GET['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'ID Customer tidak valid.'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, role, name, email, phone, status, is_verified, avatar, last_login, created_at, updated_at FROM users WHERE id = ? AND role = 'customer'");
    $stmt->execute([$id]);
    $customer = $stmt->fetch();

    if (!$customer) {
        jsonResponse(['success' => false, 'message' => 'Customer tidak ditemukan.'], 404);
    }

    // Get recent scraping history for this customer
    $histStmt = $pdo->prepare("SELECT id, query_name, method, location_name, total_found, created_at FROM scraping_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
    $histStmt->execute([$id]);
    $customer['recent_history'] = $histStmt->fetchAll();

    // Get archive collections count
    $arcStmt = $pdo->prepare("SELECT COUNT(*) FROM archives WHERE user_id = ?");
    $arcStmt->execute([$id]);
    $customer['total_archives'] = (int)$arcStmt->fetchColumn();

    jsonResponse(['success' => true, 'customer' => $customer]);
}

// 3. CREATE CUSTOMER
if ($action === 'create') {
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';
    $status = in_array($input['status'] ?? '', ['active', 'suspended']) ? $input['status'] : 'active';
    $isVerified = isset($input['is_verified']) ? (int)$input['is_verified'] : 1;

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama customer wajib diisi.'], 400);
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Email customer tidak valid.'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['success' => false, 'message' => 'Password minimal 6 karakter.'], 400);
    }

    // Check duplicate email
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ?");
    $chk->execute([$email]);
    if ($chk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Email sudah terdaftar di sistem.'], 409);
    }

    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("INSERT INTO users (role, name, email, phone, password_hash, status, is_verified) VALUES ('customer', ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$name, $email, $phone, $hash, $status, $isVerified]);
        $newId = (int)$pdo->lastInsertId();

        jsonResponse([
            'success' => true,
            'message' => 'Akun customer baru berhasil dibuat!',
            'customer_id' => $newId
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Gagal membuat customer: ' . $e->getMessage()], 500);
    }
}

// 4. UPDATE CUSTOMER
if ($action === 'update') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'ID Customer tidak valid.'], 400);
    }

    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $phone = trim($input['phone'] ?? '');
    $status = in_array($input['status'] ?? '', ['active', 'suspended']) ? $input['status'] : 'active';
    $isVerified = isset($input['is_verified']) ? (int)$input['is_verified'] : 1;
    $password = $input['password'] ?? '';

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama customer wajib diisi.'], 400);
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Email customer tidak valid.'], 400);
    }

    // Check email collision
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->execute([$email, $id]);
    if ($chk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Email sudah digunakan oleh akun lain.'], 409);
    }

    try {
        if (!empty($password)) {
            if (strlen($password) < 6) {
                jsonResponse(['success' => false, 'message' => 'Password minimal 6 karakter.'], 400);
            }
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, status = ?, is_verified = ?, password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND role = 'customer'");
            $stmt->execute([$name, $email, $phone, $status, $isVerified, $hash, $id]);
        } else {
            $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, status = ?, is_verified = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ? AND role = 'customer'");
            $stmt->execute([$name, $email, $phone, $status, $isVerified, $id]);
        }

        jsonResponse([
            'success' => true,
            'message' => 'Data customer berhasil diperbarui!'
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Gagal memperbarui data: ' . $e->getMessage()], 500);
    }
}

// 5. DELETE CUSTOMER
if ($action === 'delete') {
    $id = (int)($input['id'] ?? 0);
    if ($id <= 0) {
        jsonResponse(['success' => false, 'message' => 'ID Customer tidak valid.'], 400);
    }

    // Ensure target is a customer, not admin
    $chk = $pdo->prepare("SELECT role, name FROM users WHERE id = ?");
    $chk->execute([$id]);
    $target = $chk->fetch();

    if (!$target) {
        jsonResponse(['success' => false, 'message' => 'Customer tidak ditemukan.'], 404);
    }

    if ($target['role'] === 'admin') {
        jsonResponse(['success' => false, 'message' => 'Akun Administrator tidak dapat dihapus.'], 403);
    }

    try {
        $del = $pdo->prepare("DELETE FROM users WHERE id = ?");
        $del->execute([$id]);

        jsonResponse([
            'success' => true,
            'message' => 'Customer ' . $target['name'] . ' berhasil dihapus dari database.'
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Gagal menghapus customer: ' . $e->getMessage()], 500);
    }
}

// 6. CUSTOMER STATS
if ($action === 'stats') {
    $totalCust = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer'")->fetchColumn();
    $activeCust = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND status = 'active'")->fetchColumn();
    $verifiedCust = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND is_verified = 1")->fetchColumn();
    $pendingOtp = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role = 'customer' AND is_verified = 0")->fetchColumn();

    jsonResponse([
        'success' => true,
        'stats' => [
            'total' => $totalCust,
            'active' => $activeCust,
            'verified' => $verifiedCust,
            'pending_otp' => $pendingOtp
        ]
    ]);
}

jsonResponse(['success' => false, 'message' => 'Aksi customer tidak dikenali.'], 400);
