<?php
/**
 * Client Reach AI - WhatsApp Message Template Manager API
 * Manage message templates per business category, add new categories, and interpolate variables.
 */
require_once __DIR__ . '/../config.php';

$method = $_SERVER['REQUEST_METHOD'];
$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$input = !empty($jsonInput) ? $jsonInput : $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

// 1. List all categories and their message templates
if ($action === 'list') {
    try {
        $cats = $pdo->query("SELECT * FROM categories ORDER BY name ASC")->fetchAll();
        $templates = $pdo->query("SELECT * FROM templates")->fetchAll();
        
        $tplMap = [];
        foreach ($templates as $t) {
            $tplMap[$t['category_name']] = $t;
        }

        $result = [];
        foreach ($cats as $c) {
            $cName = $c['name'];
            $tpl = $tplMap[$cName] ?? [
                'category_name' => $cName,
                'greeting_type' => 'formal',
                'message_body' => 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami dari tim Client Reach AI tertarik dengan potensi bisnis kakak di {alamat}. Boleh kami sharing solusi singkat untuk optimasi sales? Terima kasih!'
            ];
            $result[] = [
                'category_id' => $c['id'],
                'category_name' => $cName,
                'greeting_type' => $tpl['greeting_type'] ?? 'formal',
                'message_body' => $tpl['message_body'] ?? ''
            ];
        }

        jsonResponse([
            'success' => true,
            'categories' => $result
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 2. Save or update a template for a category
if ($action === 'save') {
    $categoryName = trim($input['category_name'] ?? '');
    $greetingType = trim($input['greeting_type'] ?? 'formal');
    $messageBody = trim($input['message_body'] ?? '');

    if (empty($categoryName) || empty($messageBody)) {
        jsonResponse(['success' => false, 'message' => 'Kategori dan isi pesan wajib diisi'], 400);
    }

    try {
        // Ensure category exists in categories table
        $catStmt = $pdo->prepare("INSERT OR IGNORE INTO categories (name) VALUES (?)");
        $catStmt->execute([$categoryName]);

        // Upsert template
        $stmt = $pdo->prepare("
            INSERT INTO templates (category_name, greeting_type, message_body, updated_at) 
            VALUES (?, ?, ?, CURRENT_TIMESTAMP)
            ON CONFLICT(category_name) DO UPDATE SET 
                greeting_type = excluded.greeting_type,
                message_body = excluded.message_body,
                updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$categoryName, $greetingType, $messageBody]);

        jsonResponse([
            'success' => true,
            'message' => 'Template untuk kategori "' . $categoryName . '" berhasil disimpan.'
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 3. Create a new custom category
if ($action === 'create_category') {
    $categoryName = trim($input['name'] ?? '');

    if (empty($categoryName)) {
        jsonResponse(['success' => false, 'message' => 'Nama kategori tidak boleh kosong'], 400);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO categories (name) VALUES (?)");
        $stmt->execute([$categoryName]);
        $newId = (int)$pdo->lastInsertId();

        // Create default template for this new category
        $defaultBody = 'Hallo kak dgn pemilik/team manajemen {nama_tempat}? Kami dari Client Reach AI melihat perkembangan usaha kakak di {alamat} sangat menarik. Kami ingin berbagi solusi otomasi sales untuk memperluas jangkauan pelanggan. Apakah ada waktu luang sebentar kak? Terima kasih!';
        $tplStmt = $pdo->prepare("INSERT OR IGNORE INTO templates (category_name, greeting_type, message_body) VALUES (?, 'formal', ?)");
        $tplStmt->execute([$categoryName, $defaultBody]);

        jsonResponse([
            'success' => true,
            'message' => 'Kategori baru berhasil ditambahkan.',
            'category' => ['id' => $newId, 'name' => $categoryName]
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => 'Kategori sudah ada atau terjadi kesalahan: ' . $e->getMessage()], 400);
    }
}

jsonResponse(['success' => false, 'message' => 'Aksi template tidak dikenali'], 400);
