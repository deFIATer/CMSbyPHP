<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;
use Core\Router;
use Core\ProtectedMedia;

Auth::requireCapability('manage_pages');

$db = Database::getConnection();
$basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

// 1. Handle privacy and password updates
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_privacy') {
    if (!Security::verifyCsrfToken($_POST['csrf'] ?? '')) {
        if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Invalid CSRF token']);
            exit;
        }
        die('Invalid CSRF token');
    }

    $mediaId = (int)($_POST['media_id'] ?? 0);
    $isPrivate = !empty($_POST['is_private']) && $_POST['is_private'] === '1';
    $password = isset($_POST['password']) ? trim((string)$_POST['password']) : null;

    $result = ProtectedMedia::setPrivacy($mediaId, $isPrivate, $password);

    if (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) || (isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json'))) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($result);
        exit;
    }

    header('Location: media.php?updated=1');
    exit;
}

// 2. Handle media item deletion
if (isset($_GET['delete']) && isset($_GET['csrf'])) {
    if (Security::verifyCsrfToken($_GET['csrf'])) {
        $delId = (int)$_GET['delete'];
        $stmt = $db->prepare("SELECT filepath, webp_path FROM media WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $delId]);
        $fileRecord = $stmt->fetch();

        if ($fileRecord) {
            $baseDir = dirname(__DIR__);
            $physicalPath = $baseDir . $fileRecord['filepath'];
            if (file_exists($physicalPath)) {
                @unlink($physicalPath);
            }

            // Also check protected folder
            $protectedAlt = ProtectedMedia::getProtectedDir() . '/' . basename($fileRecord['filepath']);
            if (file_exists($protectedAlt)) {
                @unlink($protectedAlt);
            }

            if (!empty($fileRecord['webp_path'])) {
                $webpPhysical = $baseDir . $fileRecord['webp_path'];
                if (file_exists($webpPhysical)) {
                    @unlink($webpPhysical);
                }
            }

            $db->prepare("DELETE FROM media WHERE id = :id")->execute([':id' => $delId]);
        }

        header('Location: media.php?deleted=1');
        exit;
    }
}

/**
 * Auto-synchronize physical files in uploads/ and data/protected_files/ with media table
 */
function syncPhysicalMedia(\PDO $db): void {
    $existing = $db->query("SELECT filepath FROM media")->fetchAll(\PDO::FETCH_COLUMN) ?: [];
    $existingMap = [];
    foreach ($existing as $p) {
        $clean = ltrim($p, '/');
        $existingMap[$clean] = true;
        $existingMap['/' . $clean] = true;
    }

    $baseDir = dirname(__DIR__);
    $candidates = [];

    // 1. Scan uploads
    $uploadsDir = $baseDir . '/uploads';
    if (is_dir($uploadsDir)) {
        foreach (scandir($uploadsDir) ?: [] as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess' || $file === 'cache' || is_dir($uploadsDir . '/' . $file)) {
                continue;
            }
            $relPath = '/uploads/' . $file;
            if (!isset($existingMap[$relPath])) {
                $candidates[] = [
                    'file' => $file,
                    'path' => $relPath,
                    'full' => $uploadsDir . '/' . $file,
                    'private' => 0
                ];
            }
        }
    }

    // 2. Scan data/protected_files
    $protDir = $baseDir . '/data/protected_files';
    if (is_dir($protDir)) {
        foreach (scandir($protDir) ?: [] as $file) {
            if ($file === '.' || $file === '..' || $file === '.htaccess' || is_dir($protDir . '/' . $file)) {
                continue;
            }
            $relPath = '/data/protected_files/' . $file;
            if (!isset($existingMap[$relPath])) {
                $candidates[] = [
                    'file' => $file,
                    'path' => $relPath,
                    'full' => $protDir . '/' . $file,
                    'private' => 1
                ];
            }
        }
    }

    if (!empty($candidates)) {
        $insert = $db->prepare("
            INSERT INTO media (filename, filepath, mime_type, file_size, webp_path, is_private, user_id, created_at)
            VALUES (:f, :p, :m, :s, :w, :ip, 1, :c)
        ");
        foreach ($candidates as $c) {
            $mime = 'application/octet-stream';
            if (function_exists('finfo_open')) {
                $finfo = finfo_open(FILEINFO_MIME_TYPE);
                $mime = finfo_file($finfo, $c['full']) ?: 'application/octet-stream';
                finfo_close($finfo);
            }
            $size = @filesize($c['full']) ?: 0;
            $mtime = date('Y-m-d H:i:s', @filemtime($c['full']) ?: time());

            $insert->execute([
                ':f'  => $c['file'],
                ':p'  => $c['path'],
                ':m'  => $mime,
                ':s'  => $size,
                ':w'  => null,
                ':ip' => $c['private'],
                ':c'  => $mtime
            ]);
        }
    }
}

// Ensure all physical assets on server are registered
syncPhysicalMedia($db);

// Fetch all uploaded assets from database
$mediaItems = $db->query("
    SELECT m.*, u.username as uploader 
    FROM media m 
    LEFT JOIN users u ON m.user_id = u.id 
    ORDER BY m.created_at DESC
")->fetchAll();

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header">
    <div>
        <h1 class="page-title"><?= _e('Media Library') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;"><?= _e('Manage site assets, videos, documents, and password-protected files') ?></p>
    </div>
    <button type="button" class="btn btn-primary" onclick="document.getElementById('file-upload-input').click()">
        <?= _e('+ Upload File') ?>
    </button>
</div>

<?php if (isset($_GET['deleted'])): ?>
    <div class="card" style="border-left: 4px solid var(--success); padding: 12px; margin-bottom: 20px;">
        <?= _e('Media asset removed successfully.') ?>
    </div>
<?php endif; ?>

<?php if (isset($_GET['updated'])): ?>
    <div class="card" style="border-left: 4px solid var(--primary); padding: 12px; margin-bottom: 20px;">
        <?= _e('Media privacy and password updated successfully.') ?>
    </div>
<?php endif; ?>

<div id="drop-zone" class="card" style="border: 2px dashed var(--border-strong); text-align: center; padding: 28px 20px; cursor: pointer; transition: all 0.2s ease; background: var(--bg-surface);">
    <input type="file" id="file-upload-input" multiple accept="image/*,video/*,audio/*,.pdf,.doc,.docx,.xls,.xlsx,.zip,.txt" style="display: none;">
    <svg style="width: 38px; height: 38px; color: var(--primary); margin: 0 auto 8px; display: block;" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
        <path stroke-linecap="round" stroke-linejoin="round" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>
    </svg>
    <p style="font-weight: 600; font-size: 15px; margin-bottom: 4px; color: var(--text-main);"><?= _e('Drag and drop files here, or click to browse') ?></p>
    <p style="font-size: 12px; color: var(--text-muted); margin: 0 0 14px 0;"><?= _e('Supports Videos (MP4, WebM, MOV), Audio (MP3, WAV), Images (JPG, PNG, WebP, SVG), PDF, Documents & Archives') ?></p>

    <!-- Optional direct privacy toggle on upload (dedicated new line) -->
    <div style="display: flex; justify-content: center; width: 100%; margin-top: 4px;">
        <div style="display: inline-flex; align-items: center; gap: 8px; background: var(--bg-card); padding: 8px 16px; border-radius: 12px; border: 1px solid var(--border-subtle); flex-wrap: wrap; max-width: 100%; justify-content: center; box-shadow: 0 1px 3px rgba(0,0,0,0.05);" onclick="event.stopPropagation()">
            <input type="checkbox" id="upload-is-private" onchange="toggleUploadPasswordInput(this.checked)" style="cursor: pointer; width: 16px; height: 16px;">
            <label for="upload-is-private" style="font-size: 12.5px; font-weight: 600; cursor: pointer; color: var(--text-main); display: flex; align-items: center; gap: 6px;">
                🔒 <?= _e('Upload as private with password protection') ?>
            </label>
            <div id="upload-pw-container" style="display: none; align-items: center; gap: 6px; margin-left: 4px;">
                <input type="text" id="upload-password" placeholder="<?= _e('Enter password...') ?>" style="padding: 6px 12px; font-size: 12px; border-radius: 8px; border: 1px solid var(--border-subtle); background: var(--bg-surface, #ffffff); color: var(--text-main); width: 160px; max-width: 100%;">
            </div>
        </div>
    </div>

    <!-- Upload Dashboard (Live Progress, Speed, Bytes & ETA) -->
    <div id="upload-dashboard" style="display: none; margin-top: 20px; padding: 18px 22px; background: var(--bg-card, #ffffff); border: 1px solid var(--border-subtle); border-radius: 14px; text-align: left; box-shadow: 0 8px 24px rgba(0,0,0,0.08); max-width: 680px; margin-left: auto; margin-right: auto;" onclick="event.stopPropagation()">
        <!-- Header: File Icon, Name, Queue & Percent Badge -->
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-bottom: 12px;">
            <div style="display: flex; align-items: center; gap: 12px; min-width: 0; flex: 1;">
                <span id="upload-file-icon" style="font-size: 28px; line-height: 1; flex-shrink: 0;">🎬</span>
                <div style="min-width: 0; flex: 1;">
                    <div id="upload-file-name" style="font-weight: 700; font-size: 14px; color: var(--text-main); white-space: nowrap; overflow: hidden; text-overflow: ellipsis;"></div>
                    <div id="upload-queue-info" style="font-size: 11px; color: var(--text-muted); margin-top: 2px;"></div>
                </div>
            </div>
            <div style="display: flex; align-items: center; gap: 10px; flex-shrink: 0;">
                <span id="upload-percent-badge" class="badge" style="background: rgba(37, 99, 235, 0.12); color: #2563eb; font-weight: 800; font-size: 13px; padding: 5px 12px; border-radius: 8px;">0%</span>
                <button type="button" id="upload-cancel-btn" class="btn btn-danger-ghost" style="padding: 4px 10px; font-size: 12px; border-radius: 6px; line-height: 1.2;" onclick="cancelCurrentUpload()"><?= _e('Cancel') ?></button>
            </div>
        </div>

        <!-- Animated Progress Bar -->
        <div style="width: 100%; height: 10px; background: var(--bg-subtle, #e2e8f0); border-radius: 9999px; overflow: hidden; position: relative;">
            <div id="upload-progress-fill" style="width: 0%; height: 100%; background: linear-gradient(90deg, #2563eb, #6366f1); border-radius: 9999px; transition: width 0.15s ease; box-shadow: 0 0 10px rgba(37,99,235,0.4);"></div>
        </div>

        <!-- Transfer Stats: Size / Total, Speed, ETA -->
        <div style="display: flex; align-items: center; justify-content: space-between; gap: 12px; margin-top: 12px; font-size: 12px; color: var(--text-muted); flex-wrap: wrap;">
            <div style="display: flex; align-items: center; gap: 14px; flex-wrap: wrap;">
                <span id="upload-data-stat" style="font-weight: 600; color: var(--text-main);">0 MB / 0 MB</span>
                <span id="upload-speed-stat" style="display: inline-flex; align-items: center; gap: 4px; color: #10b981; font-weight: 600;">⚡ 0 KB/s</span>
                <span id="upload-eta-stat" style="display: inline-flex; align-items: center; gap: 4px;">⏱️ <?= _e('Remaining:') ?> --</span>
            </div>
            <div id="upload-status-text" style="font-size: 12px; font-style: italic; color: var(--text-muted); font-weight: 500;"><?= _e('Uploading...') ?></div>
        </div>

        <!-- Multi-file completed list (if uploading multiple files) -->
        <div id="upload-completed-list" style="display: none; margin-top: 12px; padding-top: 10px; border-top: 1px dashed var(--border-subtle); font-size: 11px; max-height: 90px; overflow-y: auto;"></div>
    </div>
</div>

<div class="media-grid-container" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(230px, 1fr)); gap: 20px; margin-top: 24px;">
    <?php if (empty($mediaItems)): ?>
        <div class="card" style="grid-column: 1 / -1; text-align: center; padding: 48px; color: var(--text-muted);">
            <?= _e('No media assets found in library.') ?>
        </div>
    <?php else: ?>
        <?php foreach ($mediaItems as $item):
            $isPrivate = !empty($item['is_private']);
            $accessUrl = ProtectedMedia::getAccessUrl($item, false);
            $absoluteUrl = ProtectedMedia::getAccessUrl($item, true);
            $fileSizeKb = round((int)$item['file_size'] / 1024, 1);
            $fileSizeFormatted = $fileSizeKb > 1024 ? round($fileSizeKb / 1024, 2) . ' MB' : $fileSizeKb . ' KB';
            $deleteUrl = "media.php?delete={$item['id']}&csrf=" . Security::generateCsrfToken();
            $category = ProtectedMedia::detectMediaTypeCategory($item['mime_type'], $item['filename']);
            $isImage = ($category['type'] === 'image');
        ?>
            <div class="card media-card" id="media-card-<?= $item['id'] ?>" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; box-shadow: var(--shadow-sm); background: var(--bg-card); border: 1px solid var(--border-card); border-radius: var(--radius-md); position: relative;">
                
                <!-- Status Badges -->
                <div style="position: absolute; top: 10px; left: 10px; z-index: 10;">
                    <?php if ($isPrivate): ?>
                        <span class="privacy-badge" style="background: rgba(245, 158, 11, 0.95); color: #ffffff; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.02em;">
                            🔒 <?= _e('Private (Password)') ?>
                        </span>
                    <?php else: ?>
                        <span class="privacy-badge" style="background: rgba(16, 185, 129, 0.95); color: #ffffff; font-size: 10px; font-weight: 700; padding: 3px 8px; border-radius: 9999px; display: inline-flex; align-items: center; gap: 4px; box-shadow: 0 2px 4px rgba(0,0,0,0.3); letter-spacing: 0.02em;">
                            🌐 <?= _e('Public') ?>
                        </span>
                    <?php endif; ?>
                </div>

                <div style="position: absolute; top: 10px; right: 10px; z-index: 10;">
                    <span style="background: rgba(0, 0, 0, 0.65); color: #f1f5f9; font-size: 10px; font-weight: 700; padding: 3px 7px; border-radius: 6px; backdrop-filter: blur(4px);">
                        <?= strtoupper(pathinfo($item['filename'], PATHINFO_EXTENSION) ?: 'FILE') ?>
                    </span>
                </div>

                <!-- Preview Area -->
                <div style="width: 100%; height: 145px; background: var(--bg-subtle); border-bottom: 1px solid var(--border-subtle); display: flex; align-items: center; justify-content: center; position: relative; overflow: hidden;">
                    <?php if ($isImage && !$isPrivate): ?>
                        <img src="<?= htmlspecialchars($accessUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= Security::sanitize($item['filename']) ?>" style="max-width: 100%; max-height: 100%; width: auto; height: auto; object-fit: contain;">
                    <?php elseif ($category['type'] === 'video'): ?>
                        <div style="text-align: center; color: var(--primary);">
                            <div style="width: 50px; height: 50px; border-radius: 50%; background: rgba(59, 130, 246, 0.15); display: flex; align-items: center; justify-content: center; margin: 0 auto 6px;">
                                <svg style="width: 24px; height: 24px; margin-left: 2px;" fill="currentColor" viewBox="0 0 24 24">
                                    <path d="M8 5v14l11-7z"/>
                                </svg>
                            </div>
                            <span style="font-size: 11px; font-weight: 600; color: var(--text-muted);"><?= _e('Video Stream') ?></span>
                        </div>
                    <?php elseif ($category['type'] === 'audio'): ?>
                        <div style="text-align: center;">
                            <div style="font-size: 40px; margin-bottom: 4px;">🎵</div>
                            <span style="font-size: 11px; font-weight: 600; color: var(--text-muted);"><?= _e('Audio File') ?></span>
                        </div>
                    <?php elseif ($category['type'] === 'pdf'): ?>
                        <div style="text-align: center;">
                            <div style="font-size: 40px; margin-bottom: 4px;">📄</div>
                            <span style="font-size: 11px; font-weight: 600; color: var(--text-muted);"><?= _e('PDF Document') ?></span>
                        </div>
                    <?php else: ?>
                        <div style="text-align: center;">
                            <div style="font-size: 40px; margin-bottom: 4px;"><?= $category['icon'] ?></div>
                            <span style="font-size: 11px; font-weight: 600; color: var(--text-muted);"><?= $category['label'] ?></span>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Info & Actions -->
                <div style="padding: 14px; flex-grow: 1; display: flex; flex-direction: column; justify-content: space-between; background: var(--bg-card);">
                    <div>
                        <span style="display: block; font-weight: 600; font-size: 13px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: var(--text-main);" title="<?= Security::sanitize($item['filename']) ?>">
                            <?= Security::sanitize($item['filename']) ?>
                        </span>
                        <div style="display: flex; justify-content: space-between; font-size: 11px; color: var(--text-muted); margin-top: 3px;">
                            <span><?= $fileSizeFormatted ?></span>
                            <span><?= date('d.m.Y', strtotime($item['created_at'])) ?></span>
                        </div>
                    </div>

                    <div style="margin-top: 14px; display: flex; flex-direction: column; gap: 6px;">
                        <!-- Primary Action Buttons -->
                        <div style="display: flex; gap: 6px;">
                            <button type="button" class="btn btn-secondary" style="padding: 5px 8px; font-size: 11px; flex-grow: 1; display: flex; align-items: center; justify-content: center; gap: 4px;" onclick="openPrivacyModal(<?= (int)$item['id'] ?>, '<?= htmlspecialchars(addslashes($item['filename']), ENT_QUOTES, 'UTF-8') ?>', <?= $isPrivate ? 'true' : 'false' ?>, '<?= htmlspecialchars(addslashes($accessUrl), ENT_QUOTES, 'UTF-8') ?>')">
                                🔒 <?= _e('Password') ?>
                            </button>
                            <button type="button" class="btn btn-secondary" style="padding: 5px 8px; font-size: 11px; flex-grow: 1;" onclick="copyMediaUrl('<?= htmlspecialchars($accessUrl, ENT_QUOTES, 'UTF-8') ?>', this)">
                                <?= _e('Copy URL') ?>
                            </button>
                            <a href="<?= htmlspecialchars($accessUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener" class="btn btn-secondary" style="padding: 5px 8px; font-size: 11px; display: flex; align-items: center; justify-content: center;" title="<?= _e('Open URL') ?>">
                                ↗
                            </a>
                        </div>

                        <!-- Secondary Row: Edit Image & Delete -->
                        <div style="display: flex; gap: 6px; justify-content: space-between; align-items: center;">
                            <?php if ($isImage && !$isPrivate): ?>
                                <button type="button" class="btn btn-secondary" style="padding: 3px 8px; font-size: 10.5px;" onclick="openImageEditor('<?= htmlspecialchars($accessUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= Security::sanitize($item['filename']) ?>')">
                                    ✏️ <?= _e('Edit') ?>
                                </button>
                            <?php else: ?>
                                <span style="font-size: 10px; color: var(--text-muted);"><?= $isPrivate ? _e('Protection active') : _e('Direct link') ?></span>
                            <?php endif; ?>

                            <button type="button" class="btn btn-danger-ghost" style="padding: 2px 7px; font-size: 13px; font-weight: bold; line-height: 1;" onclick="confirmDeleteMedia('<?= htmlspecialchars($deleteUrl, ENT_QUOTES, 'UTF-8') ?>', '<?= htmlspecialchars(addslashes($item['filename']), ENT_QUOTES, 'UTF-8') ?>')">
                                &times;
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- Modal: Privacy & Password Configuration -->
<div id="privacy-modal" style="display: none; position: fixed; inset: 0; background: var(--modal-overlay); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(6px);">
    <div class="card" style="width: 100%; max-width: 480px; padding: 28px; background: var(--modal-bg); border-radius: 14px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.6); border: 1px solid var(--border-subtle); text-align: left;">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px;">
            <h3 style="font-size: 17px; font-weight: 700; margin: 0; color: var(--text-main); display: flex; align-items: center; gap: 8px;">
                🔒 <?= _e('File Privacy & Password Protection') ?>
            </h3>
            <button type="button" onclick="closePrivacyModal()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px; line-height: 1.5;">
            <?= _e('Public files can be accessed directly by anyone with the link. Private files require entering a password before viewing or downloading.') ?>
        </p>

        <form id="privacy-form" onsubmit="savePrivacySettings(event)">
            <input type="hidden" id="privacy-media-id" name="media_id" value="">
            <input type="hidden" name="csrf" value="<?= Security::generateCsrfToken() ?>">
            <input type="hidden" name="action" value="set_privacy">

            <div style="background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: 10px; padding: 14px; margin-bottom: 20px;">
                <div style="font-size: 11px; text-transform: uppercase; letter-spacing: 0.05em; color: var(--text-muted); margin-bottom: 4px;"><?= _e('Selected File') ?>:</div>
                <div id="privacy-file-name" style="font-size: 14px; font-weight: 600; color: var(--text-main); word-break: break-all;"></div>
            </div>

            <!-- Privacy Toggle Switch -->
            <div style="margin-bottom: 20px; display: flex; align-items: center; justify-content: space-between; padding: 12px 14px; background: rgba(0,0,0,0.1); border-radius: 10px; border: 1px solid var(--border-subtle);">
                <div>
                    <label for="privacy-toggle-checkbox" style="font-size: 14px; font-weight: 600; color: var(--text-main); cursor: pointer; display: block; margin-bottom: 2px;">
                        <?= _e('Private File (Password Protected)') ?>
                    </label>
                    <span style="font-size: 12px; color: var(--text-muted);"><?= _e('Block direct access and require password') ?></span>
                </div>
                <input type="checkbox" id="privacy-toggle-checkbox" name="is_private" value="1" style="width: 20px; height: 20px; cursor: pointer;" onchange="togglePasswordFields(this.checked)">
            </div>

            <!-- Password Fields -->
            <div id="password-fields-group" style="margin-bottom: 20px; display: none;">
                <label for="privacy-password-input" style="display: block; font-size: 13px; font-weight: 600; margin-bottom: 6px; color: var(--text-main);">
                    <?= _e('File Password') ?> <span id="pwd-required-star" style="color: var(--danger);">*</span>
                </label>
                <div style="display: flex; gap: 8px;">
                    <div style="position: relative; flex-grow: 1;">
                        <input type="password" id="privacy-password-input" name="password" class="form-control" placeholder="<?= _e('Set file password...') ?>" style="width: 100%; padding: 10px 38px 10px 12px; font-size: 13px; border-radius: 8px; border: 1px solid var(--border-subtle); background: var(--bg-card); color: var(--text-main);">
                        <button type="button" onclick="toggleModalPasswordView()" style="position: absolute; right: 8px; top: 50%; transform: translateY(-50%); background: none; border: none; cursor: pointer; color: var(--text-muted); padding: 4px;">
                            👁️
                        </button>
                    </div>
                    <button type="button" class="btn btn-secondary" onclick="generateRandomPassword()" style="font-size: 12px; white-space: nowrap; padding: 8px 12px;">
                        🎲 <?= _e('Generate') ?>
                    </button>
                </div>
                <span style="font-size: 11px; color: var(--text-muted); display: block; margin-top: 6px;">
                    <?= _e('Leave blank to keep existing password when editing a private file.') ?>
                </span>
            </div>

            <!-- Link Preview Box -->
            <div style="background: var(--bg-subtle); border: 1px solid var(--border-subtle); border-radius: 8px; padding: 10px 12px; margin-bottom: 24px;">
                <div style="font-size: 11px; color: var(--text-muted); margin-bottom: 2px;"><?= _e('Access Link for Visitors') ?>:</div>
                <div id="privacy-link-preview" style="font-size: 12px; color: var(--primary); font-family: monospace; word-break: break-all;"></div>
            </div>

            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closePrivacyModal()"><?= _e('Cancel') ?></button>
                <button type="submit" id="save-privacy-btn" class="btn btn-primary"><?= _e('Save Changes') ?></button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Delete Confirmation -->
<div id="delete-confirm-modal" style="display: none; position: fixed; inset: 0; background: var(--modal-overlay); z-index: 2000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div class="card" style="width: 100%; max-width: 420px; padding: 24px; background: var(--modal-bg); border-radius: 12px; box-shadow: 0 25px 50px -12px rgba(0,0,0,0.5); border: 1px solid var(--border-subtle); text-align: center;">
        <div style="width: 48px; height: 48px; border-radius: 50%; background: rgba(239, 68, 68, 0.15); color: var(--danger); display: flex; align-items: center; justify-content: center; margin: 0 auto 16px;">
            <svg style="width: 24px; height: 24px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/>
            </svg>
        </div>
        <h3 style="font-size: 17px; font-weight: 700; margin: 0 0 8px; color: var(--text-main);"><?= _e('Delete Media Asset') ?></h3>
        <p style="font-size: 13px; color: var(--text-muted); margin: 0 0 20px; line-height: 1.5;">
            <?= _e('Are you sure you want to permanently delete') ?> <strong id="delete-filename-placeholder" style="color: var(--text-main);"></strong>? <?= _e('This action cannot be undone.') ?>
        </p>
        <div style="display: flex; gap: 10px; justify-content: center;">
            <button type="button" class="btn btn-secondary" style="flex: 1; padding: 9px;" onclick="closeDeleteModal()"><?= _e('Cancel') ?></button>
            <a id="confirm-delete-link" href="#" class="btn btn-primary" style="background: var(--danger); border-color: var(--danger); flex: 1; padding: 9px; text-decoration: none; text-align: center;">
                <?= _e('Delete') ?>
            </a>
        </div>
    </div>
</div>

<!-- Modal: Canvas Image Editor -->
<div id="image-editor-modal" style="display: none; position: fixed; inset: 0; background: var(--modal-overlay); z-index: 1000; align-items: center; justify-content: center; padding: 20px; backdrop-filter: blur(4px);">
    <div style="background: var(--modal-bg); border-radius: var(--radius-md); max-width: 900px; width: 100%; max-height: 90vh; display: flex; flex-direction: column; overflow: hidden; box-shadow: var(--shadow-md); border: 1px solid var(--border-subtle);">
        <div style="padding: 16px 20px; border-bottom: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface);">
            <h3 style="font-size: 16px; font-weight: 700; margin: 0; color: var(--text-main);"><?= _e('Canvas Image Editor') ?></h3>
            <button type="button" onclick="closeImageEditor()" style="background: none; border: none; font-size: 20px; cursor: pointer; color: var(--text-muted);">&times;</button>
        </div>

        <div style="padding: 14px 20px; background: var(--bg-subtle); border-bottom: 1px solid var(--border-subtle); display: flex; gap: 10px; flex-wrap: wrap; align-items: center;">
            <button type="button" class="btn btn-secondary" onclick="rotateImage(90)"><?= _e('Rotate 90°') ?></button>
            <button type="button" class="btn btn-secondary" onclick="flipImage('h')"><?= _e('Flip Horizontal') ?></button>
            <button type="button" class="btn btn-secondary" onclick="flipImage('v')"><?= _e('Flip Vertical') ?></button>
            <select id="aspect-ratio-selector" class="form-control" style="width: auto; padding: 6px 12px; font-size: 13px;" onchange="applyAspectCrop(this.value)">
                <option value="free"><?= _e('Original Ratio') ?></option>
                <option value="16:9">16:9 (<?= _e('Landscape') ?>)</option>
                <option value="4:3">4:3 (<?= _e('Standard') ?>)</option>
                <option value="1:1">1:1 (<?= _e('Square') ?>)</option>
            </select>
        </div>

        <div style="padding: 20px; overflow: auto; flex-grow: 1; display: flex; justify-content: center; align-items: center; background: var(--bg-main);">
            <canvas id="editor-canvas" style="max-width: 100%; max-height: 55vh; box-shadow: 0 4px 12px rgba(0,0,0,0.4);"></canvas>
        </div>

        <div style="padding: 16px 20px; border-top: 1px solid var(--border-subtle); display: flex; justify-content: space-between; align-items: center; background: var(--bg-surface);">
            <span id="canvas-resolution" style="font-size: 12px; color: var(--text-muted);"></span>
            <div style="display: flex; gap: 10px;">
                <button type="button" class="btn btn-secondary" onclick="closeImageEditor()"><?= _e('Cancel') ?></button>
                <button type="button" id="save-canvas-btn" class="btn btn-primary" onclick="saveEditedImage()"><?= _e('Save as New Image') ?></button>
            </div>
        </div>
    </div>
</div>

<script>
const CSRF_TOKEN = '<?= Security::generateCsrfToken() ?>';
const BASE_SUBDIR = '<?= $basePrefix ?>';

// -------------------------------------------------------------------------
// Privacy & Password Protection Modal
// -------------------------------------------------------------------------
const privacyModal = document.getElementById('privacy-modal');
const privacyForm = document.getElementById('privacy-form');
const privacyMediaId = document.getElementById('privacy-media-id');
const privacyFileName = document.getElementById('privacy-file-name');
const privacyToggle = document.getElementById('privacy-toggle-checkbox');
const passwordFieldsGroup = document.getElementById('password-fields-group');
const passwordInput = document.getElementById('privacy-password-input');
const linkPreview = document.getElementById('privacy-link-preview');
const savePrivacyBtn = document.getElementById('save-privacy-btn');

let activeMediaItem = null;

function openPrivacyModal(id, filename, isPrivate, currentUrl) {
    activeMediaItem = { id, filename, isPrivate, currentUrl };
    privacyMediaId.value = id;
    privacyFileName.textContent = filename;
    privacyToggle.checked = isPrivate;
    passwordInput.value = '';
    
    togglePasswordFields(isPrivate);
    updateLinkPreview(isPrivate);
    privacyModal.style.display = 'flex';
}

function closePrivacyModal() {
    privacyModal.style.display = 'none';
}

privacyModal.addEventListener('click', (e) => {
    if (e.target === privacyModal) closePrivacyModal();
});

function togglePasswordFields(isPrivate) {
    passwordFieldsGroup.style.display = isPrivate ? 'block' : 'none';
    updateLinkPreview(isPrivate);
}

function updateLinkPreview(isPrivate) {
    if (!activeMediaItem) return;
    const origin = window.location.origin;
    if (isPrivate) {
        linkPreview.textContent = origin + BASE_SUBDIR + '/file/' + activeMediaItem.id + '/' + encodeURIComponent(activeMediaItem.filename);
    } else {
        linkPreview.textContent = origin + activeMediaItem.currentUrl;
    }
}

function toggleModalPasswordView() {
    passwordInput.type = passwordInput.type === 'password' ? 'text' : 'password';
}

function generateRandomPassword() {
    const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
    let res = '';
    for (let i = 0; i < 10; i++) {
        res += chars.charAt(Math.floor(Math.random() * chars.length));
    }
    passwordInput.type = 'text';
    passwordInput.value = res;
}

function savePrivacySettings(e) {
    e.preventDefault();
    const isPrivate = privacyToggle.checked;
    const password = passwordInput.value.trim();

    if (isPrivate && !activeMediaItem.isPrivate && password === '') {
        alert('<?= _e('Please enter a password for the private file.') ?>');
        passwordInput.focus();
        return;
    }

    savePrivacyBtn.disabled = true;
    savePrivacyBtn.textContent = '<?= _e('Saving...') ?>';

    const formData = new FormData(privacyForm);

    fetch('media.php', {
        method: 'POST',
        body: formData,
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            UI.toast(data.message || '<?= _e('File status updated.') ?>', 'success');
            setTimeout(() => window.location.reload(), 400);
        } else {
            alert(data.error || '<?= _e('An error occurred while saving.') ?>');
            savePrivacyBtn.disabled = false;
            savePrivacyBtn.textContent = '<?= _e('Save Changes') ?>';
        }
    })
    .catch(() => {
        alert('<?= _e('Network error.') ?>');
        savePrivacyBtn.disabled = false;
        savePrivacyBtn.textContent = '<?= _e('Save Changes') ?>';
    });
}

// -------------------------------------------------------------------------
// File Upload Handling with Live Progress, Speed & ETA
// -------------------------------------------------------------------------
const dropZone = document.getElementById('drop-zone');
const fileInput = document.getElementById('file-upload-input');
const uploadIsPrivate = document.getElementById('upload-is-private');
const uploadPwContainer = document.getElementById('upload-pw-container');
const uploadPassword = document.getElementById('upload-password');

dropZone.addEventListener('click', () => fileInput.click());

function toggleUploadPasswordInput(checked) {
    uploadPwContainer.style.display = checked ? 'flex' : 'none';
    if (checked) {
        uploadPassword.focus();
    }
}

['dragenter', 'dragover'].forEach(e => {
    dropZone.addEventListener(e, (evt) => {
        evt.preventDefault();
        dropZone.style.borderColor = 'var(--primary, #3b82f6)';
        dropZone.style.backgroundColor = 'var(--primary-subtle, rgba(59, 130, 246, 0.1))';
    });
});

['dragleave', 'drop'].forEach(e => {
    dropZone.addEventListener(e, (evt) => {
        evt.preventDefault();
        dropZone.style.borderColor = 'var(--border-strong)';
        dropZone.style.backgroundColor = 'var(--bg-surface)';
    });
});

dropZone.addEventListener('drop', (evt) => {
    if (evt.dataTransfer.files.length) {
        uploadFiles(evt.dataTransfer.files);
    }
});

fileInput.addEventListener('change', () => {
    if (fileInput.files.length) {
        uploadFiles(fileInput.files);
    }
});

let currentUploadXhr = null;
let uploadQueue = [];
let uploadQueueIndex = 0;
let uploadTotalCount = 0;
let isUploadCancelled = false;

function formatBytes(bytes, decimals = 1) {
    if (!bytes || bytes <= 0) return '0 B';
    const k = 1024;
    const dm = decimals < 0 ? 0 : decimals;
    const sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
    const i = Math.floor(Math.log(bytes) / Math.log(k));
    return parseFloat((bytes / Math.pow(k, i)).toFixed(dm)) + ' ' + sizes[i];
}

function formatSpeed(bytesPerSec) {
    if (!bytesPerSec || bytesPerSec <= 0) return '0 KB/s';
    if (bytesPerSec >= 1024 * 1024) {
        return (bytesPerSec / (1024 * 1024)).toFixed(1) + ' MB/s';
    }
    return Math.round(bytesPerSec / 1024) + ' KB/s';
}

function formatTime(seconds) {
    if (!isFinite(seconds) || seconds <= 0) return 'chwila...';
    if (seconds < 60) return `~${Math.round(seconds)}s`;
    const mins = Math.floor(seconds / 60);
    const secs = Math.round(seconds % 60);
    return `~${mins} min ${secs}s`;
}

function getFileIcon(filename) {
    const ext = filename.split('.').pop().toLowerCase();
    if (['mp4', 'webm', 'mov', 'avi', 'mkv', 'ogv'].includes(ext)) return '🎬';
    if (['mp3', 'wav', 'ogg', 'm4a', 'weba'].includes(ext)) return '🎵';
    if (['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg', 'ico'].includes(ext)) return '🖼️';
    if (ext === 'pdf') return '📄';
    if (['zip', 'rar', '7z', 'tar', 'gz'].includes(ext)) return '📦';
    if (['doc', 'docx', 'txt', 'rtf'].includes(ext)) return '📝';
    if (['xls', 'xlsx', 'csv'].includes(ext)) return '📊';
    return '📁';
}

function escapeHtml(text) {
    const div = document.createElement('div');
    div.textContent = text;
    return div.innerHTML;
}

function cancelCurrentUpload() {
    isUploadCancelled = true;
    if (currentUploadXhr) {
        currentUploadXhr.abort();
    }
    const statusText = document.getElementById('upload-status-text');
    if (statusText) statusText.innerHTML = '<span style="color: #ef4444; font-weight: 600;"><?= _e('Upload cancelled.') ?></span>';
    setTimeout(() => {
        const dashboard = document.getElementById('upload-dashboard');
        if (dashboard) dashboard.style.display = 'none';
        fileInput.value = '';
    }, 1200);
}

function uploadFiles(files) {
    if (!files || !files.length) return;

    const isPrivate = uploadIsPrivate.checked;
    const pwd = uploadPassword.value.trim();

    if (isPrivate && pwd === '') {
        alert('<?= _e('Please enter a password for the protected files.') ?>');
        uploadPassword.focus();
        return;
    }

    uploadQueue = Array.from(files);
    uploadTotalCount = uploadQueue.length;
    uploadQueueIndex = 0;
    isUploadCancelled = false;

    const dashboard = document.getElementById('upload-dashboard');
    const compList = document.getElementById('upload-completed-list');
    if (compList) {
        compList.innerHTML = '';
        compList.style.display = 'none';
    }
    dashboard.style.display = 'block';
    dashboard.scrollIntoView({ behavior: 'smooth', block: 'nearest' });

    processNextUploadInQueue(isPrivate, pwd);
}

function processNextUploadInQueue(isPrivate, pwd) {
    if (isUploadCancelled) return;

    if (uploadQueueIndex >= uploadTotalCount) {
        const fill = document.getElementById('upload-progress-fill');
        const badge = document.getElementById('upload-percent-badge');
        const statusText = document.getElementById('upload-status-text');
        const cancelBtn = document.getElementById('upload-cancel-btn');

        fill.style.width = '100%';
        fill.style.background = 'linear-gradient(90deg, #10b981, #059669)';
        badge.textContent = '100%';
        badge.style.background = 'rgba(16, 185, 129, 0.15)';
        badge.style.color = '#10b981';
        if (cancelBtn) cancelBtn.style.display = 'none';
        statusText.innerHTML = '<strong style="color: #10b981;">✓ <?= _e('Upload completed successfully! Refreshing library...') ?></strong>';

        setTimeout(() => window.location.reload(), 600);
        return;
    }

    const file = uploadQueue[uploadQueueIndex];
    uploadQueueIndex++;

    const iconEl = document.getElementById('upload-file-icon');
    const nameEl = document.getElementById('upload-file-name');
    const queueEl = document.getElementById('upload-queue-info');
    const badgeEl = document.getElementById('upload-percent-badge');
    const fillEl = document.getElementById('upload-progress-fill');
    const dataStatEl = document.getElementById('upload-data-stat');
    const speedStatEl = document.getElementById('upload-speed-stat');
    const etaStatEl = document.getElementById('upload-eta-stat');
    const statusTextEl = document.getElementById('upload-status-text');
    const cancelBtn = document.getElementById('upload-cancel-btn');

    if (cancelBtn) cancelBtn.style.display = 'inline-block';
    iconEl.textContent = getFileIcon(file.name);
    nameEl.textContent = file.name;
    nameEl.title = file.name;
    queueEl.textContent = '<?= _e('File') ?> ' + uploadQueueIndex + ' <?= _e('of') ?> ' + uploadTotalCount + ' • ' + formatBytes(file.size);
    badgeEl.textContent = '0%';
    badgeEl.style.background = 'rgba(37, 99, 235, 0.12)';
    badgeEl.style.color = '#2563eb';
    fillEl.style.width = '0%';
    fillEl.style.background = 'linear-gradient(90deg, #2563eb, #6366f1)';
    dataStatEl.textContent = '0 B / ' + formatBytes(file.size);
    speedStatEl.innerHTML = '⚡ 0 KB/s';
    etaStatEl.innerHTML = '⏱️ <?= _e('Remaining:') ?> --';
    statusTextEl.textContent = '<?= _e('Uploading...') ?>';

    const formData = new FormData();
    formData.append('file', file);
    if (isPrivate) {
        formData.append('is_private', '1');
        formData.append('password', pwd);
    }

    const xhr = new XMLHttpRequest();
    currentUploadXhr = xhr;

    const startTime = performance.now();

    xhr.upload.addEventListener('progress', (e) => {
        if (!e.lengthComputable || isUploadCancelled) return;

        const percent = Math.min(99, Math.round((e.loaded / e.total) * 100));
        fillEl.style.width = percent + '%';
        badgeEl.textContent = percent + '%';
        dataStatEl.textContent = formatBytes(e.loaded) + ' / ' + formatBytes(e.total);

        const now = performance.now();
        const elapsedSec = (now - startTime) / 1000;
        if (elapsedSec > 0.1) {
            const bytesPerSec = e.loaded / elapsedSec;
            speedStatEl.innerHTML = '⚡ ' + formatSpeed(bytesPerSec);
            if (bytesPerSec > 0 && e.loaded < e.total) {
                const remainingSec = (e.total - e.loaded) / bytesPerSec;
                etaStatEl.innerHTML = '⏱️ <?= _e('Remaining:') ?> ' + formatTime(remainingSec);
            }
        }

        if (e.loaded >= e.total) {
            badgeEl.textContent = '100%';
            fillEl.style.width = '100%';
            etaStatEl.innerHTML = '⏱️ <?= _e('Processing...') ?>';
            statusTextEl.innerHTML = '<span style="color: #6366f1; font-weight: 600;">⚙️ <?= _e('Saving on server...') ?></span>';
        }
    });

    xhr.addEventListener('load', () => {
        currentUploadXhr = null;
        if (isUploadCancelled) return;

        if (xhr.status >= 200 && xhr.status < 300) {
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.error) {
                    handleUploadError(file.name, res.error);
                    return;
                }
                if (uploadTotalCount > 1) {
                    const compList = document.getElementById('upload-completed-list');
                    compList.style.display = 'block';
                    const item = document.createElement('div');
                    item.style.cssText = 'color: #10b981; margin-bottom: 3px; display: flex; align-items: center; gap: 6px;';
                    item.innerHTML = '<span>✓</span> <span style="color: var(--text-main); font-weight: 500;">' + escapeHtml(file.name) + '</span> <span style="color: var(--text-muted);">(' + formatBytes(file.size) + ')</span>';
                    compList.appendChild(item);
                }

                processNextUploadInQueue(isPrivate, pwd);
            } catch (err) {
                handleUploadError(file.name, '<?= _e('Invalid server response.') ?>');
            }
        } else {
            let errMsg = 'HTTP Error ' + xhr.status;
            try {
                const res = JSON.parse(xhr.responseText);
                if (res.error) errMsg = res.error;
            } catch (e) {}
            handleUploadError(file.name, errMsg);
        }
    });

    xhr.addEventListener('error', () => {
        currentUploadXhr = null;
        if (!isUploadCancelled) {
            handleUploadError(file.name, '<?= _e('Network connection error during upload.') ?>');
        }
    });

    xhr.addEventListener('abort', () => {
        currentUploadXhr = null;
    });

    xhr.open('POST', 'upload.php', true);
    xhr.send(formData);
}

function handleUploadError(filename, errorMsg) {
    const badgeEl = document.getElementById('upload-percent-badge');
    const fillEl = document.getElementById('upload-progress-fill');
    const statusTextEl = document.getElementById('upload-status-text');
    const cancelBtn = document.getElementById('upload-cancel-btn');

    fillEl.style.background = '#ef4444';
    badgeEl.textContent = '<?= _e('Error') ?>';
    badgeEl.style.background = 'rgba(239, 68, 68, 0.15)';
    badgeEl.style.color = '#ef4444';
    if (cancelBtn) cancelBtn.style.display = 'none';

    statusTextEl.innerHTML = '<span style="color: #ef4444; font-weight: 600;">❌ ' + escapeHtml(errorMsg) + '</span>';

    UI.alert({
        title: '<?= _e('Upload Error') ?>',
        message: '<?= _e('Failed to upload file:') ?> ' + filename + '\n' + errorMsg,
        danger: true
    });
}

// -------------------------------------------------------------------------
// Copy URL Helper
// -------------------------------------------------------------------------
function copyMediaUrl(path, buttonElement = null) {
    const cleanPath = path.startsWith('/') ? path : '/' + path;
    const absoluteUrl = window.location.origin + cleanPath;

    navigator.clipboard.writeText(absoluteUrl).then(() => {
        if (buttonElement) {
            const originalText = buttonElement.innerText;
            buttonElement.innerText = '<?= _e('Copied!') ?>';
            buttonElement.style.color = 'var(--primary, #3b82f6)';
            setTimeout(() => {
                buttonElement.innerText = originalText;
                buttonElement.style.color = '';
            }, 1800);
        } else {
            UI.toast('<?= _e('Link copied to clipboard') ?>', 'success');
        }
    }).catch(() => {
        const textarea = document.createElement('textarea');
        textarea.value = absoluteUrl;
        document.body.appendChild(textarea);
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
        UI.toast('<?= _e('Link copied to clipboard') ?>', 'success');
    });
}

// -------------------------------------------------------------------------
// Deletion Modal
// -------------------------------------------------------------------------
const deleteModal = document.getElementById('delete-confirm-modal');
const deleteLink = document.getElementById('confirm-delete-link');
const deleteNamePlaceholder = document.getElementById('delete-filename-placeholder');

function confirmDeleteMedia(url, filename) {
    deleteNamePlaceholder.textContent = filename;
    deleteLink.href = url;
    deleteModal.style.display = 'flex';
}

function closeDeleteModal() {
    deleteModal.style.display = 'none';
}

deleteModal.addEventListener('click', (e) => {
    if (e.target === deleteModal) closeDeleteModal();
});

// -------------------------------------------------------------------------
// Canvas Image Editor
// -------------------------------------------------------------------------
const editorModal = document.getElementById('image-editor-modal');
const canvas = document.getElementById('editor-canvas');
const ctx = canvas.getContext('2d');
const resIndicator = document.getElementById('canvas-resolution');
let originalImg = new Image();
let currentAngle = 0;
let scaleH = 1;
let scaleV = 1;
let activeFilename = 'edited.jpg';

function openImageEditor(imageUrl, filename) {
    activeFilename = filename;
    originalImg.crossOrigin = 'anonymous';
    originalImg.src = imageUrl;
    originalImg.onload = () => {
        currentAngle = 0;
        scaleH = 1;
        scaleV = 1;
        document.getElementById('aspect-ratio-selector').value = 'free';
        renderCanvas();
        editorModal.style.display = 'flex';
    };
}

function closeImageEditor() {
    editorModal.style.display = 'none';
}

function renderCanvas() {
    const isRotated = currentAngle % 180 !== 0;
    const w = isRotated ? originalImg.naturalHeight : originalImg.naturalWidth;
    const h = isRotated ? originalImg.naturalWidth : originalImg.naturalHeight;

    canvas.width = w;
    canvas.height = h;

    ctx.save();
    ctx.translate(canvas.width / 2, canvas.height / 2);
    ctx.rotate((currentAngle * Math.PI) / 180);
    ctx.scale(scaleH, scaleV);
    ctx.drawImage(originalImg, -originalImg.naturalWidth / 2, -originalImg.naturalHeight / 2);
    ctx.restore();

    resIndicator.textContent = `${canvas.width} x ${canvas.height} px`;
}

function rotateImage(deg) {
    currentAngle = (currentAngle + deg) % 360;
    renderCanvas();
}

function flipImage(axis) {
    if (axis === 'h') scaleH *= -1;
    if (axis === 'v') scaleV *= -1;
    renderCanvas();
}

function applyAspectCrop(ratio) {
    if (ratio === 'free') {
        renderCanvas();
        return;
    }
    renderCanvas();
    const [rw, rh] = ratio.split(':').map(Number);
    let targetW = canvas.width;
    let targetH = Math.round(targetW * (rh / rw));

    if (targetH > canvas.height) {
        targetH = canvas.height;
        targetW = Math.round(targetH * (rw / rh));
    }

    const startX = Math.round((canvas.width - targetW) / 2);
    const startY = Math.round((canvas.height - targetH) / 2);

    const croppedData = ctx.getImageData(startX, startY, targetW, targetH);
    canvas.width = targetW;
    canvas.height = targetH;
    ctx.putImageData(croppedData, 0, 0);
    resIndicator.textContent = `${canvas.width} x ${canvas.height} px`;
}

function saveEditedImage() {
    const saveBtn = document.getElementById('save-canvas-btn');
    saveBtn.disabled = true;
    saveBtn.textContent = '<?= _e('Saving...') ?>';

    canvas.toBlob((blob) => {
        const formData = new FormData();
        formData.append('file', blob, 'edited_' + activeFilename.replace(/\.[^/.]+$/, '.jpg'));

        fetch('upload.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.location) {
                window.location.reload();
            } else {
                UI.alert({ title: '<?= _e('Save Error') ?>', message: data.error || 'Failed to save image.', danger: true });
                saveBtn.disabled = false;
                saveBtn.textContent = '<?= _e('Save as New Image') ?>';
            }
        })
        .catch(() => {
            UI.alert({ title: '<?= _e('Save Error') ?>', message: 'Upload failed due to network error.', danger: true });
            saveBtn.disabled = false;
            saveBtn.textContent = '<?= _e('Save as New Image') ?>';
        });
    }, 'image/jpeg', 0.92);
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
