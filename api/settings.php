<?php
/**
 * Client Reach AI - Admin Settings & Profile API
 * Handles Admin profile updates, password changes, and system settings.
 */
require_once __DIR__ . '/../config.php';

$currentUser = getAuthUser($pdo, 'admin');

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$input = !empty($jsonInput) ? $jsonInput : $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? 'get_profile');

// 1. GET ADMIN PROFILE
if ($action === 'get_profile') {
    $stmt = $pdo->prepare("SELECT id, role, name, email, phone, status, avatar, last_login, created_at FROM users WHERE id = ?");
    $stmt->execute([$currentUser['id']]);
    $profile = $stmt->fetch();

    jsonResponse(['success' => true, 'profile' => $profile]);
}

// 2. UPDATE ADMIN LOGIN INFO & PROFILE
if ($action === 'update_profile') {
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $phone = trim($input['phone'] ?? '');

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama lengkap admin wajib diisi.'], 400);
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Format email login tidak valid.'], 400);
    }

    // Check email conflict
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->execute([$email, $currentUser['id']]);
    if ($chk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Email login sudah digunakan oleh akun lain.'], 409);
    }

    try {
        $stmt = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$name, $email, $phone, $currentUser['id']]);

        $upStmt = $pdo->prepare("SELECT id, role, name, email, phone, status, avatar FROM users WHERE id = ?");
        $upStmt->execute([$currentUser['id']]);
        $updated = $upStmt->fetch();

        jsonResponse([
            'success' => true,
            'message' => 'Informasi profil dan login admin berhasil disimpan!',
            'user' => $updated
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Gagal menyimpan profil: ' . $e->getMessage()], 500);
    }
}

// 3. CHANGE ADMIN PASSWORD
if ($action === 'change_password') {
    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (empty($currentPassword)) {
        jsonResponse(['success' => false, 'message' => 'Password saat ini wajib dimasukkan.'], 400);
    }
    if (strlen($newPassword) < 6) {
        jsonResponse(['success' => false, 'message' => 'Password baru minimal 6 karakter.'], 400);
    }
    if ($newPassword !== $confirmPassword) {
        jsonResponse(['success' => false, 'message' => 'Konfirmasi password baru tidak cocok.'], 400);
    }

    // Verify current password
    $pwdStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
    $pwdStmt->execute([$currentUser['id']]);
    $storedHash = $pwdStmt->fetchColumn();

    if (!password_verify($currentPassword, $storedHash)) {
        jsonResponse(['success' => false, 'message' => 'Password saat ini yang Anda masukkan salah.'], 400);
    }

    try {
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
        $stmt->execute([$newHash, $currentUser['id']]);

        jsonResponse([
            'success' => true,
            'message' => 'Password admin berhasil diubah! Gunakan password baru untuk login selanjutnya.'
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Gagal mengubah password: ' . $e->getMessage()], 500);
    }
}

// 4. GET SYSTEM SETTINGS
if ($action === 'get_system_settings') {
    $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
    $rawSettings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $gemini = $rawSettings['gemini_api_key'] ?? (defined('GEMINI_API_KEY') ? GEMINI_API_KEY : '');
    $gmaps = $rawSettings['gmaps_api_key'] ?? (defined('GOOGLE_MAPS_API_KEY') ? GOOGLE_MAPS_API_KEY : '');
    $smtpHost = $rawSettings['smtp_host'] ?? '';
    $smtpPort = $rawSettings['smtp_port'] ?? '587';
    $smtpUser = $rawSettings['smtp_user'] ?? '';
    $smtpFrom = $rawSettings['smtp_from'] ?? 'no-reply@cliento.id';

    jsonResponse([
        'success' => true,
        'settings' => [
            'gemini_api_key' => $gemini,
            'gmaps_api_key' => $gmaps,
            'smtp_host' => $smtpHost,
            'smtp_port' => $smtpPort,
            'smtp_user' => $smtpUser,
            'smtp_from' => $smtpFrom,
            'backend_url' => $rawSettings['backend_url'] ?? ''
        ]
    ]);
}

// 5. SAVE SYSTEM SETTINGS
if ($action === 'save_system_settings') {
    $settings = [
        'gemini_api_key' => trim($input['gemini_api_key'] ?? ''),
        'gmaps_api_key' => trim($input['gmaps_api_key'] ?? ''),
        'smtp_host' => trim($input['smtp_host'] ?? ''),
        'smtp_port' => trim($input['smtp_port'] ?? '587'),
        'smtp_user' => trim($input['smtp_user'] ?? ''),
        'smtp_from' => trim($input['smtp_from'] ?? 'no-reply@cliento.id'),
        'backend_url' => trim($input['backend_url'] ?? '')
    ];

    if (!empty($input['smtp_pass'])) {
        $settings['smtp_pass'] = $input['smtp_pass'];
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?, ?, CURRENT_TIMESTAMP)
                               ON CONFLICT(setting_key) DO UPDATE SET setting_value = excluded.setting_value, updated_at = CURRENT_TIMESTAMP");
        foreach ($settings as $k => $v) {
            $stmt->execute([$k, $v]);
        }

        jsonResponse([
            'success' => true,
            'message' => 'Pengaturan sistem & konfigurasi API berhasil disimpan!'
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Gagal menyimpan pengaturan: ' . $e->getMessage()], 500);
    }
}

jsonResponse(['success' => false, 'message' => 'Aksi pengaturan tidak valid.'], 400);
