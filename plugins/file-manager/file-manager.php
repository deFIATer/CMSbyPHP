<?php
declare(strict_types=1);

/**
 * Plugin Name: Menadżer plików (FTP)
 * Description: Zaawansowany menadżer plików serwera (FTP). Przeglądaj całą strukturę folderu public_html, podglądaj, edytuj, twórz i pobieraj pliki bezpośrednio z panelu admina.
 * Version: 1.0.0
 * Author: CMSbyPHP
 */

use Core\Auth;
use Core\Security;

// 1. Register plugin in GetSimple Compatibility Registry
if (function_exists('register_plugin')) {
    register_plugin(
        'file-manager',
        'Menadżer plików (FTP)',
        '1.0.0',
        'CMSbyPHP',
        'https://github.com/deFIATer/CMSbyPHP',
        'Zaawansowany menadżer plików serwera (FTP). Przeglądaj całą strukturę folderu public_html, podglądaj, edytuj, twórz i pobieraj pliki bezpośrednio z panelu admina.',
        'tools',
        'file_manager_admin_render'
    );
}

if (class_exists('Core\Hooks')) {
    \Core\Hooks::addAction('admin_plugin_view_file-manager', 'file_manager_admin_render');
}

// 2. Add sidebar menu item
if (function_exists('createSideMenu')) {
    createSideMenu(
        'file-manager',
        'Menadżer plików (FTP)',
        'file-manager',
        'M3 7v10a2 2 0 002 2h14a2 2 0 002-2V9a2 2 0 00-2-2h-6l-2-2H5a2 2 0 00-2 2z'
    );
}

// 3. Early request interceptor for AJAX and file downloads (before headers/HTML)
if (defined('IN_ADMIN') && isset($_GET['id']) && $_GET['id'] === 'file-manager') {
    file_manager_handle_actions();
}

/**
 * Resolves the root public_html directory path
 */
function file_manager_root(): string {
    // __DIR__ is plugins/file-manager, so dirname(__DIR__, 2) is the root CMS directory (public_html)
    $root = realpath(dirname(__DIR__, 2));
    return $root ?: dirname(__DIR__, 2);
}

/**
 * Validates and safely resolves relative paths inside the root jail
 */
function file_manager_resolve_path(string $subpath = ''): ?string {
    $root = file_manager_root();
    $clean = trim(str_replace('\\', '/', $subpath), '/');
    
    // Prevent directory traversal sequences
    if (str_contains($clean, '..')) {
        return null;
    }

    $target = $clean === '' ? $root : realpath($root . '/' . $clean);
    if ($target === false || !str_starts_with($target, $root)) {
        return null;
    }

    return $target;
}

/**
 * Formats bytes to human-readable size
 */
function file_manager_format_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

/**
 * Determines file icon, category and editable status
 */
function file_manager_file_info(string $filename, bool $isDir): array {
    if ($isDir) {
        return [
            'icon' => '📁',
            'type' => 'Folder',
            'editable' => false,
            'previewable' => false
        ];
    }

    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $editableExtensions = ['php', 'html', 'htm', 'phtml', 'css', 'scss', 'js', 'json', 'txt', 'md', 'xml', 'htaccess', 'svg', 'ini', 'log', 'sql', 'yaml', 'yml', 'sample'];
    $imageExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico'];
    $videoExtensions = ['mp4', 'webm', 'ogg', 'mov'];
    $audioExtensions = ['mp3', 'wav', 'aac'];

    $isEditable = in_array($ext, $editableExtensions, true) || str_starts_with($filename, '.');
    $isImage = in_array($ext, $imageExtensions, true);
    $isVideo = in_array($ext, $videoExtensions, true);
    $isAudio = in_array($ext, $audioExtensions, true);

    $icon = match ($ext) {
        'php' => '🐘',
        'js', 'ts' => '🟨',
        'css', 'scss' => '🎨',
        'html', 'htm' => '🌐',
        'json', 'xml', 'yaml', 'yml' => '⚙️',
        'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg' => '🖼️',
        'mp4', 'webm', 'mov' => '🎬',
        'mp3', 'wav', 'ogg' => '🎵',
        'zip', 'tar', 'gz', 'rar' => '📦',
        'pdf' => '📕',
        'sqlite', 'db', 'sql' => '🗄️',
        'md', 'txt' => '📝',
        default => '📄'
    };

    return [
        'icon' => $icon,
        'type' => strtoupper($ext ?: 'FILE'),
        'editable' => $isEditable,
        'previewable' => $isImage || $isVideo || $isAudio || $isEditable,
        'is_image' => $isImage,
        'is_video' => $isVideo,
        'is_audio' => $isAudio
    ];
}

/**
 * Handles early actions (download, view, raw stream, AJAX file save/create/delete)
 */
function file_manager_handle_actions(): void {
    if (!Auth::check() || !Auth::can('manage_settings')) {
        return;
    }

    $action = $_REQUEST['fm_action'] ?? '';
    if ($action === '') {
        return;
    }

    $root = file_manager_root();

    // 1. File Download
    if ($action === 'download') {
        $path = file_manager_resolve_path($_GET['file'] ?? '');
        if (!$path || !is_file($path)) {
            http_response_code(404);
            die('Plik nie istnieje.');
        }

        $filename = basename($path);
        header('Content-Description: File Transfer');
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . rawurlencode($filename) . '"');
        header('Expires: 0');
        header('Cache-Control: must-revalidate');
        header('Pragma: public');
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // 2. Raw Stream (images, media, or text preview)
    if ($action === 'raw') {
        $path = file_manager_resolve_path($_GET['file'] ?? '');
        if (!$path || !is_file($path)) {
            http_response_code(404);
            die('Nie znaleziono pliku.');
        }

        $mime = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $mime = finfo_file($finfo, $path) ?: 'application/octet-stream';
            finfo_close($finfo);
        }

        header('Content-Type: ' . $mime);
        header('Content-Length: ' . filesize($path));
        readfile($path);
        exit;
    }

    // 3. Read text file content for code editor
    if ($action === 'read') {
        header('Content-Type: application/json; charset=utf-8');
        $path = file_manager_resolve_path($_GET['file'] ?? '');
        if (!$path || !is_file($path)) {
            echo json_encode(['ok' => false, 'error' => 'Plik nie istnieje lub jest niedostępny.']);
            exit;
        }

        // Limit editor read size to 4MB to prevent memory exhaustion
        if (filesize($path) > 4 * 1024 * 1024) {
            echo json_encode(['ok' => false, 'error' => 'Plik jest zbyt duży do edycji w przeglądarce (> 4MB). Pobierz go i edytuj lokalnie.']);
            exit;
        }

        $content = file_get_contents($path);
        echo json_encode([
            'ok' => true,
            'file' => basename($path),
            'rel_path' => ltrim(str_replace($root, '', $path), '/'),
            'size' => filesize($path),
            'content' => $content
        ]);
        exit;
    }

    // Mutating actions require POST & CSRF
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $token = (string)($_POST['csrf_token'] ?? '');
        if (!Security::verifyCsrfToken($token)) {
            if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json')) {
                header('Content-Type: application/json; charset=utf-8');
                http_response_code(403);
                echo json_encode(['ok' => false, 'error' => 'Nieprawidłowy token CSRF']);
                exit;
            }
            die('Nieprawidłowy token CSRF');
        }

        // 4. Save file content
        if ($action === 'save') {
            header('Content-Type: application/json; charset=utf-8');
            $path = file_manager_resolve_path($_POST['file'] ?? '');
            if (!$path || !is_file($path)) {
                echo json_encode(['ok' => false, 'error' => 'Plik docelowy nie istnieje.']);
                exit;
            }

            if (!is_writable($path)) {
                echo json_encode(['ok' => false, 'error' => 'Brak uprawnień zapisu do pliku na serwerze.']);
                exit;
            }

            $content = (string)($_POST['content'] ?? '');
            $res = file_put_contents($path, $content);
            if ($res === false) {
                echo json_encode(['ok' => false, 'error' => 'Błąd podczas zapisywania zmian na dysku.']);
                exit;
            }

            echo json_encode(['ok' => true, 'message' => 'Plik został pomyślnie zapisany.']);
            exit;
        }

        // 5. Create new file
        if ($action === 'create_file') {
            $folder = file_manager_resolve_path($_POST['current_dir'] ?? '');
            $name = trim(basename($_POST['file_name'] ?? ''));
            if ($folder && is_dir($folder) && $name !== '') {
                $targetFile = $folder . '/' . $name;
                if (!file_exists($targetFile)) {
                    file_put_contents($targetFile, '');
                }
            }
            header('Location: plugins.php?id=file-manager&path=' . urlencode($_POST['current_dir'] ?? '') . '&msg=created');
            exit;
        }

        // 6. Create new folder
        if ($action === 'create_folder') {
            $folder = file_manager_resolve_path($_POST['current_dir'] ?? '');
            $name = trim(basename($_POST['folder_name'] ?? ''));
            if ($folder && is_dir($folder) && $name !== '') {
                $targetDir = $folder . '/' . $name;
                if (!file_exists($targetDir)) {
                    mkdir($targetDir, 0755, true);
                }
            }
            header('Location: plugins.php?id=file-manager&path=' . urlencode($_POST['current_dir'] ?? '') . '&msg=folder_created');
            exit;
        }

        // 7. Rename file or folder
        if ($action === 'rename') {
            $oldPath = file_manager_resolve_path($_POST['target_item'] ?? '');
            $newName = trim(basename($_POST['new_name'] ?? ''));
            if ($oldPath && file_exists($oldPath) && $newName !== '') {
                $newPath = dirname($oldPath) . '/' . $newName;
                if (!file_exists($newPath)) {
                    rename($oldPath, $newPath);
                }
            }
            header('Location: plugins.php?id=file-manager&path=' . urlencode($_POST['current_dir'] ?? '') . '&msg=renamed');
            exit;
        }

        // 8. Delete file or folder
        if ($action === 'delete') {
            $target = file_manager_resolve_path($_POST['target_item'] ?? '');
            if ($target && file_exists($target) && $target !== $root) {
                if (is_dir($target)) {
                    file_manager_delete_tree($target);
                } else {
                    unlink($target);
                }
            }
            header('Location: plugins.php?id=file-manager&path=' . urlencode($_POST['current_dir'] ?? '') . '&msg=deleted');
            exit;
        }

        // 9. Upload file(s) into current directory
        if ($action === 'upload') {
            $folder = file_manager_resolve_path($_POST['current_dir'] ?? '');
            if ($folder && is_dir($folder) && !empty($_FILES['upload_files'])) {
                $files = $_FILES['upload_files'];
                $count = is_array($files['name']) ? count($files['name']) : 1;
                for ($i = 0; $i < $count; $i++) {
                    $tmp = is_array($files['tmp_name']) ? $files['tmp_name'][$i] : $files['tmp_name'];
                    $name = is_array($files['name']) ? $files['name'][$i] : $files['name'];
                    $error = is_array($files['error']) ? $files['error'][$i] : $files['error'];

                    if ($error === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
                        $dest = $folder . '/' . basename($name);
                        move_uploaded_file($tmp, $dest);
                    }
                }
            }
            header('Location: plugins.php?id=file-manager&path=' . urlencode($_POST['current_dir'] ?? '') . '&msg=uploaded');
            exit;
        }
    }
}

/**
 * Recursively deletes a directory
 */
function file_manager_delete_tree(string $dir): bool {
    $files = array_diff(scandir($dir) ?: [], ['.', '..']);
    foreach ($files as $file) {
        $p = $dir . '/' . $file;
        is_dir($p) ? file_manager_delete_tree($p) : unlink($p);
    }
    return rmdir($dir);
}

/**
 * Main plugin view rendering function
 */
function file_manager_admin_render(): void {
    if (!Auth::can('manage_settings')) {
        echo '<p style="color:var(--danger); padding:20px;">Brak uprawnień do zarządzania plikami.</p>';
        return;
    }

    $root = file_manager_root();
    $sub = trim(str_replace('\\', '/', $_GET['path'] ?? ''), '/');
    $currentDir = file_manager_resolve_path($sub);

    if (!$currentDir || !is_dir($currentDir)) {
        $currentDir = $root;
        $sub = '';
    }

    $isAtRoot = ($currentDir === $root);
    $parentSub = '';
    if (!$isAtRoot) {
        $parentSub = ltrim(str_replace($root, '', dirname($currentDir)), '/');
    }

    // Build breadcrumbs
    $breadcrumbs = [];
    $parts = $sub !== '' ? explode('/', $sub) : [];
    $accum = '';
    foreach ($parts as $p) {
        $accum = ltrim($accum . '/' . $p, '/');
        $breadcrumbs[] = [
            'name' => $p,
            'path' => $accum
        ];
    }

    // Scan directory contents
    $entries = scandir($currentDir) ?: [];
    $dirs = [];
    $files = [];
    $totalSize = 0;

    foreach ($entries as $e) {
        if ($e === '.' || $e === '..') {
            continue;
        }

        $fullPath = $currentDir . '/' . $e;
        $relPath = ltrim(str_replace($root, '', $fullPath), '/');
        $isDir = is_dir($fullPath);
        $mtime = filemtime($fullPath);
        $perms = substr(sprintf('%o', fileperms($fullPath)), -4);

        if ($isDir) {
            $childCount = count(array_diff(scandir($fullPath) ?: [], ['.', '..']));
            $dirs[] = [
                'name' => $e,
                'path' => $relPath,
                'full' => $fullPath,
                'mtime' => $mtime,
                'perms' => $perms,
                'items' => $childCount
            ];
        } else {
            $size = filesize($fullPath);
            $totalSize += $size;
            $info = file_manager_file_info($e, false);
            $files[] = [
                'name' => $e,
                'path' => $relPath,
                'full' => $fullPath,
                'size' => $size,
                'size_formatted' => file_manager_format_size($size),
                'mtime' => $mtime,
                'perms' => $perms,
                'info' => $info
            ];
        }
    }

    // Sort alphabetically
    usort($dirs, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    usort($files, fn($a, $b) => strcasecmp($a['name'], $b['name']));

    $csrf = Security::generateCsrfToken();
    $msg = $_GET['msg'] ?? '';
    ?>

    <style>
        .fm-container { font-family: inherit; }
        .fm-toolbar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 12px; margin-bottom: 16px; }
        .fm-breadcrumbs { display: flex; align-items: center; gap: 6px; flex-wrap: wrap; font-size: 13px; font-weight: 500; }
        .fm-breadcrumbs a { color: var(--primary); text-decoration: none; padding: 3px 6px; border-radius: 4px; }
        .fm-breadcrumbs a:hover { background: var(--bg-subtle); }
        .fm-table-row:hover { background: var(--bg-subtle, rgba(0,0,0,0.02)); }
        .fm-modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.65); z-index: 9999; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px); }
        .fm-modal { background: var(--bg-card, #ffffff); border-radius: 12px; width: 100%; max-width: 600px; padding: 24px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle); }
        .fm-editor-modal { max-width: 960px; height: 85vh; display: flex; flex-direction: column; padding: 20px; }
        .fm-code-area { width: 100%; flex: 1; min-height: 400px; font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, monospace; font-size: 13px; line-height: 1.5; background: var(--bg-subtle, #0f172a); color: var(--text-main, #f8fafc); border: 1px solid var(--border-subtle); border-radius: 8px; padding: 14px; resize: none; tab-size: 4; white-space: pre; }
    </style>

    <div class="fm-container">
        <!-- Header & Action Status Alerts -->
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px; flex-wrap: wrap; gap: 10px;">
            <div>
                <h2 style="font-size: 18px; font-weight: 700; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                    <span>📂 Menadżer plików serwera (FTP)</span>
                    <span class="badge" style="background: rgba(37, 99, 235, 0.12); color: var(--primary); font-size: 11px; font-weight: 700;">public_html</span>
                </h2>
                <p style="font-size: 12px; color: var(--text-muted); margin: 2px 0 0;">
                    Przeglądaj strukturę serwera, edytuj kod, twórz i pobieraj pliki bez konieczności uruchamiania klienta FTP.
                </p>
            </div>
            <div style="font-size: 12px; color: var(--text-muted); background: var(--bg-subtle); padding: 5px 12px; border-radius: 8px; border: 1px solid var(--border-subtle);">
                Katalog: <strong><?= count($dirs) ?></strong> folderów, <strong><?= count($files) ?></strong> plików (<strong><?= file_manager_format_size($totalSize) ?></strong>)
            </div>
        </div>

        <?php if ($msg === 'created'): ?>
            <div class="card" style="border-left: 4px solid var(--success); background: var(--success-subtle); padding: 10px 14px; margin-bottom: 16px; color: var(--success); font-weight: 600; font-size: 13px;">
                ✓ Nowy plik został utworzony.
            </div>
        <?php elseif ($msg === 'folder_created'): ?>
            <div class="card" style="border-left: 4px solid var(--success); background: var(--success-subtle); padding: 10px 14px; margin-bottom: 16px; color: var(--success); font-weight: 600; font-size: 13px;">
                ✓ Nowy folder został utworzony.
            </div>
        <?php elseif ($msg === 'renamed'): ?>
            <div class="card" style="border-left: 4px solid var(--primary); background: rgba(37, 99, 235, 0.08); padding: 10px 14px; margin-bottom: 16px; color: var(--primary); font-weight: 600; font-size: 13px;">
                ✓ Zmieniono nazwę elementu.
            </div>
        <?php elseif ($msg === 'deleted'): ?>
            <div class="card" style="border-left: 4px solid var(--warning); background: rgba(245, 158, 11, 0.1); padding: 10px 14px; margin-bottom: 16px; font-weight: 600; font-size: 13px;">
                ✓ Element został usunięty.
            </div>
        <?php elseif ($msg === 'uploaded'): ?>
            <div class="card" style="border-left: 4px solid var(--success); background: var(--success-subtle); padding: 10px 14px; margin-bottom: 16px; color: var(--success); font-weight: 600; font-size: 13px;">
                ✓ Pomyślnie wgrano pliki.
            </div>
        <?php endif; ?>

        <!-- Toolbar: Breadcrumbs & Action Buttons -->
        <div class="card" style="padding: 12px 16px; margin-bottom: 16px; border: 1px solid var(--border-subtle);">
            <div class="fm-toolbar" style="margin-bottom: 0;">
                <!-- Breadcrumbs -->
                <div class="fm-breadcrumbs">
                    <span style="color: var(--text-muted);">Ścieżka:</span>
                    <a href="plugins.php?id=file-manager"><strong>public_html</strong></a>
                    <?php foreach ($breadcrumbs as $b): ?>
                        <span style="color: var(--text-muted);">&rsaquo;</span>
                        <a href="plugins.php?id=file-manager&path=<?= urlencode($b['path']) ?>"><?= htmlspecialchars($b['name'], ENT_QUOTES, 'UTF-8') ?></a>
                    <?php endforeach; ?>
                </div>

                <!-- Right Action Buttons -->
                <div style="display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
                    <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="openNewFileModal()">
                        + Nowy plik
                    </button>
                    <button type="button" class="btn btn-secondary" style="padding: 6px 12px; font-size: 12px;" onclick="openNewFolderModal()">
                        + Nowy folder
                    </button>
                    <button type="button" class="btn btn-primary" style="padding: 6px 12px; font-size: 12px;" onclick="openUploadModal()">
                        ⬆️ Wgraj plik
                    </button>
                    <a href="plugins.php?id=file-manager&path=<?= urlencode($sub) ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;" title="Odśwież">
                        🔄
                    </a>
                    <input type="text" id="fm-quick-filter" placeholder="🔍 Filtruj pliki..." style="padding: 5px 10px; font-size: 12px; border-radius: 6px; border: 1px solid var(--border-subtle); background: var(--bg-card); color: var(--text-main); width: 140px;" oninput="filterFmItems(this.value)">
                </div>
            </div>
        </div>

        <!-- File Table -->
        <div class="table-container" style="border: 1px solid var(--border-subtle); border-radius: 10px; overflow: hidden;">
            <table class="pro-table" id="fm-files-table">
                <thead>
                    <tr>
                        <th style="width: 45%;">Nazwa</th>
                        <th style="width: 12%;">Rozmiar</th>
                        <th style="width: 13%;">Typ</th>
                        <th style="width: 10%;">Prawa</th>
                        <th style="width: 10%;">Data modyfikacji</th>
                        <th style="text-align: right; width: 10%;">Akcje</th>
                    </tr>
                </thead>
                <tbody>
                    <!-- Parent directory link -->
                    <?php if (!$isAtRoot): ?>
                        <tr class="fm-table-row">
                            <td colspan="6">
                                <a href="plugins.php?id=file-manager&path=<?= urlencode($parentSub) ?>" style="font-weight: 600; text-decoration: none; color: var(--primary); display: flex; align-items: center; gap: 8px; padding: 4px 0;">
                                    <span>📁 ⬆️ .. [Przejdź wyżej]</span>
                                </a>
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php if (empty($dirs) && empty($files)): ?>
                        <tr>
                            <td colspan="6" style="text-align: center; padding: 36px; color: var(--text-muted);">
                                📂 Katalog jest pusty.
                            </td>
                        </tr>
                    <?php endif; ?>

                    <!-- Directories -->
                    <?php foreach ($dirs as $d): ?>
                        <tr class="fm-table-row fm-item-row" data-name="<?= htmlspecialchars(strtolower($d['name']), ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <a href="plugins.php?id=file-manager&path=<?= urlencode($d['path']) ?>" style="font-weight: 600; text-decoration: none; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 16px;">📁</span>
                                    <span><?= htmlspecialchars($d['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <span style="font-size: 11px; color: var(--text-muted); font-weight: normal;">(<?= $d['items'] ?>)</span>
                                </a>
                            </td>
                            <td style="font-size: 12px; color: var(--text-muted);">&mdash;</td>
                            <td><span class="badge" style="background: var(--bg-subtle); font-size: 10px;">FOLDER</span></td>
                            <td style="font-family: monospace; font-size: 11px; color: var(--text-muted);"><?= $d['perms'] ?></td>
                            <td style="font-size: 12px; color: var(--text-muted);"><?= date('d.m.Y H:i', $d['mtime']) ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 4px;">
                                    <button type="button" class="btn btn-secondary" style="padding: 3px 6px; font-size: 11px;" title="Zmień nazwę" onclick="openRenameModal('<?= htmlspecialchars(addslashes($d['path']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($d['name']), ENT_QUOTES, 'UTF-8') ?>')">
                                        🏷️
                                    </button>
                                    <button type="button" class="btn btn-danger-ghost" style="padding: 3px 6px; font-size: 11px;" title="Usuń folder" onclick="confirmDeleteFmItem('<?= htmlspecialchars(addslashes($d['path']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($d['name']), ENT_QUOTES, 'UTF-8') ?>', true)">
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>

                    <!-- Files -->
                    <?php foreach ($files as $f): 
                        $info = $f['info'];
                        $downloadUrl = "plugins.php?id=file-manager&fm_action=download&file=" . urlencode($f['path']);
                        $rawUrl = "plugins.php?id=file-manager&fm_action=raw&file=" . urlencode($f['path']);
                    ?>
                        <tr class="fm-table-row fm-item-row" data-name="<?= htmlspecialchars(strtolower($f['name']), ENT_QUOTES, 'UTF-8') ?>">
                            <td>
                                <div style="display: flex; align-items: center; gap: 8px;">
                                    <span style="font-size: 16px;"><?= $info['icon'] ?></span>
                                    <?php if ($info['editable']): ?>
                                        <a href="javascript:void(0)" onclick="openFileEditor('<?= htmlspecialchars(addslashes($f['path']), ENT_QUOTES, 'UTF-8') ?>')" style="font-weight: 500; text-decoration: none; color: var(--text-main);">
                                            <?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </a>
                                    <?php else: ?>
                                        <span style="font-weight: 500; color: var(--text-main);">
                                            <?= htmlspecialchars($f['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td style="font-size: 12px; color: var(--text-muted);"><?= $f['size_formatted'] ?></td>
                            <td><span class="badge" style="background: var(--bg-subtle); font-size: 10px;"><?= $info['type'] ?></span></td>
                            <td style="font-family: monospace; font-size: 11px; color: var(--text-muted);"><?= $f['perms'] ?></td>
                            <td style="font-size: 12px; color: var(--text-muted);"><?= date('d.m.Y H:i', $f['mtime']) ?></td>
                            <td style="text-align: right; white-space: nowrap;">
                                <div style="display: inline-flex; gap: 4px;">
                                    <?php if ($info['previewable']): ?>
                                        <button type="button" class="btn btn-secondary" style="padding: 3px 6px; font-size: 11px;" title="Podgląd" onclick="openPreviewModal('<?= htmlspecialchars(addslashes($f['path']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($f['name']), ENT_QUOTES, 'UTF-8') ?>', '<?= $info['is_image'] ? 'image' : ($info['is_video'] ? 'video' : ($info['is_audio'] ? 'audio' : 'text')) ?>')">
                                            👁️
                                        </button>
                                    <?php endif; ?>

                                    <?php if ($info['editable']): ?>
                                        <button type="button" class="btn btn-secondary" style="padding: 3px 6px; font-size: 11px;" title="Edytuj kod" onclick="openFileEditor('<?= htmlspecialchars(addslashes($f['path']), ENT_QUOTES, 'UTF-8') ?>')">
                                            ✏️
                                        </button>
                                    <?php endif; ?>

                                    <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" style="padding: 3px 6px; font-size: 11px; text-decoration: none;" title="Pobierz plik">
                                        ⬇️
                                    </a>

                                    <button type="button" class="btn btn-secondary" style="padding: 3px 6px; font-size: 11px;" title="Zmień nazwę" onclick="openRenameModal('<?= htmlspecialchars(addslashes($f['path']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($f['name']), ENT_QUOTES, 'UTF-8') ?>')">
                                        🏷️
                                    </button>

                                    <button type="button" class="btn btn-danger-ghost" style="padding: 3px 6px; font-size: 11px;" title="Usuń plik" onclick="confirmDeleteFmItem('<?= htmlspecialchars(addslashes($f['path']), ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($f['name']), ENT_QUOTES, 'UTF-8') ?>', false)">
                                        🗑️
                                    </button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- MODAL 1: Code Editor -->
    <div id="fm-editor-modal" class="fm-modal-backdrop">
        <div class="fm-modal fm-editor-modal">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; padding-bottom: 8px; border-bottom: 1px solid var(--border-subtle);">
                <div style="display: flex; align-items: center; gap: 8px; min-width: 0;">
                    <span style="font-size: 18px;">✏️</span>
                    <strong id="fm-editor-filename" style="font-size: 14px; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></strong>
                    <span id="fm-editor-status" class="badge" style="display: none; background: rgba(16, 185, 129, 0.15); color: var(--success); font-size: 11px;">Zapisano</span>
                </div>
                <div style="display: flex; gap: 8px;">
                    <button type="button" id="fm-editor-save-btn" class="btn btn-primary" style="padding: 6px 14px; font-size: 12px;" onclick="saveEditorContent()">
                        💾 Zapisz (Ctrl+S)
                    </button>
                    <button type="button" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;" onclick="closeModal('fm-editor-modal')">
                        ✕ Zamknij
                    </button>
                </div>
            </div>
            <textarea id="fm-editor-textarea" class="fm-code-area" spellcheck="false"></textarea>
            <input type="hidden" id="fm-editor-path" value="">
        </div>
    </div>

    <!-- MODAL 2: File Preview (Media/Image/Text) -->
    <div id="fm-preview-modal" class="fm-modal-backdrop">
        <div class="fm-modal" style="max-width: 760px; text-align: center;">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 14px;">
                <h3 id="fm-preview-title" style="margin: 0; font-size: 15px; font-weight: 700; color: var(--text-main);"></h3>
                <button type="button" class="btn btn-secondary" style="padding: 4px 8px; font-size: 12px;" onclick="closeModal('fm-preview-modal')">&times;</button>
            </div>
            <div id="fm-preview-body" style="max-height: 70vh; overflow-y: auto; background: var(--bg-subtle); border-radius: 8px; padding: 12px; display: flex; align-items: center; justify-content: center;"></div>
        </div>
    </div>

    <!-- MODAL 3: New File -->
    <div id="fm-new-file-modal" class="fm-modal-backdrop">
        <div class="fm-modal">
            <h3 style="margin: 0 0 12px; font-size: 16px; font-weight: 700; color: var(--text-main);">Utwórz nowy plik</h3>
            <form method="post" action="plugins.php?id=file-manager">
                <input type="hidden" name="fm_action" value="create_file">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="current_dir" value="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px;">Nazwa pliku (np. script.php, style.css, custom.json)</label>
                    <input type="text" name="file_name" class="form-control" placeholder="nazwa-pliku.php" required autofocus>
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('fm-new-file-modal')">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Utwórz plik</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 4: New Folder -->
    <div id="fm-new-folder-modal" class="fm-modal-backdrop">
        <div class="fm-modal">
            <h3 style="margin: 0 0 12px; font-size: 16px; font-weight: 700; color: var(--text-main);">Utwórz nowy folder</h3>
            <form method="post" action="plugins.php?id=file-manager">
                <input type="hidden" name="fm_action" value="create_folder">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="current_dir" value="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px;">Nazwa folderu</label>
                    <input type="text" name="folder_name" class="form-control" placeholder="nowy-folder" required autofocus>
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('fm-new-folder-modal')">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Utwórz folder</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 5: Rename Item -->
    <div id="fm-rename-modal" class="fm-modal-backdrop">
        <div class="fm-modal">
            <h3 style="margin: 0 0 12px; font-size: 16px; font-weight: 700; color: var(--text-main);">Zmień nazwę</h3>
            <form method="post" action="plugins.php?id=file-manager">
                <input type="hidden" name="fm_action" value="rename">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="current_dir" value="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="target_item" id="fm-rename-target" value="">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px;">Nowa nazwa</label>
                    <input type="text" name="new_name" id="fm-rename-input" class="form-control" required autofocus>
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('fm-rename-modal')">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Zmień nazwę</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 6: Upload File(s) -->
    <div id="fm-upload-modal" class="fm-modal-backdrop">
        <div class="fm-modal">
            <h3 style="margin: 0 0 12px; font-size: 16px; font-weight: 700; color: var(--text-main);">Wgraj pliki do tego folderu</h3>
            <form method="post" action="plugins.php?id=file-manager" enctype="multipart/form-data">
                <input type="hidden" name="fm_action" value="upload">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="current_dir" value="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group" style="margin-bottom: 16px;">
                    <label class="form-label" style="font-size: 12px;">Wybierz jeden lub więcej plików</label>
                    <input type="file" name="upload_files[]" multiple class="form-control" required style="padding: 10px;">
                </div>
                <div style="display: flex; gap: 8px; justify-content: flex-end;">
                    <button type="button" class="btn btn-secondary" onclick="closeModal('fm-upload-modal')">Anuluj</button>
                    <button type="submit" class="btn btn-primary">Rozpocznij wgrywanie</button>
                </div>
            </form>
        </div>
    </div>

    <!-- MODAL 7: Delete Confirmation -->
    <div id="fm-delete-modal" class="fm-modal-backdrop">
        <div class="fm-modal" style="text-align: center; max-width: 440px;">
            <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: var(--danger); display: flex; align-items: center; justify-content: center; margin: 0 auto 14px; font-size: 22px;">
                🗑️
            </div>
            <h3 style="margin: 0 0 8px; font-size: 16px; font-weight: 700; color: var(--text-main);">Potwierdź usunięcie</h3>
            <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 18px; line-height: 1.5;">
                Czy na pewno chcesz bezpowrotnie usunąć <strong id="fm-delete-label" style="color: var(--text-main);"></strong>? Tej operacji nie można cofnąć!
            </p>
            <form method="post" action="plugins.php?id=file-manager">
                <input type="hidden" name="fm_action" value="delete">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="current_dir" value="<?= htmlspecialchars($sub, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="target_item" id="fm-delete-target" value="">
                <div style="display: flex; gap: 10px; justify-content: center;">
                    <button type="button" class="btn btn-secondary" style="flex: 1;" onclick="closeModal('fm-delete-modal')">Anuluj</button>
                    <button type="submit" class="btn btn-primary" style="flex: 1; background: var(--danger); border-color: var(--danger);">Usuń na stałe</button>
                </div>
            </form>
        </div>
    </div>

    <script>
    const FM_CSRF = <?= json_encode($csrf) ?>;

    function closeModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'none';
    }

    function openModal(id) {
        const el = document.getElementById(id);
        if (el) el.style.display = 'flex';
    }

    function openNewFileModal() { openModal('fm-new-file-modal'); }
    function openNewFolderModal() { openModal('fm-new-folder-modal'); }
    function openUploadModal() { openModal('fm-upload-modal'); }

    function openRenameModal(path, currentName) {
        document.getElementById('fm-rename-target').value = path;
        document.getElementById('fm-rename-input').value = currentName;
        openModal('fm-rename-modal');
    }

    function confirmDeleteFmItem(path, name, isDir) {
        document.getElementById('fm-delete-target').value = path;
        document.getElementById('fm-delete-label').textContent = (isDir ? 'folder: ' : 'plik: ') + name;
        openModal('fm-delete-modal');
    }

    // Code Editor handling
    function openFileEditor(path) {
        const modal = document.getElementById('fm-editor-modal');
        const filenameLabel = document.getElementById('fm-editor-filename');
        const textarea = document.getElementById('fm-editor-textarea');
        const pathInput = document.getElementById('fm-editor-path');
        const statusBadge = document.getElementById('fm-editor-status');

        statusBadge.style.display = 'none';
        filenameLabel.textContent = 'Ładowanie pliku: ' + path + '...';
        textarea.value = 'Wczytywanie zawartości pliku...';
        openModal('fm-editor-modal');

        fetch('plugins.php?id=file-manager&fm_action=read&file=' + encodeURIComponent(path))
            .then(r => r.json())
            .then(data => {
                if (data.ok) {
                    filenameLabel.textContent = data.rel_path;
                    textarea.value = data.content;
                    pathInput.value = data.rel_path;
                    textarea.focus();
                } else {
                    alert(data.error || 'Nie udało się wczytać pliku.');
                    closeModal('fm-editor-modal');
                }
            })
            .catch(err => {
                alert('Błąd sieci podczas wczytywania pliku.');
                closeModal('fm-editor-modal');
            });
    }

    function saveEditorContent() {
        const path = document.getElementById('fm-editor-path').value;
        const content = document.getElementById('fm-editor-textarea').value;
        const btn = document.getElementById('fm-editor-save-btn');
        const statusBadge = document.getElementById('fm-editor-status');

        if (!path) return;

        btn.disabled = true;
        btn.textContent = '⏳ Zapisywanie...';

        const body = new URLSearchParams();
        body.set('fm_action', 'save');
        body.set('file', path);
        body.set('content', content);
        body.set('csrf_token', FM_CSRF);

        fetch('plugins.php?id=file-manager', {
            method: 'POST',
            body: body,
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
        .then(r => r.json())
        .then(data => {
            btn.disabled = false;
            btn.textContent = '💾 Zapisz (Ctrl+S)';
            if (data.ok) {
                statusBadge.style.display = 'inline-block';
                statusBadge.textContent = 'Zapisano (' + new Date().toLocaleTimeString() + ')';
                setTimeout(() => { statusBadge.style.display = 'none'; }, 4000);
            } else {
                alert(data.error || 'Błąd podczas zapisywania.');
            }
        })
        .catch(err => {
            btn.disabled = false;
            btn.textContent = '💾 Zapisz (Ctrl+S)';
            alert('Błąd sieci podczas zapisywania.');
        });
    }

    // Ctrl+S / Cmd+S in editor
    document.addEventListener('keydown', (e) => {
        if ((e.ctrlKey || e.metaKey) && e.key === 's') {
            const editorModal = document.getElementById('fm-editor-modal');
            if (editorModal && editorModal.style.display === 'flex') {
                e.preventDefault();
                saveEditorContent();
            }
        }
    });

    // File Preview handling
    function openPreviewModal(path, name, type) {
        const titleEl = document.getElementById('fm-preview-title');
        const bodyEl = document.getElementById('fm-preview-body');
        titleEl.textContent = 'Podgląd: ' + name;
        bodyEl.innerHTML = 'Ładowanie podglądu...';
        openModal('fm-preview-modal');

        const rawUrl = 'plugins.php?id=file-manager&fm_action=raw&file=' + encodeURIComponent(path);

        if (type === 'image') {
            bodyEl.innerHTML = '<img src="' + rawUrl + '" alt="' + name + '" style="max-width: 100%; max-height: 60vh; border-radius: 6px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">';
        } else if (type === 'video') {
            bodyEl.innerHTML = '<video controls autoplay style="max-width: 100%; max-height: 60vh; border-radius: 6px;"><source src="' + rawUrl + '"></video>';
        } else if (type === 'audio') {
            bodyEl.innerHTML = '<audio controls autoplay style="width: 100%; margin: 20px 0;"><source src="' + rawUrl + '"></audio>';
        } else {
            // Text preview
            fetch('plugins.php?id=file-manager&fm_action=read&file=' + encodeURIComponent(path))
                .then(r => r.json())
                .then(data => {
                    if (data.ok) {
                        bodyEl.innerHTML = '<pre style="text-align: left; width: 100%; max-height: 55vh; overflow: auto; font-family: monospace; font-size: 12px; margin: 0; white-space: pre-wrap; word-break: break-all;">' + escapeHtml(data.content) + '</pre>';
                    } else {
                        bodyEl.innerHTML = '<p style="color:var(--danger);">' + (data.error || 'Nie można wczytać podglądu.') + '</p>';
                    }
                });
        }
    }

    function escapeHtml(str) {
        return (str || '').replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Live search filter in current directory
    function filterFmItems(query) {
        const q = (query || '').toLowerCase().trim();
        const rows = document.querySelectorAll('.fm-item-row');
        rows.forEach(r => {
            const name = r.getAttribute('data-name') || '';
            r.style.display = (q === '' || name.includes(q)) ? '' : 'none';
        });
    }

    // Close modals on clicking backdrop
    document.querySelectorAll('.fm-modal-backdrop').forEach(modal => {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) modal.style.display = 'none';
        });
    });
    </script>
    <?php
}
