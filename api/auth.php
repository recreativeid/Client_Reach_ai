<?php
/**
 * Client Reach AI - Authentication API
 * Supports Admin & Customer Login, Registration, Email OTP Verification, and Profile Settings.
 */
require_once __DIR__ . '/../config.php';

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$input = !empty($jsonInput) ? $jsonInput : $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? 'me');

// 1. LOGIN (Admin & Customer)
if ($action === 'login') {
    $email = strtolower(trim($input['email'] ?? ''));
    $password = $input['password'] ?? '';

    if (empty($email) || empty($password)) {
        jsonResponse(['success' => false, 'message' => 'Email dan password wajib diisi.'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['success' => false, 'message' => 'Email atau kata sandi tidak cocok.'], 401);
    }

    if ($user['status'] !== 'active') {
        jsonResponse(['success' => false, 'message' => 'Akun Anda dinonaktifkan. Silakan hubungi administrator.'], 403);
    }

    // If customer and email is not verified, require OTP verification
    if ($user['role'] === 'customer' && (int)$user['is_verified'] !== 1) {
        // Generate new OTP
        $otpCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 mins

        $otpStmt = $pdo->prepare("INSERT INTO email_otps (user_id, email, otp_code, type, expires_at) VALUES (?, ?, ?, 'register', ?)");
        $otpStmt->execute([$user['id'], $email, $otpCode, $expiresAt]);

        sendEmailOtp($email, $otpCode, $user['name']);

        jsonResponse([
            'success' => false,
            'otp_required' => true,
            'email' => $email,
            'message' => 'Akun Anda belum diverifikasi. Kode OTP baru telah dikirimkan ke email Anda.',
            'otp_preview' => $otpCode
        ], 200);
    }

    // Generate login token
    $token = bin2hex(random_bytes(32));
    $upStmt = $pdo->prepare("UPDATE users SET token = ?, last_login = CURRENT_TIMESTAMP WHERE id = ?");
    $upStmt->execute([$token, $user['id']]);

    unset($user['password_hash']);
    $user['token'] = $token;

    jsonResponse([
        'success' => true,
        'message' => 'Login berhasil! Selamat datang kembali, ' . $user['name'],
        'token' => $token,
        'user' => $user
    ]);
}

// 2. REGISTER (Customer)
if ($action === 'register') {
    $name = trim($input['name'] ?? '');
    $email = strtolower(trim($input['email'] ?? ''));
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama lengkap wajib diisi.'], 400);
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Format email tidak valid.'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['success' => false, 'message' => 'Kata sandi minimal 6 karakter.'], 400);
    }

    // Check existing email
    $checkStmt = $pdo->prepare("SELECT id, is_verified FROM users WHERE email = ?");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch();

    $userId = null;
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
        if ((int)$existing['is_verified'] === 1) {
            jsonResponse(['success' => false, 'message' => 'Email sudah terdaftar dan aktif. Silakan langsung masuk.'], 409);
        }
        // Update user data & password
        $userId = $existing['id'];
        $upStmt = $pdo->prepare("UPDATE users SET name = ?, phone = ?, password_hash = ? WHERE id = ?");
        $upStmt->execute([$name, $phone, $passwordHash, $userId]);
    } else {
        $inStmt = $pdo->prepare("INSERT INTO users (role, name, email, phone, password_hash, status, is_verified) VALUES ('customer', ?, ?, ?, ?, 'active', 0)");
        $inStmt->execute([$name, $email, $phone, $passwordHash]);
        $userId = (int)$pdo->lastInsertId();
    }

    // Generate 6-digit OTP code
    $otpCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 mins

    $otpStmt = $pdo->prepare("INSERT INTO email_otps (user_id, email, otp_code, type, expires_at) VALUES (?, ?, ?, 'register', ?)");
    $otpStmt->execute([$userId, $email, $otpCode, $expiresAt]);

    $emailSent = sendEmailOtp($email, $otpCode, $name);

    jsonResponse([
        'success' => true,
        'message' => 'Pendaftaran berhasil! Kode verifikasi OTP telah dikirimkan ke email ' . $email . '.',
        'email' => $email,
        'otp_preview' => $otpCode, // Included for seamless testing in local / offline dev environments
        'email_sent' => $emailSent
    ]);
}

// 3. VERIFY OTP
if ($action === 'verify_otp') {
    $email = strtolower(trim($input['email'] ?? ''));
    $otpCode = trim($input['otp_code'] ?? '');

    if (empty($email) || empty($otpCode)) {
        jsonResponse(['success' => false, 'message' => 'Email dan kode OTP wajib diisi.'], 400);
    }

    // Check OTP
    $stmt = $pdo->prepare("SELECT id, user_id FROM email_otps WHERE email = ? AND otp_code = ? AND is_used = 0 AND expires_at >= CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, $otpCode]);
    $otpRow = $stmt->fetch();

    if (!$otpRow) {
        jsonResponse(['success' => false, 'message' => 'Kode OTP tidak valid atau telah kadaluarsa. Silakan kirim ulang kode baru.'], 400);
    }

    // Mark OTP as used
    $pdo->prepare("UPDATE email_otps SET is_used = 1 WHERE id = ?")->execute([$otpRow['id']]);

    // Update user as verified and generate token
    $token = bin2hex(random_bytes(32));
    $upUser = $pdo->prepare("UPDATE users SET is_verified = 1, token = ?, last_login = CURRENT_TIMESTAMP WHERE email = ?");
    $upUser->execute([$token, $email]);

    // Fetch user info
    $userStmt = $pdo->prepare("SELECT id, role, name, email, phone, status, is_verified, avatar FROM users WHERE email = ?");
    $userStmt->execute([$email]);
    $user = $userStmt->fetch();
    $user['token'] = $token;

    jsonResponse([
        'success' => true,
        'message' => 'Verifikasi email berhasil! Selamat datang di Cliento.',
        'token' => $token,
        'user' => $user
    ]);
}

// 4. RESEND OTP
if ($action === 'resend_otp') {
    $email = strtolower(trim($input['email'] ?? ''));

    if (empty($email)) {
        jsonResponse(['success' => false, 'message' => 'Email wajib diisi.'], 400);
    }

    $uStmt = $pdo->prepare("SELECT id, name FROM users WHERE email = ?");
    $uStmt->execute([$email]);
    $user = $uStmt->fetch();

    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Email tidak ditemukan.'], 404);
    }

    // Invalidate previous unused OTPs
    $pdo->prepare("UPDATE email_otps SET is_used = 1 WHERE email = ? AND is_used = 0")->execute([$email]);

    // Generate new OTP
    $otpCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 900);

    $pdo->prepare("INSERT INTO email_otps (user_id, email, otp_code, type, expires_at) VALUES (?, ?, ?, 'register', ?)")
        ->execute([$user['id'], $email, $otpCode, $expiresAt]);

    $emailSent = sendEmailOtp($email, $otpCode, $user['name']);

    jsonResponse([
        'success' => true,
        'message' => 'Kode OTP baru berhasil dikirim ke ' . $email,
        'otp_preview' => $otpCode,
        'email_sent' => $emailSent
    ]);
}

// 5. CURRENT USER PROFILE (ME)
if ($action === 'me') {
    $user = getAuthUser($pdo, null);
    if (!$user) {
        jsonResponse(['success' => false, 'authenticated' => false], 200);
    }
    jsonResponse(['success' => true, 'authenticated' => true, 'user' => $user]);
}

// 6. UPDATE PROFILE (Admin or Customer)
if ($action === 'update_profile') {
    $user = getAuthUser($pdo, null);
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Akses ditolak: Silakan login terlebih dahulu'], 401);
    }

    $name = trim($input['name'] ?? $user['name']);
    $email = strtolower(trim($input['email'] ?? $user['email']));
    $phone = trim($input['phone'] ?? $user['phone']);
    $avatar = trim($input['avatar'] ?? ($user['avatar'] ?? ''));

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama tidak boleh kosong.'], 400);
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Format email tidak valid.'], 400);
    }

    // If changing email, check uniqueness
    if ($email !== strtolower($user['email'])) {
        $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
        $chk->execute([$email, $user['id']]);
        if ($chk->fetch()) {
            jsonResponse(['success' => false, 'message' => 'Email sudah digunakan oleh akun lain.'], 409);
        }
    }

    // Handle password change if requested
    $currentPassword = $input['current_password'] ?? '';
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    $newPasswordHash = null;
    if (!empty($newPassword)) {
        if (empty($currentPassword)) {
            jsonResponse(['success' => false, 'message' => 'Password saat ini wajib diisi untuk verifikasi keamanan.'], 400);
        }

        // Verify current password from database
        $pwdStmt = $pdo->prepare("SELECT password_hash FROM users WHERE id = ?");
        $pwdStmt->execute([$user['id']]);
        $currHash = $pwdStmt->fetchColumn();

        if (!password_verify($currentPassword, $currHash)) {
            jsonResponse(['success' => false, 'message' => 'Password saat ini yang Anda masukkan salah.'], 400);
        }

        if (strlen($newPassword) < 6) {
            jsonResponse(['success' => false, 'message' => 'Password baru minimal 6 karakter.'], 400);
        }

        if (!empty($confirmPassword) && $newPassword !== $confirmPassword) {
            jsonResponse(['success' => false, 'message' => 'Konfirmasi password baru tidak cocok.'], 400);
        }

        $newPasswordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    }

    try {
        if ($newPasswordHash) {
            $up = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, avatar = ?, password_hash = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $up->execute([$name, $email, $phone, $avatar, $newPasswordHash, $user['id']]);
        } else {
            $up = $pdo->prepare("UPDATE users SET name = ?, email = ?, phone = ?, avatar = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
            $up->execute([$name, $email, $phone, $avatar, $user['id']]);
        }

        $stmt = $pdo->prepare("SELECT id, role, name, email, phone, status, is_verified, avatar FROM users WHERE id = ?");
        $stmt->execute([$user['id']]);
        $updatedUser = $stmt->fetch();

        jsonResponse([
            'success' => true,
            'message' => 'Profil dan informasi login berhasil diperbarui!',
            'user' => $updatedUser
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Gagal memperbarui profil: ' . $e->getMessage()], 500);
    }
}

// 7. LOGOUT
if ($action === 'logout') {
    $token = getBearerToken();
    if ($token) {
        $pdo->prepare("UPDATE users SET token = '' WHERE token = ?")->execute([$token]);
    }
    jsonResponse(['success' => true, 'message' => 'Logout berhasil.']);
}

jsonResponse(['success' => false, 'message' => 'Aksi autentikasi tidak valid.'], 400);
