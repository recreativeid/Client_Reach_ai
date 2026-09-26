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

// 1. LOGIN (Bisa pakai Email atau Username - Admin & Customer Terpisah)
if ($action === 'login') {
    $account = strtolower(trim($input['account'] ?? ($input['email'] ?? ($input['username'] ?? ''))));
    $password = $input['password'] ?? '';
    $portal = trim($input['portal'] ?? ''); // 'admin' or 'user'

    if (empty($account) || empty($password)) {
        jsonResponse(['success' => false, 'message' => 'Username/email dan kata sandi wajib diisi.'], 400);
    }

    $stmt = $pdo->prepare("SELECT * FROM users WHERE (LOWER(email) = ? OR LOWER(username) = ?) LIMIT 1");
    $stmt->execute([$account, $account]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password_hash'])) {
        jsonResponse(['success' => false, 'message' => 'Username/email atau kata sandi tidak cocok.'], 401);
    }

    if ($user['status'] !== 'active') {
        jsonResponse(['success' => false, 'message' => 'Akun Anda dinonaktifkan. Silakan hubungi administrator.'], 403);
    }

    // Strict Portal Validation: Enforce Admin vs User separation (Beda Link Halaman Login)
    if ($portal === 'admin' && $user['role'] !== 'admin') {
        jsonResponse([
            'success' => false,
            'message' => 'Akses ditolak: Akun ini adalah akun Pengguna/Customer. Silakan masuk melalui halaman login pengguna (index.html).'
        ], 403);
    }
    if ($portal === 'user' && $user['role'] === 'admin') {
        jsonResponse([
            'success' => false,
            'message' => 'Akun ini terdaftar sebagai Administrator. Silakan masuk melalui halaman khusus login Admin (admin.html).'
        ], 403);
    }

    // If customer and email is not verified, require OTP verification
    if ($user['role'] === 'customer' && (int)$user['is_verified'] !== 1) {
        // Generate new OTP
        $otpCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
        $expiresAt = date('Y-m-d H:i:s', time() + 900); // 15 mins

        $otpStmt = $pdo->prepare("INSERT INTO email_otps (user_id, email, otp_code, type, expires_at) VALUES (?, ?, ?, 'register', ?)");
        $otpStmt->execute([$user['id'], $user['email'], $otpCode, $expiresAt]);

        sendEmailOtp($user['email'], $otpCode, $user['name']);

        jsonResponse([
            'success' => false,
            'otp_required' => true,
            'email' => $user['email'],
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

// 2. REGISTER (User Baru - Wajib isi Username & Email)
if ($action === 'register') {
    $name = trim($input['name'] ?? '');
    $username = strtolower(trim($input['username'] ?? ''));
    $email = strtolower(trim($input['email'] ?? ''));
    $phone = trim($input['phone'] ?? '');
    $password = $input['password'] ?? '';

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama lengkap wajib diisi.'], 400);
    }
    if (empty($username)) {
        jsonResponse(['success' => false, 'message' => 'Username wajib diisi untuk login akun Anda.'], 400);
    }
    if (!preg_match('/^[a-z0-9_]{3,30}$/', $username)) {
        jsonResponse(['success' => false, 'message' => 'Username hanya boleh berupa huruf kecil, angka, atau garis bawah (_) minimal 3-30 karakter.'], 400);
    }
    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Alamat email wajib diisi supaya kode OTP dapat dikirimkan jika Anda lupa akun atau kata sandi.'], 400);
    }
    if (strlen($password) < 6) {
        jsonResponse(['success' => false, 'message' => 'Kata sandi minimal 6 karakter.'], 400);
    }

    // Check existing username
    $checkUser = $pdo->prepare("SELECT id FROM users WHERE LOWER(username) = ?");
    $checkUser->execute([$username]);
    if ($checkUser->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Username "' . $username . '" sudah digunakan. Silakan gunakan username lain.'], 409);
    }

    // Check existing email
    $checkStmt = $pdo->prepare("SELECT id, is_verified FROM users WHERE LOWER(email) = ?");
    $checkStmt->execute([$email]);
    $existing = $checkStmt->fetch();

    $userId = null;
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);

    if ($existing) {
        if ((int)$existing['is_verified'] === 1) {
            jsonResponse(['success' => false, 'message' => 'Email sudah terdaftar dan aktif. Silakan langsung masuk atau gunakan fitur Lupa Username / Email.'], 409);
        }
        // Update user data & password
        $userId = $existing['id'];
        $upStmt = $pdo->prepare("UPDATE users SET username = ?, name = ?, phone = ?, password_hash = ? WHERE id = ?");
        $upStmt->execute([$username, $name, $phone, $passwordHash, $userId]);
    } else {
        $inStmt = $pdo->prepare("INSERT INTO users (role, username, name, email, phone, password_hash, status, is_verified) VALUES ('customer', ?, ?, ?, ?, ?, 'active', 0)");
        $inStmt->execute([$username, $name, $email, $phone, $passwordHash]);
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

    // If changing email, require OTP verification
    if ($email !== strtolower($user['email'])) {
        jsonResponse(['success' => false, 'message' => 'Perubahan alamat email login harus melalui verifikasi kode OTP. Silakan gunakan menu "Ganti Email & Verifikasi OTP".'], 400);
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

// 7. FORGOT PASSWORD (Kirim OTP ke Email untuk Lupa Password - HANYA UNTUK USER)
if ($action === 'forgot_password') {
    $account = strtolower(trim($input['account'] ?? ($input['email'] ?? ($input['username'] ?? ''))));

    if (empty($account)) {
        jsonResponse(['success' => false, 'message' => 'Masukkan alamat email atau username akun Anda.'], 400);
    }

    $stmt = $pdo->prepare("SELECT id, role, username, name, email, status FROM users WHERE (LOWER(email) = ? OR LOWER(username) = ?) LIMIT 1");
    $stmt->execute([$account, $account]);
    $user = $stmt->fetch();

    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Akun dengan email atau username tersebut tidak ditemukan dalam sistem.'], 404);
    }

    // STRICT: Yang bisa lupa kata sandi HANYA akun User/Customer!
    if ($user['role'] === 'admin') {
        jsonResponse([
            'success' => false,
            'message' => 'Akses ditolak: Fitur pemulihan kata sandi hanya tersedia untuk akun User/Customer. Akun Administrator dikelola secara internal oleh Super Administrator sistem demi alasan keamanan.'
        ], 403);
    }

    if ($user['status'] !== 'active') {
        jsonResponse(['success' => false, 'message' => 'Akun Anda dinonaktifkan. Hubungi administrator.'], 403);
    }

    $userEmail = strtolower($user['email']);

    // Invalidate old unused OTPs
    $pdo->prepare("UPDATE email_otps SET is_used = 1 WHERE email = ? AND is_used = 0")->execute([$userEmail]);

    // Generate 6-digit OTP code (15 mins expiry)
    $otpCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 900);

    $ins = $pdo->prepare("INSERT INTO email_otps (user_id, email, otp_code, type, expires_at) VALUES (?, ?, ?, 'reset_password', ?)");
    $ins->execute([$user['id'], $userEmail, $otpCode, $expiresAt]);

    $emailSent = sendEmailOtp($userEmail, $otpCode, $user['name'], 'forgot_password');

    // Masked email for display
    $emailParts = explode('@', $userEmail);
    $uNamePart = $emailParts[0];
    $dPart = $emailParts[1] ?? '';
    $uLen = strlen($uNamePart);
    $maskedName = ($uLen <= 3) ? substr($uNamePart, 0, 1) . '***' : substr($uNamePart, 0, 2) . str_repeat('*', max(3, $uLen - 3)) . substr($uNamePart, -1);
    $maskedEmail = $maskedName . '@' . $dPart;

    jsonResponse([
        'success' => true,
        'message' => 'Kode OTP pemulihan kata sandi telah dikirimkan ke email terdaftar: ' . $maskedEmail,
        'email' => $userEmail,
        'masked_email' => $maskedEmail,
        'username' => $user['username'] ?? '',
        'otp_preview' => $otpCode,
        'email_sent' => $emailSent
    ]);
}

// 8. RESET PASSWORD VIA OTP (HANYA UNTUK USER)
if ($action === 'reset_password_otp') {
    $account = strtolower(trim($input['account'] ?? ($input['email'] ?? ($input['username'] ?? ''))));
    $otpCode = trim($input['otp_code'] ?? '');
    $newPassword = $input['new_password'] ?? '';
    $confirmPassword = $input['confirm_password'] ?? '';

    if (empty($account) || empty($otpCode) || empty($newPassword)) {
        jsonResponse(['success' => false, 'message' => 'Email/username, kode OTP, dan kata sandi baru wajib diisi.'], 400);
    }

    // Pastikan user ada dan bukan admin
    $uCheck = $pdo->prepare("SELECT id, role, email FROM users WHERE (LOWER(email) = ? OR LOWER(username) = ?) LIMIT 1");
    $uCheck->execute([$account, $account]);
    $userRow = $uCheck->fetch();

    if (!$userRow) {
        jsonResponse(['success' => false, 'message' => 'Akun tidak ditemukan.'], 404);
    }

    if ($userRow['role'] === 'admin') {
        jsonResponse(['success' => false, 'message' => 'Akses ditolak: Reset kata sandi tidak diizinkan untuk akun Administrator.'], 403);
    }

    $email = strtolower($userRow['email']);

    if (strlen($newPassword) < 6) {
        jsonResponse(['success' => false, 'message' => 'Kata sandi baru minimal 6 karakter.'], 400);
    }

    if ($newPassword !== $confirmPassword) {
        jsonResponse(['success' => false, 'message' => 'Konfirmasi kata sandi tidak cocok.'], 400);
    }

    // Verify OTP
    $stmt = $pdo->prepare("SELECT id, user_id FROM email_otps WHERE email = ? AND otp_code = ? AND type = 'reset_password' AND is_used = 0 AND expires_at >= CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1");
    $stmt->execute([$email, $otpCode]);
    $otpRow = $stmt->fetch();

    if (!$otpRow) {
        jsonResponse(['success' => false, 'message' => 'Kode OTP pemulihan salah atau telah kadaluarsa. Silakan minta kode baru.'], 400);
    }

    // Mark OTP as used
    $pdo->prepare("UPDATE email_otps SET is_used = 1 WHERE id = ?")->execute([$otpRow['id']]);

    // Update password
    $token = bin2hex(random_bytes(32));
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    $upStmt = $pdo->prepare("UPDATE users SET password_hash = ?, token = ?, is_verified = 1, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $upStmt->execute([$hash, $token, $userRow['id']]);

    $uStmt = $pdo->prepare("SELECT id, role, username, name, email, phone, status, is_verified, avatar FROM users WHERE id = ?");
    $uStmt->execute([$userRow['id']]);
    $user = $uStmt->fetch();
    $user['token'] = $token;

    jsonResponse([
        'success' => true,
        'message' => 'Kata sandi berhasil direset! Anda telah otomatis masuk.',
        'token' => $token,
        'user' => $user
    ]);
}

// 8b. FORGOT USERNAME / EMAIL (Pencarian Akun untuk User/Customer - HANYA UNTUK USER)
if ($action === 'forgot_email' || $action === 'forgot_account') {
    $query = trim($input['query'] ?? ($input['phone'] ?? ''));
    if (empty($query)) {
        jsonResponse(['success' => false, 'message' => 'Silakan masukkan nomor WhatsApp, nama lengkap, atau username akun Anda.'], 400);
    }

    $cleanPhone = preg_replace('/[^0-9]/', '', $query);
    $likeQuery = '%' . strtolower($query) . '%';

    // STRICT: ONLY role = 'customer' (Akun admin tidak pernah diekspos melalui fitur ini)
    if (!empty($cleanPhone) && strlen($cleanPhone) >= 8) {
        $stmt = $pdo->prepare("SELECT id, role, username, name, email, phone FROM users WHERE role = 'customer' AND (REPLACE(REPLACE(phone, '-', ''), ' ', '') LIKE ? OR phone LIKE ?) LIMIT 5");
        $stmt->execute(['%' . $cleanPhone . '%', '%' . $cleanPhone . '%']);
    } else {
        $stmt = $pdo->prepare("SELECT id, role, username, name, email, phone FROM users WHERE role = 'customer' AND (LOWER(name) LIKE ? OR LOWER(username) LIKE ? OR LOWER(email) = ?) LIMIT 5");
        $stmt->execute([$likeQuery, $likeQuery, strtolower($query)]);
    }
    $results = $stmt->fetchAll();

    if (empty($results)) {
        jsonResponse(['success' => false, 'message' => 'Akun User tidak ditemukan. Pastikan nomor WhatsApp, nama, atau username yang dimasukkan sesuai saat mendaftar.'], 404);
    }

    $foundAccounts = [];
    foreach ($results as $u) {
        $emailParts = explode('@', $u['email']);
        $userNamePart = $emailParts[0];
        $domainPart = $emailParts[1] ?? '';
        $len = strlen($userNamePart);
        if ($len <= 3) {
            $maskedName = substr($userNamePart, 0, 1) . '***';
        } else {
            $maskedName = substr($userNamePart, 0, 2) . str_repeat('*', max(3, $len - 3)) . substr($userNamePart, -1);
        }
        $maskedEmail = $maskedName . '@' . $domainPart;

        $phoneDisplay = '';
        if (!empty($u['phone'])) {
            $pLen = strlen($u['phone']);
            $phoneDisplay = substr($u['phone'], 0, 4) . '****' . substr($u['phone'], -3);
        }

        $foundAccounts[] = [
            'name' => $u['name'],
            'username' => $u['username'] ?? '',
            'masked_email' => $maskedEmail,
            'full_email' => $u['email'],
            'phone' => $phoneDisplay
        ];
    }

    jsonResponse([
        'success' => true,
        'message' => 'Akun User berhasil ditemukan!',
        'accounts' => $foundAccounts
    ]);
}

// 9. REQUEST EMAIL CHANGE OTP (Kirim OTP ke email baru sebelum diubah)
if ($action === 'request_email_change_otp') {
    $user = getAuthUser($pdo, null);
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Silakan masuk terlebih dahulu.'], 401);
    }

    $newEmail = strtolower(trim($input['new_email'] ?? ''));

    if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        jsonResponse(['success' => false, 'message' => 'Alamat email baru tidak valid.'], 400);
    }

    if ($newEmail === strtolower($user['email'])) {
        jsonResponse(['success' => false, 'message' => 'Alamat email baru sama dengan email saat ini.'], 400);
    }

    // Check collision
    $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? AND id != ?");
    $chk->execute([$newEmail, $user['id']]);
    if ($chk->fetch()) {
        jsonResponse(['success' => false, 'message' => 'Email tersebut sudah digunakan oleh akun lain.'], 409);
    }

    // Generate OTP for new email
    $otpCode = str_pad((string)random_int(100000, 999999), 6, '0', STR_PAD_LEFT);
    $expiresAt = date('Y-m-d H:i:s', time() + 900);

    $ins = $pdo->prepare("INSERT INTO email_otps (user_id, email, otp_code, type, expires_at) VALUES (?, ?, ?, 'change_email', ?)");
    $ins->execute([$user['id'], $newEmail, $otpCode, $expiresAt]);

    $emailSent = sendEmailOtp($newEmail, $otpCode, $user['name'], 'change_email');

    jsonResponse([
        'success' => true,
        'message' => 'Kode OTP verifikasi telah dikirimkan ke email baru ' . $newEmail . '.',
        'new_email' => $newEmail,
        'otp_preview' => $otpCode,
        'email_sent' => $emailSent
    ]);
}

// 10. VERIFY EMAIL CHANGE OTP
if ($action === 'verify_email_change_otp') {
    $user = getAuthUser($pdo, null);
    if (!$user) {
        jsonResponse(['success' => false, 'message' => 'Silakan masuk terlebih dahulu.'], 401);
    }

    $newEmail = strtolower(trim($input['new_email'] ?? ''));
    $otpCode = trim($input['otp_code'] ?? '');

    if (empty($newEmail) || empty($otpCode)) {
        jsonResponse(['success' => false, 'message' => 'Email baru dan kode OTP wajib diisi.'], 400);
    }

    $stmt = $pdo->prepare("SELECT id FROM email_otps WHERE user_id = ? AND email = ? AND otp_code = ? AND type = 'change_email' AND is_used = 0 AND expires_at >= CURRENT_TIMESTAMP ORDER BY id DESC LIMIT 1");
    $stmt->execute([$user['id'], $newEmail, $otpCode]);
    $otpRow = $stmt->fetch();

    if (!$otpRow) {
        jsonResponse(['success' => false, 'message' => 'Kode OTP verifikasi email salah atau telah kadaluarsa.'], 400);
    }

    $pdo->prepare("UPDATE email_otps SET is_used = 1 WHERE id = ?")->execute([$otpRow['id']]);

    $up = $pdo->prepare("UPDATE users SET email = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?");
    $up->execute([$newEmail, $user['id']]);

    $uStmt = $pdo->prepare("SELECT id, role, name, email, phone, status, is_verified, avatar FROM users WHERE id = ?");
    $uStmt->execute([$user['id']]);
    $updatedUser = $uStmt->fetch();

    jsonResponse([
        'success' => true,
        'message' => 'Alamat email login berhasil diperbarui menjadi ' . $newEmail . '!',
        'user' => $updatedUser
    ]);
}

// 11. LOGOUT
if ($action === 'logout') {
    $token = getBearerToken();
    if ($token) {
        $pdo->prepare("UPDATE users SET token = '' WHERE token = ?")->execute([$token]);
    }
    jsonResponse(['success' => true, 'message' => 'Logout berhasil.']);
}

jsonResponse(['success' => false, 'message' => 'Aksi autentikasi tidak valid.'], 400);
