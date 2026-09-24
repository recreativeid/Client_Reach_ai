<?php
/**
 * Client Reach AI - Archive Manager API (Google Drive Style)
 * Supports nested folders (parent_id), breadcrumbs, dataset saving,
 * and prospect status toggle ('prospect', 'rejected', 'none').
 */
require_once __DIR__ . '/../config.php';

$rawInput = file_get_contents('php://input');
$jsonInput = !empty($rawInput) ? json_decode($rawInput, true) : [];
$input = !empty($jsonInput) ? $jsonInput : $_POST;
$action = $_GET['action'] ?? ($input['action'] ?? 'get_view');

// 1. Get Folder View: Lists subfolders, datasets in current folder, and breadcrumb trail
if ($action === 'get_view') {
    $folderId = isset($_GET['folder_id']) && $_GET['folder_id'] !== '' ? (int)$_GET['folder_id'] : null;

    try {
        // Fetch subfolders
        if ($folderId === null) {
            $folderStmt = $pdo->prepare("SELECT * FROM folders WHERE parent_id IS NULL ORDER BY name ASC");
            $folderStmt->execute();
        } else {
            $folderStmt = $pdo->prepare("SELECT * FROM folders WHERE parent_id = ? ORDER BY name ASC");
            $folderStmt->execute([$folderId]);
        }
        $folders = $folderStmt->fetchAll();

        // Add subfolder count and archive count to each folder
        foreach ($folders as &$f) {
            $subCount = $pdo->prepare("SELECT COUNT(*) FROM folders WHERE parent_id = ?");
            $subCount->execute([$f['id']]);
            $f['subfolder_count'] = (int)$subCount->fetchColumn();

            $arcCount = $pdo->prepare("SELECT COUNT(*) FROM archives WHERE folder_id = ?");
            $arcCount->execute([$f['id']]);
            $f['archive_count'] = (int)$arcCount->fetchColumn();
        }

        // Fetch datasets/archives in this folder
        if ($folderId === null) {
            $arcStmt = $pdo->prepare("SELECT * FROM archives WHERE folder_id IS NULL ORDER BY created_at DESC");
            $arcStmt->execute();
        } else {
            $arcStmt = $pdo->prepare("SELECT * FROM archives WHERE folder_id = ? ORDER BY created_at DESC");
            $arcStmt->execute([$folderId]);
        }
        $archives = $arcStmt->fetchAll();

        // Build breadcrumb path
        $breadcrumbs = [['id' => null, 'name' => 'Arsip Utama']];
        $currId = $folderId;
        $trail = [];
        while ($currId !== null) {
            $bStmt = $pdo->prepare("SELECT id, parent_id, name FROM folders WHERE id = ?");
            $bStmt->execute([$currId]);
            $fInfo = $bStmt->fetch();
            if ($fInfo) {
                $trail[] = ['id' => $fInfo['id'], 'name' => $fInfo['name']];
                $currId = $fInfo['parent_id'] !== null ? (int)$fInfo['parent_id'] : null;
            } else {
                break;
            }
        }
        $breadcrumbs = array_merge($breadcrumbs, array_reverse($trail));

        // Get total system stats for archives
        $totalArchives = (int)$pdo->query("SELECT COUNT(*) FROM archives")->fetchColumn();
        $totalProspects = (int)$pdo->query("SELECT COUNT(*) FROM scraped_items WHERE status = 'prospect'")->fetchColumn();

        jsonResponse([
            'success' => true,
            'current_folder_id' => $folderId,
            'breadcrumbs' => $breadcrumbs,
            'folders' => $folders,
            'archives' => $archives,
            'total_system_archives' => $totalArchives,
            'total_prospects' => $totalProspects
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 2. Create New Folder
if ($action === 'create_folder') {
    $name = trim($input['name'] ?? '');
    $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' ? (int)$input['parent_id'] : null;

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama folder tidak boleh kosong'], 400);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO folders (parent_id, name) VALUES (?, ?)");
        $stmt->execute([$parentId, $name]);
        $newId = (int)$pdo->lastInsertId();

        jsonResponse([
            'success' => true,
            'message' => 'Folder "' . $name . '" berhasil dibuat.',
            'folder' => ['id' => $newId, 'name' => $name, 'parent_id' => $parentId]
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 2b. List All Folders Hierarchically (For Dropdowns and Tree Pickers)
if ($action === 'list_all_folders') {
    try {
        $stmt = $pdo->query("SELECT * FROM folders ORDER BY name ASC");
        $allFolders = $stmt->fetchAll();

        function buildFolderTree($folders, $parentId = null, $depth = 0) {
            $result = [];
            foreach ($folders as $f) {
                $curParent = $f['parent_id'] !== null ? (int)$f['parent_id'] : null;
                if ($curParent === $parentId) {
                    $prefix = $depth > 0 ? str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', $depth) . '↳ ' : '';
                    $result[] = [
                        'id' => (int)$f['id'],
                        'name' => $f['name'],
                        'label' => $prefix . '📁 ' . $f['name'],
                        'depth' => $depth,
                        'parent_id' => $curParent
                    ];
                    $children = buildFolderTree($folders, (int)$f['id'], $depth + 1);
                    $result = array_merge($result, $children);
                }
            }
            return $result;
        }

        $tree = buildFolderTree($allFolders, null, 0);

        jsonResponse([
            'success' => true,
            'folders' => $tree
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 3. Save Scraping Data as Named Archive
if ($action === 'save_archive') {
    $name = trim($input['name'] ?? '');
    $folderId = isset($input['folder_id']) && $input['folder_id'] !== '' ? (int)$input['folder_id'] : null;
    $items = $input['items'] ?? [];

    if (empty($name)) {
        jsonResponse(['success' => false, 'message' => 'Nama simpanan arsip wajib diisi'], 400);
    }

    try {
        $stmt = $pdo->prepare("INSERT INTO archives (folder_id, name, total_items) VALUES (?, ?, ?)");
        $stmt->execute([$folderId, $name, count($items)]);
        $archiveId = (int)$pdo->lastInsertId();

        // Insert items into scraped_items table
        $itemStmt = $pdo->prepare("INSERT INTO scraped_items (archive_id, name, address, phone, lat, lng, category, social_media, opening_hours, rating, reviews_count, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($items as $it) {
            $itemStmt->execute([
                $archiveId,
                $it['name'] ?? 'Tempat Usaha',
                $it['address'] ?? '-',
                $it['phone'] ?? '',
                $it['lat'] ?? 0,
                $it['lng'] ?? 0,
                $it['category'] ?? 'Umum',
                $it['social_media'] ?? '',
                $it['opening_hours'] ?? '',
                $it['rating'] ?? 0,
                $it['reviews_count'] ?? 0,
                $it['status'] ?? 'none'
            ]);
        }

        jsonResponse([
            'success' => true,
            'message' => 'Data berhasil disimpan ke arsip "' . $name . '" (' . count($items) . ' kontak).',
            'archive_id' => $archiveId
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 4. Get items within a specific archive
if ($action === 'get_archive_items') {
    $archiveId = (int)($_GET['archive_id'] ?? 0);
    if ($archiveId <= 0) {
        jsonResponse(['success' => false, 'message' => 'ID arsip tidak valid'], 400);
    }

    try {
        $arcStmt = $pdo->prepare("SELECT * FROM archives WHERE id = ?");
        $arcStmt->execute([$archiveId]);
        $archive = $arcStmt->fetch();

        if (!$archive) {
            jsonResponse(['success' => false, 'message' => 'Arsip tidak ditemukan'], 404);
        }

        $itemsStmt = $pdo->prepare("SELECT * FROM scraped_items WHERE archive_id = ? ORDER BY id ASC");
        $itemsStmt->execute([$archiveId]);
        $items = $itemsStmt->fetchAll();

        jsonResponse([
            'success' => true,
            'archive' => $archive,
            'total_items' => count($items),
            'items' => $items
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 5. Update Prospect Status (Centang: 'prospect', Silang: 'rejected', Reset: 'none')
if ($action === 'update_status') {
    $itemId = (int)($input['item_id'] ?? 0);
    $status = trim($input['status'] ?? 'none'); // 'prospect', 'rejected', 'none'

    if (!in_array($status, ['prospect', 'rejected', 'none'])) {
        $status = 'none';
    }

    try {
        $stmt = $pdo->prepare("UPDATE scraped_items SET status = ? WHERE id = ?");
        $stmt->execute([$status, $itemId]);

        jsonResponse([
            'success' => true,
            'item_id' => $itemId,
            'status' => $status,
            'message' => 'Status baris berhasil diperbarui'
        ]);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 6. Delete Archive
if ($action === 'delete_archive') {
    $archiveId = (int)($input['archive_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("DELETE FROM archives WHERE id = ?");
        $stmt->execute([$archiveId]);
        jsonResponse(['success' => true, 'message' => 'Arsip berhasil dihapus']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

// 7. Delete Folder
if ($action === 'delete_folder') {
    $folderId = (int)($input['folder_id'] ?? 0);

    try {
        $stmt = $pdo->prepare("DELETE FROM folders WHERE id = ?");
        $stmt->execute([$folderId]);
        jsonResponse(['success' => true, 'message' => 'Folder berhasil dihapus']);
    } catch (Exception $e) {
        jsonResponse(['success' => false, 'message' => $e->getMessage()], 500);
    }
}

jsonResponse(['success' => false, 'message' => 'Aksi arsip tidak dikenali'], 400);
