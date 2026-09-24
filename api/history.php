<?php
/**
 * Client Reach AI - Scraping History API
 * View previous scraping logs and transfer history results into archives.
 */
require_once __DIR__ . '/../config.php';

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$input = !empty($jsonInput) ? $jsonInput : $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? 'list');

// 1. List Scraping History
if ($action === 'list') {
    try {
        $stmt = $pdo->query("SELECT * FROM scraping_history ORDER BY created_at DESC LIMIT 30");
        $history = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'history' => $history
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 2. Get items of a specific history entry
if ($action === 'get_items') {
    $historyId = (int)($_GET['history_id'] ?? 0);
    try {
        $stmt = $pdo->prepare("SELECT * FROM scraped_items WHERE history_id = ? ORDER BY id ASC");
        $stmt->execute([$historyId]);
        $items = $stmt->fetchAll();

        jsonResponse([
            'success' => true,
            'history_id' => $historyId,
            'items' => $items
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 3. Save History to Archive
if ($action === 'save_to_archive') {
    $historyId = (int)($input['history_id'] ?? 0);
    $archiveName = trim($input['name'] ?? '');
    $folderId = isset($input['folder_id']) && $input['folder_id'] !== '' ? (int)$input['folder_id'] : null;

    if (empty($archiveName)) {
        jsonResponse(['success' => false, 'message' => 'Nama simpanan arsip wajib diisi'], 400);
    }

    try {
        // Fetch items from this history run
        $itemStmt = $pdo->prepare("SELECT * FROM scraped_items WHERE history_id = ?");
        $itemStmt->execute([$historyId]);
        $items = $itemStmt->fetchAll();

        if (empty($items)) {
            jsonResponse(['success' => false, 'message' => 'Tidak ada data pada riwayat ini'], 404);
        }

        // Create new archive entry
        $arcStmt = $pdo->prepare("INSERT INTO archives (folder_id, name, total_items) VALUES (?, ?, ?)");
        $arcStmt->execute([$folderId, $archiveName, count($items)]);
        $archiveId = (int)$pdo->lastInsertId();

        // Clone items into the archive
        $copyStmt = $pdo->prepare("INSERT INTO scraped_items (archive_id, name, address, phone, lat, lng, category, social_media, opening_hours, rating, reviews_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'none')");
        foreach ($items as $it) {
            $copyStmt->execute([
                $archiveId,
                $it['name'],
                $it['address'],
                $it['phone'],
                $it['lat'],
                $it['lng'],
                $it['category'],
                $it['social_media'],
                $it['opening_hours'],
                $it['rating'],
                $it['reviews_count']
            ]);
        }

        jsonResponse([
            'success' => true,
            'message' => 'Riwayat berhasil disimpan ke arsip "' . $archiveName . '"',
            'archive_id' => $archiveId
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

jsonResponse(['success' => false, 'message' => 'Aksi riwayat tidak dikenali'], 400);
