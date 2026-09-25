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

// Database Connection (SQLite)
$dbDir = __DIR__ . '/database';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$dbPath = $dbDir . '/client_reach.db';

try {
    $pdo = new PDO("sqlite:" . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // Enable foreign keys and WAL mode for better concurrency
    $pdo->exec("PRAGMA foreign_keys = ON;");
    $pdo->exec("PRAGMA journal_mode = WAL;");
} catch (PDOException $e) {
    jsonResponse([
        'success' => false,
        'message' => 'Gagal terhubung ke database: ' . $e->getMessage()
    ], 500);
}

// Load local overrides or environment variables securely
$localConfig = [];
if (file_exists(__DIR__ . '/config.local.php')) {
    $localConfig = require __DIR__ . '/config.local.php';
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

    // Seed default admin if empty
    $chkAdmin = $pdo->query("SELECT id FROM users WHERE role = 'admin' LIMIT 1")->fetch();
    if (!$chkAdmin) {
        $adminStmt = $pdo->prepare("INSERT INTO users (role, name, email, phone, password_hash, status, is_verified) VALUES ('admin', 'Super Administrator', 'admin@cliento.id', '081234567890', ?, 'active', 1)");
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
 * Helper to send branded HTML OTP email
 */
function sendEmailOtp($toEmail, $otpCode, $userName = 'Pengguna Cliento') {
    $subject = "Kode OTP Verifikasi Akun Cliento Anda: " . $otpCode;
    
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
            <div class="badge">Verifikasi Email OTP</div>
            <div class="title">Halo, ' . htmlspecialchars($userName) . '!</div>
            <div class="desc">Terima kasih telah mendaftar di <strong>cliento</strong>. Gunakan kode verifikasi OTP berikut untuk menyelesaikan pendaftaran akun Anda:</div>
            
            <div class="otp-box">
                <div class="otp-code">' . htmlspecialchars($otpCode) . '</div>
                <div class="otp-hint">Kode ini berlaku selama 15 menit. Jaga kerahasiaan kode Anda.</div>
            </div>
            
            <div class="desc" style="font-size: 13px;">Jika Anda tidak merasa melakukan pendaftaran ini, silakan abaikan email ini dengan aman.</div>
            
            <div class="footer">
                &copy; ' . date('Y') . ' cliento - sales intelligence. Seluruh hak cipta dilindungi.
            </div>
        </div>
    </body>
    </html>';

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: cliento Sales Intelligence <no-reply@cliento.id>',
        'Reply-To: support@cliento.id',
        'X-Mailer: PHP/' . phpversion()
    ];

    // Attempt native PHP mail
    $sent = false;
    try {
        $sent = @mail($toEmail, $subject, $htmlContent, implode("\r\n", $headers));
    } catch (Exception $e) {
        $sent = false;
    }
    return $sent;
}


