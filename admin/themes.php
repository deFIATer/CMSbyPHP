<?php
define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Security;
use Core\Router;
use Core\PageCache;

Auth::requireCapability('manage_settings');

$db = Database::getConnection();
$message = '';
$messageType = 'success';
$activeTheme = Router::getOption('active_theme', 'cmsbyphp-clean');
$themesDir = dirname(__DIR__) . '/themes';

// -----------------------------------------------------------------------------
// 1. Action: Activate Theme
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'activate' && isset($_GET['theme'])) {
    if (!Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
        die('Invalid CSRF token');
    }

    $themeSlug = basename(trim((string)$_GET['theme']));
    $targetDir = $themesDir . '/' . $themeSlug;

    if (is_dir($targetDir)) {
        $stmt = $db->prepare("INSERT INTO settings (key, value) VALUES ('active_theme', :v) ON CONFLICT(key) DO UPDATE SET value = :v");
        $stmt->execute([':v' => $themeSlug]);

        if (class_exists(\Core\PageCache::class)) {
            \Core\PageCache::purge();
        }

        header('Location: themes.php?activated=' . urlencode($themeSlug));
        exit;
    } else {
        $message = __('Selected theme directory does not exist.');
        $messageType = 'danger';
    }
}

// -----------------------------------------------------------------------------
// 2. Action: Export Theme as ZIP
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'export' && isset($_GET['theme'])) {
    if (!Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
        die('Invalid CSRF token');
    }

    $themeSlug = basename(trim((string)$_GET['theme']));
    $targetDir = $themesDir . '/' . $themeSlug;

    if (is_dir($targetDir) && class_exists('ZipArchive')) {
        $zipFile = tempnam(sys_get_temp_dir(), 'cms_theme_') . '.zip';
        $zip = new \ZipArchive();

        if ($zip->open($zipFile, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) === true) {
            $files = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($targetDir, \RecursiveDirectoryIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::LEAVES_ONLY
            );

            foreach ($files as $file) {
                if (!$file->isDir()) {
                    $filePath = $file->getRealPath();
                    $relativePath = $themeSlug . '/' . substr($filePath, strlen($targetDir) + 1);
                    $zip->addFile($filePath, $relativePath);
                }
            }
            $zip->close();

            if (file_exists($zipFile)) {
                header('Content-Type: application/zip');
                header('Content-Disposition: attachment; filename="' . $themeSlug . '.zip"');
                header('Content-Length: ' . filesize($zipFile));
                header('Pragma: no-cache');
                readfile($zipFile);
                @unlink($zipFile);
                exit;
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 3. Action: Delete Theme
// -----------------------------------------------------------------------------
if (isset($_GET['action']) && $_GET['action'] === 'delete' && isset($_GET['theme'])) {
    if (!Security::verifyCsrfToken($_GET['csrf'] ?? '')) {
        die('Invalid CSRF token');
    }

    $delSlug = basename(trim((string)$_GET['theme']));

    if ($delSlug === $activeTheme) {
        $message = __('Cannot delete the currently active theme.');
        $messageType = 'danger';
    } elseif (in_array($delSlug, ['cmsbyphp-clean', 'default'], true)) {
        $message = __('Core default themes cannot be deleted.');
        $messageType = 'danger';
    } else {
        $targetDir = $themesDir . '/' . $delSlug;
        if (is_dir($targetDir)) {
            $it = new \RecursiveDirectoryIterator($targetDir, \RecursiveDirectoryIterator::SKIP_DOTS);
            $files = new \RecursiveIteratorIterator($it, \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) {
                if ($file->isDir()) {
                    @rmdir($file->getRealPath());
                } else {
                    @unlink($file->getRealPath());
                }
            }
            @rmdir($targetDir);
            header('Location: themes.php?deleted=1');
            exit;
        }
    }
}

// -----------------------------------------------------------------------------
// 4. Action: Install Theme from ZIP
// -----------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'install_theme') {
    if (!Security::verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        die('Invalid CSRF token');
    }

    if (empty($_FILES['theme_zip']) || $_FILES['theme_zip']['error'] !== UPLOAD_ERR_OK) {
        $message = __('Please select a valid ZIP archive to upload.');
        $messageType = 'danger';
    } else {
        $origName = basename((string)$_FILES['theme_zip']['name']);
        $zipTmp = $_FILES['theme_zip']['tmp_name'];

        if (!str_ends_with(strtolower($origName), '.zip')) {
            $message = __('Only ZIP packages (.zip) are supported.');
            $messageType = 'danger';
        } elseif (!class_exists('ZipArchive')) {
            $message = __('ZipArchive PHP extension is missing on the server.');
            $messageType = 'danger';
        } else {
            $zip = new \ZipArchive();
            if ($zip->open($zipTmp) !== true) {
                $message = __('Failed to open the uploaded ZIP file.');
                $messageType = 'danger';
            } else {
                $isValid = true;
                $hasThemeMarker = false;
                $topDirs = [];
                $rootFiles = [];

                for ($i = 0; $i < $zip->numFiles; $i++) {
                    $stat = $zip->statIndex($i);
                    $entryName = str_replace('\\', '/', (string)$stat['name']);

                    // Zip Slip prevention
                    if (str_contains($entryName, '../') || str_starts_with($entryName, '/') || str_starts_with($entryName, '\\')) {
                        $isValid = false;
                        break;
                    }

                    $parts = explode('/', trim($entryName, '/'));
                    if (count($parts) === 1 && ($stat['size'] ?? 0) > 0) {
                        $rootFiles[] = $parts[0];
                    } elseif (count($parts) > 1) {
                        $topDirs[$parts[0]] = true;
                    }

                    $base = basename($entryName);
                    if (in_array($base, ['header.php', 'index.php', 'theme.json', 'style.css', 'front-page.php'], true)) {
                        $hasThemeMarker = true;
                    }
                }

                if (!$isValid) {
                    $message = __('Security violation: ZIP archive contains illegal path characters.');
                    $messageType = 'danger';
                    $zip->close();
                } elseif (!$hasThemeMarker) {
                    $message = __('The archive does not appear to be a valid theme (missing header.php, index.php or theme.json).');
                    $messageType = 'danger';
                    $zip->close();
                } else {
                    $extractDirect = false;
                    if (count($topDirs) === 1 && empty($rootFiles)) {
                        $destSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)array_key_first($topDirs));
                        $destSlug = strtolower(trim((string)$destSlug, '-'));
                    } else {
                        $baseName = pathinfo($origName, PATHINFO_FILENAME);
                        $destSlug = preg_replace('/[^a-zA-Z0-9_-]+/', '-', (string)$baseName);
                        $destSlug = strtolower(trim((string)$destSlug, '-')) ?: 'theme-' . bin2hex(random_bytes(3));
                        $extractDirect = true;
                    }

                    $targetThemeDir = $themesDir . '/' . $destSlug;
                    if (!is_dir($targetThemeDir)) {
                        @mkdir($targetThemeDir, 0775, true);
                    }

                    for ($i = 0; $i < $zip->numFiles; $i++) {
                        $entry = (string)$zip->getNameIndex($i);
                        $cleanEntry = str_replace('\\', '/', $entry);
                        if (str_contains($cleanEntry, '../')) continue;

                        if ($extractDirect) {
                            $destPath = $targetThemeDir . '/' . $cleanEntry;
                        } else {
                            $slashPos = strpos($cleanEntry, '/');
                            if ($slashPos === false) continue;
                            $subPath = substr($cleanEntry, $slashPos + 1);
                            if ($subPath === '' || $subPath === false) continue;
                            $destPath = $targetThemeDir . '/' . $subPath;
                        }

                        if (str_ends_with($cleanEntry, '/')) {
                            @mkdir($destPath, 0775, true);
                        } else {
                            $parentDir = dirname($destPath);
                            if (!is_dir($parentDir)) {
                                @mkdir($parentDir, 0775, true);
                            }
                            $fp = $zip->getStream($entry);
                            if ($fp) {
                                file_put_contents($destPath, stream_get_contents($fp));
                                fclose($fp);
                            }
                        }
                    }
                    $zip->close();

                    header('Location: themes.php?installed=' . urlencode($destSlug));
                    exit;
                }
            }
        }
    }
}

// -----------------------------------------------------------------------------
// 5. Query Flash Messages
// -----------------------------------------------------------------------------
if (isset($_GET['activated'])) {
    $message = __('Theme activated successfully: ') . htmlspecialchars(basename((string)$_GET['activated']), ENT_QUOTES, 'UTF-8');
    $messageType = 'success';
} elseif (isset($_GET['installed'])) {
    $message = __('Theme installed successfully: ') . htmlspecialchars(basename((string)$_GET['installed']), ENT_QUOTES, 'UTF-8') . '. ' . __('You can activate it now.');
    $messageType = 'success';
} elseif (isset($_GET['deleted'])) {
    $message = __('Theme deleted successfully.');
    $messageType = 'success';
}

// -----------------------------------------------------------------------------
// 6. Discover All Themes
// -----------------------------------------------------------------------------
$allThemes = [];
if (is_dir($themesDir)) {
    foreach (glob($themesDir . '/*', GLOB_ONLYDIR) as $tDir) {
        $slug = basename($tDir);
        $manifestFile = $tDir . '/theme.json';
        $manifest = [];
        if (file_exists($manifestFile)) {
            $manifest = json_decode((string)file_get_contents($manifestFile), true) ?: [];
        }

        // Detect screenshot
        $screenshotUrl = '';
        foreach (['screenshot.png', 'screenshot.jpg', 'screenshot.jpeg', 'screenshot.webp'] as $sFile) {
            if (file_exists($tDir . '/' . $sFile)) {
                $screenshotUrl = '../themes/' . $slug . '/' . $sFile . '?v=' . filemtime($tDir . '/' . $sFile);
                break;
            }
        }

        $allThemes[$slug] = [
            'slug'        => $slug,
            'name'        => $manifest['name'] ?? ucfirst(str_replace(['-', '_'], ' ', $slug)),
            'version'     => $manifest['version'] ?? '1.0.0',
            'author'      => $manifest['author'] ?? 'CMSbyPHP',
            'description' => $manifest['description'] ?? __('Custom layout theme for CMSbyPHP.'),
            'screenshot'  => $screenshotUrl,
            'is_active'   => ($slug === $activeTheme),
            'path'        => $tDir
        ];
    }
}

// Put active theme at the top
uasort($allThemes, function ($a, $b) {
    if ($a['is_active']) return -1;
    if ($b['is_active']) return 1;
    return strcasecmp($a['name'], $b['name']);
});

require_once __DIR__ . '/views/header.php';
?>

<div class="page-header" style="display: flex; align-items: flex-start; justify-content: space-between; gap: 16px; flex-wrap: wrap;">
    <div>
        <h1 class="page-title"><?= _e('Themes') ?></h1>
        <p style="color: var(--text-muted); font-size: 13px;">
            <?= _e('Manage site appearance, switch layouts and install new themes from ZIP packages.') ?>
        </p>
    </div>
    <div style="display: flex; gap: 10px;">
        <button type="button" class="btn btn-primary" onclick="toggleUploadModal()" style="display: inline-flex; align-items: center; gap: 6px;">
            <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
            <?= _e('Install New Theme (ZIP)') ?>
        </button>
    </div>
</div>

<?php if ($message): ?>
    <div class="card" style="border-left: 4px solid <?= $messageType === 'danger' ? 'var(--danger, #ef4444)' : 'var(--success, #10b981)' ?>; background: <?= $messageType === 'danger' ? 'var(--danger-subtle, rgba(239,68,68,0.1))' : 'var(--success-subtle, rgba(16,185,129,0.1))' ?>; padding: 12px 16px; margin-bottom: 24px; color: <?= $messageType === 'danger' ? '#ef4444' : '#10b981' ?>; font-weight: 500;">
        <?= $message ?>
    </div>
<?php endif; ?>

<!-- ZIP Upload Panel / Modal -->
<div id="upload-theme-panel" class="card" style="display: none; border: 2px dashed var(--primary); padding: 28px 24px; margin-bottom: 28px; background: var(--bg-surface); text-align: center;">
    <form method="POST" action="themes.php" enctype="multipart/form-data" id="theme-upload-form">
        <input type="hidden" name="action" value="install_theme">
        <input type="hidden" name="csrf_token" value="<?= Security::generateCsrfToken() ?>">

        <svg style="width: 44px; height: 44px; color: var(--primary); margin: 0 auto 10px; display: block;" fill="none" stroke="currentColor" stroke-width="1.8" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-8l-4-4m0 0L8 8m4-4v12"/>
        </svg>

        <h3 style="font-size: 16px; font-weight: 700; margin-bottom: 6px; color: var(--text-main);"><?= _e('Upload Theme ZIP') ?></h3>
        <p style="font-size: 12.5px; color: var(--text-muted); margin-bottom: 16px; max-width: 520px; margin-left: auto; margin-right: auto;">
            <?= _e('Select a theme archive (.zip) containing template files. It will be safely extracted into the /themes directory.') ?>
        </p>

        <div style="display: flex; justify-content: center; align-items: center; gap: 12px; flex-wrap: wrap;">
            <input type="file" name="theme_zip" id="theme-zip-input" accept=".zip,application/zip" required style="font-size: 13px; color: var(--text-main);">
            <button type="submit" class="btn btn-primary" style="padding: 8px 18px; font-size: 13px;">
                📦 <?= _e('Install Theme') ?>
            </button>
            <button type="button" class="btn btn-secondary" onclick="toggleUploadModal()" style="padding: 8px 14px; font-size: 13px;">
                <?= _e('Cancel') ?>
            </button>
        </div>
    </form>
</div>

<!-- Active Theme Showcase Card -->
<?php if (isset($allThemes[$activeTheme])): 
    $cur = $allThemes[$activeTheme]; 
?>
<div class="card" style="border: 1.5px solid var(--primary); padding: 24px; margin-bottom: 32px; background: var(--bg-card); box-shadow: 0 10px 25px -5px rgba(37,99,235,0.08); border-radius: 14px;">
    <div style="display: flex; align-items: flex-start; justify-content: space-between; gap: 20px; flex-wrap: wrap;">
        <div style="display: flex; gap: 22px; flex-wrap: wrap; flex: 1; min-width: 280px;">
            <!-- Screenshot Preview -->
            <div style="width: 260px; height: 162px; border-radius: 10px; overflow: hidden; background: var(--bg-subtle); border: 1px solid var(--border-subtle); flex-shrink: 0; box-shadow: 0 4px 12px rgba(0,0,0,0.06); position: relative;">
                <?php if ($cur['screenshot']): ?>
                    <img src="<?= htmlspecialchars($cur['screenshot'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($cur['name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                    <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; color: var(--text-muted); font-size: 13px; gap: 6px;">
                        <span style="font-size: 28px;">🎨</span>
                        <span><?= htmlspecialchars($cur['name'], ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                <?php endif; ?>
                <span class="badge" style="position: absolute; top: 10px; left: 10px; background: #10b981; color: #fff; font-weight: 700; font-size: 11px; padding: 3px 8px; border-radius: 6px; box-shadow: 0 2px 6px rgba(0,0,0,0.2);">
                    ✓ <?= _e('Currently Active') ?>
                </span>
            </div>

            <!-- Details -->
            <div style="flex: 1; min-width: 240px;">
                <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 4px;">
                    <h2 style="font-size: 20px; font-weight: 800; color: var(--text-main); margin: 0; letter-spacing: -0.01em;">
                        <?= htmlspecialchars($cur['name'], ENT_QUOTES, 'UTF-8') ?>
                    </h2>
                    <span class="badge" style="background: rgba(37,99,235,0.12); color: var(--primary); font-size: 11px; font-weight: 700;">
                        v<?= htmlspecialchars($cur['version'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>
                <div style="font-size: 12px; color: var(--text-muted); margin-bottom: 12px;">
                    <?= _e('Author:') ?> <strong><?= htmlspecialchars($cur['author'], ENT_QUOTES, 'UTF-8') ?></strong> &bull; <?= _e('Directory:') ?> <code>/themes/<?= htmlspecialchars($cur['slug'], ENT_QUOTES, 'UTF-8') ?></code>
                </div>
                <p style="font-size: 13.5px; color: var(--text-muted); line-height: 1.5; margin-bottom: 20px; max-width: 680px;">
                    <?= htmlspecialchars($cur['description'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <!-- Actions for active theme -->
                <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                    <a href="customize.php" class="btn btn-primary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px;">
                        <span>🎨</span> <?= _e('Customize Theme') ?>
                    </a>
                    <a href="theme-edit.php?theme=<?= urlencode($cur['slug']) ?>" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px;">
                        <span>💻</span> <?= _e('Edit Code') ?>
                    </a>
                    <a href="themes.php?action=export&theme=<?= urlencode($cur['slug']) ?>&csrf=<?= Security::generateCsrfToken() ?>" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px;" title="<?= _e('Export ZIP') ?>">
                        <span>📦</span> <?= _e('Export ZIP') ?>
                    </a>
                    <a href="../" target="_blank" rel="noopener" class="btn btn-secondary" style="display: inline-flex; align-items: center; gap: 6px; font-size: 13px;">
                        <span>↗</span> <?= _e('View Site') ?>
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Section: All Available Themes Grid -->
<div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 16px;">
    <h2 style="font-size: 16px; font-weight: 700; color: var(--text-main); margin: 0;">
        <?= _e('Available Themes') ?> <span style="font-size: 13px; font-weight: 500; color: var(--text-muted);">(<?= count($allThemes) ?>)</span>
    </h2>
</div>

<div class="themes-grid" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(290px, 1fr)); gap: 24px;">
    <?php foreach ($allThemes as $t): 
        $isActive = $t['is_active'];
    ?>
        <div class="card theme-card" style="padding: 0; overflow: hidden; display: flex; flex-direction: column; border-radius: 12px; transition: transform 0.15s ease, box-shadow 0.15s ease; border: 1px solid <?= $isActive ? 'var(--primary)' : 'var(--border-subtle)' ?>;">
            <!-- Screenshot Container -->
            <div style="width: 100%; height: 180px; background: var(--bg-subtle); position: relative; overflow: hidden; border-bottom: 1px solid var(--border-subtle);">
                <?php if ($t['screenshot']): ?>
                    <img src="<?= htmlspecialchars($t['screenshot'], ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>" style="width: 100%; height: 100%; object-fit: cover;">
                <?php else: ?>
                    <div style="width: 100%; height: 100%; display: flex; align-items: center; justify-content: center; flex-direction: column; color: var(--text-muted); font-size: 13px; gap: 6px;">
                        <span style="font-size: 32px;">🎨</span>
                        <strong style="color: var(--text-main);"><?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?></strong>
                    </div>
                <?php endif; ?>

                <?php if ($isActive): ?>
                    <span class="badge" style="position: absolute; top: 12px; right: 12px; background: #10b981; color: #fff; font-weight: 700; font-size: 11px; padding: 4px 9px; border-radius: 6px; box-shadow: 0 2px 8px rgba(0,0,0,0.3);">
                        ✓ <?= _e('Active') ?>
                    </span>
                <?php endif; ?>
            </div>

            <!-- Theme Card Body -->
            <div style="padding: 16px 18px; display: flex; flex-direction: column; flex-grow: 1;">
                <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 6px;">
                    <h3 style="font-size: 15px; font-weight: 700; color: var(--text-main); margin: 0;">
                        <?= htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') ?>
                    </h3>
                    <span style="font-size: 11px; color: var(--text-muted); font-weight: 600;">
                        v<?= htmlspecialchars($t['version'], ENT_QUOTES, 'UTF-8') ?>
                    </span>
                </div>

                <p style="font-size: 12px; color: var(--text-muted); line-height: 1.45; margin-bottom: 16px; flex-grow: 1; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;">
                    <?= htmlspecialchars($t['description'], ENT_QUOTES, 'UTF-8') ?>
                </p>

                <!-- Actions Footer -->
                <div style="display: flex; align-items: center; justify-content: space-between; gap: 8px; border-top: 1px solid var(--border-subtle); padding-top: 12px; margin-top: auto;">
                    <?php if ($isActive): ?>
                        <a href="customize.php" class="btn btn-primary" style="flex: 1; padding: 6px 12px; font-size: 12px; text-align: center;">
                            <?= _e('Customize') ?>
                        </a>
                    <?php else: ?>
                        <a href="themes.php?action=activate&theme=<?= urlencode($t['slug']) ?>&csrf=<?= Security::generateCsrfToken() ?>" 
                           class="btn btn-primary" 
                           style="flex: 1; padding: 6px 12px; font-size: 12px; text-align: center;">
                            ⚡ <?= _e('Activate') ?>
                        </a>
                    <?php endif; ?>

                    <a href="theme-edit.php?theme=<?= urlencode($t['slug']) ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;" title="<?= _e('Edit Code') ?>">
                        💻
                    </a>

                    <a href="themes.php?action=export&theme=<?= urlencode($t['slug']) ?>&csrf=<?= Security::generateCsrfToken() ?>" class="btn btn-secondary" style="padding: 6px 10px; font-size: 12px;" title="<?= _e('Export ZIP') ?>">
                        📦
                    </a>

                    <?php if (!$isActive && !in_array($t['slug'], ['cmsbyphp-clean', 'default'], true)): ?>
                        <a href="themes.php?action=delete&theme=<?= urlencode($t['slug']) ?>&csrf=<?= Security::generateCsrfToken() ?>" 
                           class="btn btn-danger-ghost" 
                           style="padding: 6px 10px; font-size: 12px;"
                           title="<?= _e('Delete Theme') ?>"
                           data-confirm
                           data-confirm-title="<?= _e('Delete Theme') ?>"
                           data-confirm-message="<?= _e('Are you sure you want to permanently delete this theme? All theme files will be removed.') ?>"
                           data-confirm-ok="<?= _e('Delete') ?>"
                           data-confirm-danger>
                            🗑️
                        </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
</div>

<script>
function toggleUploadModal() {
    const panel = document.getElementById('upload-theme-panel');
    if (!panel) return;
    const isVisible = panel.style.display !== 'none';
    panel.style.display = isVisible ? 'none' : 'block';
    if (!isVisible) {
        panel.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        document.getElementById('theme-zip-input').focus();
    }
}
</script>

<?php require_once __DIR__ . '/views/footer.php'; ?>
