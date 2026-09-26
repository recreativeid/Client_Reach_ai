<?php
/**
 * Client Reach AI - Workflow AI Sales
 * Configuration & Database Connection
 */

// Enable error reporting during development
error_reporting(E_ALL);
ini_set('display_errors', '0');

// Set headers for JSON APIs
function jsonResponse($data, $status = 200) {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

// Handle preflight OPTIONS request
if (isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
    header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With');
    exit(0);
}

// Load local overrides or environment variables securely
$localConfig = [];
if (file_exists(__DIR__ . '/config.local.php')) {
    $localConfig = require __DIR__ . '/config.local.php';
}

// Supabase Cloud PostgreSQL & API Configuration
$supabaseHost = $localConfig['SUPABASE_DB_HOST'] ?? (getenv('SUPABASE_DB_HOST') ?: '');
$supabasePass = $localConfig['SUPABASE_DB_PASSWORD'] ?? (getenv('SUPABASE_DB_PASSWORD') ?: '');
$supabaseUser = $localConfig['SUPABASE_DB_USER'] ?? (getenv('SUPABASE_DB_USER') ?: 'postgres');
$supabasePort = $localConfig['SUPABASE_DB_PORT'] ?? (getenv('SUPABASE_DB_PORT') ?: '5432');
$supabaseDb   = $localConfig['SUPABASE_DB_NAME'] ?? (getenv('SUPABASE_DB_NAME') ?: 'postgres');
$supabaseUrl  = $localConfig['SUPABASE_URL'] ?? (getenv('SUPABASE_URL') ?: '');
$supabaseKey  = $localConfig['SUPABASE_KEY'] ?? (getenv('SUPABASE_KEY') ?: '');

define('SUPABASE_URL', $supabaseUrl);
define('SUPABASE_KEY', $supabaseKey);

$dbConnected = false;

// 1. Try Supabase Cloud PostgreSQL Connection (if credentials provided)
if (!empty($supabaseHost) && extension_loaded('pdo_pgsql')) {
    try {
        $dsn = "pgsql:host={$supabaseHost};port={$supabasePort};dbname={$supabaseDb};sslmode=require";
        $pdo = new PDO($dsn, $supabaseUser, $supabasePass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]);
        $dbConnected = true;
    } catch (PDOException $e) {
        // Fall back to SQLite if Supabase connection fails
    }
}

// 2. Default Zero-Config Local SQLite Connection
if (!$dbConnected) {
    $dbDir = __DIR__ . '/database';
    if (!is_dir($dbDir)) {
        mkdir($dbDir, 0777, true);
    }
    $dbPath = $dbDir . '/client_reach.db';
    try {
        $pdo = new PDO("sqlite:" . $dbPath);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec("PRAGMA foreign_keys = ON;");
        $pdo->exec("PRAGMA journal_mode = WAL;");
    } catch (PDOException $e) {
        jsonResponse([
            'success' => false,
            'message' => 'Gagal terhubung ke database: ' . $e->getMessage()
        ], 500);
    }
}

$geminiKey = $localConfig['GEMINI_API_KEY'] ?? (getenv('GEMINI_API_KEY') ?: '');
$gmapsKey  = $localConfig['GOOGLE_MAPS_API_KEY'] ?? (getenv('GOOGLE_MAPS_API_KEY') ?: '');

define('GEMINI_API_KEY', $geminiKey);
define('GOOGLE_MAPS_API_KEY', $gmapsKey);
define('APP_NAME', 'cliento');
define('APP_SUBTITLE', 'sales intelligence');

// Auto-check and create users & otps tables if not exist
try {
    $pdo->exec("
    CREATE TABLE IF NOT EXISTS users (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        role TEXT NOT NULL DEFAULT 'customer',
        username TEXT UNIQUE DEFAULT NULL,
        name TEXT NOT NULL,
        email TEXT UNIQUE NOT NULL,
        phone TEXT DEFAULT '',
        password_hash TEXT NOT NULL,
        status TEXT DEFAULT 'active',
        is_verified INTEGER DEFAULT 0,
        avatar TEXT DEFAULT '',
        token TEXT DEFAULT '',
        last_login DATETIME DEFAULT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS email_otps (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id INTEGER DEFAULT NULL,
        email TEXT NOT NULL,
        otp_code TEXT NOT NULL,
        type TEXT DEFAULT 'register',
        expires_at DATETIME NOT NULL,
        is_used INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE IF NOT EXISTS system_settings (
        setting_key TEXT PRIMARY KEY,
        setting_value TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    ");

    // Ensure username and user_id columns exist across tables
    try { $pdo->exec("ALTER TABLE users ADD COLUMN username TEXT DEFAULT NULL;"); } catch (Exception $e) {}
    try { $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_users_username ON users(username) WHERE username IS NOT NULL;"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE folders ADD COLUMN user_id INTEGER DEFAULT NULL;"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE archives ADD COLUMN user_id INTEGER DEFAULT NULL;"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE scraping_history ADD COLUMN user_id INTEGER DEFAULT NULL;"); } catch (Exception $e) {}
    try { $pdo->exec("ALTER TABLE scraped_items ADD COLUMN insights_json TEXT DEFAULT NULL;"); } catch (Exception $e) {}

    // Seed default admin if empty
    $chkAdmin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    if (!$chkAdmin) {
        $adminStmt = $pdo->prepare("INSERT INTO users (role, username, name, email, phone, password_hash, status, is_verified) VALUES ('admin', 'admin', 'Super Administrator', 'admin@cliento.id', '081234567890', ?, 'active', 1)");
        $adminStmt->execute([password_hash('admin123', PASSWORD_DEFAULT)]);
    }
} catch (Exception $e) {
    // Ignore if table already exists or locked
}

/**
 * Get Bearer token from HTTP Authorization header
 */
function getBearerToken() {
    $headers = null;
    if (isset($_SERVER['Authorization'])) {
        $headers = trim($_SERVER["Authorization"]);
    } elseif (isset($_SERVER['HTTP_AUTHORIZATION'])) {
        $headers = trim($_SERVER["HTTP_AUTHORIZATION"]);
    } elseif (function_exists('apache_request_headers')) {
        $requestHeaders = apache_request_headers();
        $requestHeaders = array_combine(array_map('ucwords', array_keys($requestHeaders)), array_values($requestHeaders));
        if (isset($requestHeaders['Authorization'])) {
            $headers = trim($requestHeaders['Authorization']);
        }
    }
    
    if (!empty($headers) && preg_match('/Bearer\s(\S+)/', $headers, $matches)) {
        return $matches[1];
    }
    return null;
}

/**
 * Get Authenticated User from Token
 */
function getAuthUser($pdo, $requiredRole = null) {
    $token = getBearerToken();
    if (!$token) {
        if ($requiredRole !== null) {
            jsonResponse(['success' => false, 'message' => 'Akses ditolak: Token autentikasi tidak ditemukan'], 401);
        }
        return null;
    }

    try {
        $stmt = $pdo->prepare("SELECT id, role, name, email, phone, status, is_verified, avatar FROM users WHERE token = ? AND status = 'active' LIMIT 1");
        $stmt->execute([$token]);
        $user = $stmt->fetch();

        if (!$user) {
            if ($requiredRole !== null) {
                jsonResponse(['success' => false, 'message' => 'Sesi login tidak valid atau telah berakhir'], 401);
            }
            return null;
        }

        if ($requiredRole !== null && $user['role'] !== $requiredRole && $user['role'] !== 'admin') {
            jsonResponse(['success' => false, 'message' => 'Akses ditolak: Hak akses tidak memadai'], 403);
        }

        return $user;
    } catch (Exception $e) {
        if ($requiredRole !== null) {
            jsonResponse(['success' => false, 'message' => 'Kesalahan autentikasi: ' . $e->getMessage()], 500);
        }
        return null;
    }
}

/**
 * Native PHP Socket SMTP Client (Works directly with Gmail SMTP ssl://smtp.gmail.com:465)
 */
function sendDirectSmtpSocket($host, $port, $username, $password, $from, $fromName, $to, $subject, $htmlBody) {
    $timeout = 10;
    $isSsl = ($port == 465 || strpos($host, 'ssl://') !== false);
    $connectHost = ($isSsl && strpos($host, 'ssl://') === false) ? 'ssl://' . $host : $host;

    $socket = @stream_socket_client($connectHost . ':' . $port, $errno, $errstr, $timeout);
    if (!$socket) {
        return ['success' => false, 'error' => "Koneksi SMTP socket gagal ($errno): $errstr"];
    }

    stream_set_timeout($socket, $timeout);

    $readResp = function($s) {
        $out = '';
        while ($str = fgets($s, 515)) {
            $out .= $str;
            if (substr($str, 3, 1) == ' ') break;
        }
        return $out;
    };

    $sendCmd = function($s, $cmd) use ($readResp) {
        fputs($s, $cmd . "\r\n");
        return $readResp($s);
    };

    $greeting = $readResp($socket);
    if (substr($greeting, 0, 3) != '220') {
        fclose($socket);
        return ['success' => false, 'error' => "Server SMTP tidak merespon: $greeting"];
    }

    $sendCmd($socket, "EHLO " . gethostname());

    if ($port == 587) {
        $tls = $sendCmd($socket, "STARTTLS");
        if (substr($tls, 0, 3) != '220') {
            fclose($socket);
            return ['success' => false, 'error' => "STARTTLS ditolak: $tls"];
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return ['success' => false, 'error' => "Enkripsi TLS gagal"];
        }
        $sendCmd($socket, "EHLO " . gethostname());
    }

    $auth = $sendCmd($socket, "AUTH LOGIN");
    if (substr($auth, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'error' => "AUTH LOGIN ditolak: $auth"];
    }

    $userRes = $sendCmd($socket, base64_encode($username));
    if (substr($userRes, 0, 3) != '334') {
        fclose($socket);
        return ['success' => false, 'error' => "Email/Username SMTP ditolak: $userRes"];
    }

    $passRes = $sendCmd($socket, base64_encode($password));
    if (substr($passRes, 0, 3) != '235') {
        fclose($socket);
        return ['success' => false, 'error' => "Password / Sandi Aplikasi Gmail ditolak: $passRes"];
    }

    $sendCmd($socket, "MAIL FROM: <$from>");
    $sendCmd($socket, "RCPT TO: <$to>");
    $sendCmd($socket, "DATA");

    $headers  = "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "From: $fromName <$from>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "Date: " . date('r') . "\r\n";

    $message = $headers . "\r\n" . $htmlBody . "\r\n.\r\n";
    fputs($socket, $message);
    $dataResp = $readResp($socket);

    $sendCmd($socket, "QUIT");
    fclose($socket);

    if (substr($dataResp, 0, 3) == '250') {
        return ['success' => true, 'message' => 'Email terkirim via SMTP'];
    }
    return ['success' => false, 'error' => "Pengiriman data gagal: $dataResp"];
}

/**
 * Resend REST API Mailer (Free 3,000 emails/month via https://resend.com)
 */
function sendResendApiMail($apiKey, $from, $to, $subject, $html) {
    if (empty($apiKey)) return ['success' => false, 'error' => 'API Key Resend kosong'];
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Authorization: Bearer ' . $apiKey,
        'Content-Type: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'from' => $from ?: 'Cliento <onboarding@resend.dev>',
        'to' => [$to],
        'subject' => $subject,
        'html' => $html
    ]));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    return ['success' => ($code >= 200 && $code < 300), 'response' => $data];
}

/**
 * Brevo REST API Mailer (Free 300 emails/day / 9,000/mo via https://brevo.com)
 */
function sendBrevoApiMail($apiKey, $fromEmail, $fromName, $toEmail, $toName, $subject, $html) {
    if (empty($apiKey)) return ['success' => false, 'error' => 'API Key Brevo kosong'];
    $ch = curl_init('https://api.brevo.com/v3/smtp/email');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'api-key: ' . $apiKey,
        'Content-Type: application/json',
        'Accept: application/json'
    ]);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'sender' => ['name' => $fromName, 'email' => $fromEmail],
        'to' => [['email' => $toEmail, 'name' => $toName]],
        'subject' => $subject,
        'htmlContent' => $html
    ]));
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $data = json_decode($res, true);
    return ['success' => ($code >= 200 && $code < 300), 'response' => $data];
}

/**
 * Helper to send branded HTML OTP email with support for Gmail SMTP, Resend API, & Native Mail
 */
function sendEmailOtp($toEmail, $otpCode, $userName = 'Pengguna Cliento', $purpose = 'register') {
    global $pdo;

    $badgeLabel = 'Verifikasi Akun Baru';
    $purposeDesc = 'Gunakan kode verifikasi OTP berikut untuk menyelesaikan pendaftaran akun Anda:';
    if ($purpose === 'forgot_password') {
        $badgeLabel = 'Pemulihan Kata Sandi Akun';
        $purposeDesc = 'Kami menerima permintaan untuk mereset kata sandi akun Anda. Masukkan kode OTP berikut:';
    } elseif ($purpose === 'change_email') {
        $badgeLabel = 'Verifikasi Perubahan Email';
        $purposeDesc = 'Gunakan kode OTP berikut untuk mengonfirmasi perubahan alamat email akun Cliento Anda:';
    }

    $subject = "Kode OTP $badgeLabel Cliento: " . $otpCode;
    
    $htmlContent = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="utf-8">
        <style>
            body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Arial, sans-serif; background-color: #f8fafc; margin: 0; padding: 24px; color: #1e293b; }
            .card { max-width: 500px; margin: 0 auto; background: #ffffff; border-radius: 16px; padding: 32px; box-shadow: 0 4px 16px rgba(0,0,0,0.06); border: 1px solid #e2e8f0; }
            .logo { font-size: 22px; font-weight: 800; color: #2563eb; letter-spacing: -0.5px; margin-bottom: 20px; }
            .badge { display: inline-block; padding: 4px 10px; background: #eff6ff; color: #2563eb; border-radius: 999px; font-size: 11px; font-weight: 700; text-transform: uppercase; margin-bottom: 16px; }
            .title { font-size: 18px; font-weight: 700; color: #0f172a; margin-bottom: 8px; }
            .desc { font-size: 14px; color: #64748b; line-height: 1.6; margin-bottom: 24px; }
            .otp-box { background: #f1f5f9; border: 2px dashed #93c5fd; border-radius: 12px; padding: 18px; text-align: center; margin-bottom: 24px; }
            .otp-code { font-size: 32px; font-weight: 800; letter-spacing: 8px; color: #1e3a8a; font-family: monospace; }
            .otp-hint { font-size: 12px; color: #64748b; margin-top: 6px; }
            .footer { font-size: 11px; color: #94a3b8; text-align: center; margin-top: 24px; border-top: 1px solid #f1f5f9; padding-top: 16px; }
        </style>
    </head>
    <body>
        <div class="card">
            <div class="logo">cliento <span style="font-size: 12px; font-weight: 500; color: #64748b;">sales intelligence</span></div>
            <div class="badge">' . htmlspecialchars($badgeLabel) . '</div>
            <div class="title">Halo, ' . htmlspecialchars($userName) . '!</div>
            <div class="desc">' . $purposeDesc . '</div>
            
            <div class="otp-box">
                <div class="otp-code">' . htmlspecialchars($otpCode) . '</div>
                <div class="otp-hint">Kode ini berlaku selama 15 menit. Jaga kerahasiaan kode Anda.</div>
            </div>
            
            <div class="desc" style="font-size: 13px;">Jika Anda tidak merasa melakukan permintaan ini, abaikan email ini dengan aman.</div>
            
            <div class="footer">
                &copy; ' . date('Y') . ' cliento - sales intelligence. Seluruh hak cipta dilindungi.
            </div>
        </div>
    </body>
    </html>';

    // Check configured settings from DB
    $settings = [];
    try {
        $stmt = $pdo->query("SELECT setting_key, setting_value FROM system_settings");
        $settings = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
    } catch (Exception $e) {}

    $provider = $settings['smtp_provider'] ?? 'smtp';
    $smtpHost = $settings['smtp_host'] ?? 'smtp.gmail.com';
    $smtpPort = (int)($settings['smtp_port'] ?? 465);
    $smtpUser = $settings['smtp_user'] ?? '';
    $smtpPass = $settings['smtp_pass'] ?? '';
    $smtpFrom = $settings['smtp_from'] ?? ($smtpUser ?: 'no-reply@cliento.id');
    $smtpFromName = 'cliento Sales Intelligence';
    $brevoKey = $settings['brevo_api_key'] ?? '';
    $resendKey = $settings['resend_api_key'] ?? '';

    // 1. Try Brevo API (Free 300 emails/day to Gmail)
    if ($provider === 'brevo' || (!empty($brevoKey) && empty($smtpPass))) {
        $res = sendBrevoApiMail($brevoKey, $smtpFrom, $smtpFromName, $toEmail, $userName, $subject, $htmlContent);
        if ($res['success']) return true;
    }

    // 2. Try Resend API (Free 3,000 emails/month)
    if ($provider === 'resend' || (!empty($resendKey) && empty($smtpPass))) {
        $res = sendResendApiMail($resendKey, $smtpFrom, $toEmail, $subject, $htmlContent);
        if ($res['success']) return true;
    }

    // 3. Try Gmail SMTP / Custom SMTP if password configured
    if (!empty($smtpUser) && !empty($smtpPass)) {
        $res = sendDirectSmtpSocket($smtpHost, $smtpPort, $smtpUser, $smtpPass, $smtpFrom, $smtpFromName, $toEmail, $subject, $htmlContent);
        if ($res['success']) return true;
    }

    // 3. Fallback to native PHP mail
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $smtpFromName . ' <' . $smtpFrom . '>',
        'Reply-To: support@cliento.id',
        'X-Mailer: PHP/' . phpversion()
    ];

    $sent = false;
    try {
        $sent = @mail($toEmail, $subject, $htmlContent, implode("\r\n", $headers));
    } catch (Exception $e) {
        $sent = false;
    }
    return $sent;
}


