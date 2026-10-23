<?php
declare(strict_types=1);

namespace Core;

use PDO;

/**
 * Class ProtectedMedia
 *
 * Handles file encryption / password protection for uploaded assets in CMSbyPHP.
 * When a file is marked as private, it is moved from public /uploads to the protected
 * storage directory (data/protected_files/) where direct HTTP access is denied.
 * Access is gated through /file/{id}/{filename} with password authentication and
 * full HTTP Range streaming support (essential for video seeking in Safari/iOS/Chrome).
 */
final class ProtectedMedia
{
    public const PROTECTED_DIR = 'data/protected_files';

    /**
     * Absolute path to the protected files directory.
     */
    public static function getProtectedDir(): string
    {
        $dir = dirname(__DIR__) . '/' . self::PROTECTED_DIR;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $htaccess = $dir . '/.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "# Protected Storage\nRequire all denied\nOptions -Indexes -ExecCGI\n");
        }
        return $dir;
    }

    /**
     * Absolute path to the public uploads directory.
     */
    public static function getUploadsDir(): string
    {
        $dir = dirname(__DIR__) . '/uploads';
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        return $dir;
    }

    /**
     * Toggle or update privacy and password for a media item.
     *
     * @param int $mediaId
     * @param bool $isPrivate
     * @param string|null $password
     * @return array
     */
    public static function setPrivacy(int $mediaId, bool $isPrivate, ?string $password = null): array
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM media WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $mediaId]);
        $media = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$media) {
            return ['success' => false, 'error' => 'Media item not found'];
        }

        $baseDir = dirname(__DIR__);
        $currentPath = $baseDir . $media['filepath'];
        $basename = basename($media['filepath']);

        if ($isPrivate) {
            // Require a password when making private
            $hash = $media['password_hash'] ?? null;
            if (!empty($password)) {
                $hash = password_hash(trim($password), PASSWORD_DEFAULT);
            } elseif (empty($hash)) {
                return ['success' => false, 'error' => 'Password is required to make a file private'];
            }

            $protectedDir = self::getProtectedDir();
            $targetPath = $protectedDir . '/' . $basename;

            // Move physical file to protected directory if not already there
            if (file_exists($currentPath) && $currentPath !== $targetPath) {
                @rename($currentPath, $targetPath);
            }

            // Remove or move webp copy to prevent thumbnail leak
            if (!empty($media['webp_path'])) {
                $webpCurrent = $baseDir . $media['webp_path'];
                if (file_exists($webpCurrent)) {
                    @unlink($webpCurrent);
                }
            }

            $token = $media['access_token'] ?: bin2hex(random_bytes(16));
            $newFilepath = '/' . self::PROTECTED_DIR . '/' . $basename;

            $upd = $db->prepare("
                UPDATE media 
                SET is_private = 1, 
                    password_hash = :hash, 
                    access_token = :token, 
                    filepath = :path,
                    webp_path = NULL 
                WHERE id = :id
            ");
            $upd->execute([
                ':hash' => $hash,
                ':token' => $token,
                ':path' => $newFilepath,
                ':id' => $mediaId
            ]);

            return [
                'success' => true,
                'is_private' => 1,
                'access_url' => self::getAccessUrl((int)$mediaId),
                'message' => 'File marked as private and password protected.'
            ];
        }

        // Reverting to Public
        $uploadsDir = self::getUploadsDir();
        $targetPath = $uploadsDir . '/' . $basename;

        if (file_exists($currentPath) && $currentPath !== $targetPath) {
            @rename($currentPath, $targetPath);
        }

        $newFilepath = '/uploads/' . $basename;
        $upd = $db->prepare("
            UPDATE media 
            SET is_private = 0, 
                password_hash = NULL, 
                filepath = :path 
            WHERE id = :id
        ");
        $upd->execute([
            ':path' => $newFilepath,
            ':id' => $mediaId
        ]);

        return [
            'success' => true,
            'is_private' => 0,
            'access_url' => self::getAccessUrl((int)$mediaId),
            'message' => 'File is now public.'
        ];
    }

    /**
     * Get the appropriate URL to access/share the file.
     */
    public static function getAccessUrl(array|int $media, bool $absolute = false): string
    {
        if (is_int($media)) {
            $db = Database::getConnection();
            $stmt = $db->prepare("SELECT * FROM media WHERE id = :id LIMIT 1");
            $stmt->execute([':id' => $media]);
            $media = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$media) {
                return '';
            }
        }

        $basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

        if (!empty($media['is_private'])) {
            $slugFilename = rawurlencode($media['filename']);
            $relUrl = $basePrefix . '/file/' . $media['id'] . '/' . $slugFilename;
        } else {
            $relUrl = resolve_media_url($media['filepath']);
        }

        if ($absolute) {
            $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https://' : 'http://';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            return $protocol . $host . $relUrl;
        }

        return $relUrl;
    }

    /**
     * Checks if a media item is currently unlocked for the visitor's session.
     */
    public static function isUnlocked(int $mediaId): bool
    {
        return !empty($_SESSION['unlocked_media'][$mediaId]);
    }

    /**
     * Unlock a media item in the visitor's session.
     */
    public static function unlock(int $mediaId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        $_SESSION['unlocked_media'][$mediaId] = true;
    }

    /**
     * Re-lock a media item in the visitor's session.
     */
    public static function lock(int $mediaId): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        unset($_SESSION['unlocked_media'][$mediaId]);
    }

    /**
     * Primary route handler for /file/{id} and /file/{id}/{filename}
     */
    public static function handleRoute(int $mediaId, string $filename = ''): void
    {
        $db = Database::getConnection();
        $stmt = $db->prepare("SELECT * FROM media WHERE id = :id LIMIT 1");
        $stmt->execute([':id' => $mediaId]);
        $media = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$media) {
            http_response_code(404);
            echo "<h1>404 - File Not Found</h1>";
            exit;
        }

        $baseDir = dirname(__DIR__);
        $absolutePath = $baseDir . $media['filepath'];

        // Fallback search if file was manually relocated
        if (!file_exists($absolutePath)) {
            $protectedCandidate = self::getProtectedDir() . '/' . basename($media['filepath']);
            $uploadCandidate = self::getUploadsDir() . '/' . basename($media['filepath']);
            if (file_exists($protectedCandidate)) {
                $absolutePath = $protectedCandidate;
            } elseif (file_exists($uploadCandidate)) {
                $absolutePath = $uploadCandidate;
            }
        }

        // If file is public, simply stream or redirect
        if (empty($media['is_private'])) {
            self::streamFile($absolutePath, $media['mime_type'], $media['filename'], isset($_GET['download']));
            exit;
        }

        // Handle re-lock request
        if (isset($_GET['lock'])) {
            self::lock($mediaId);
            $cleanUrl = self::getAccessUrl($media);
            header('Location: ' . $cleanUrl);
            exit;
        }

        $errorMsg = null;

        // Handle password submission
        if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['media_password'])) {
            $providedPassword = (string)$_POST['media_password'];
            if (!empty($media['password_hash']) && password_verify($providedPassword, $media['password_hash'])) {
                self::unlock($mediaId);
                // Redirect to avoid form resubmission
                header('Location: ' . self::getAccessUrl($media));
                exit;
            } else {
                $errorMsg = 'Nieprawidłowe hasło dostępu. Spróbuj ponownie.';
            }
        }

        // Check if unlocked
        if (self::isUnlocked($mediaId)) {
            // Direct streaming or download mode (e.g. video player element or download click)
            if (isset($_GET['stream']) || isset($_GET['download']) || isset($_SERVER['HTTP_RANGE'])) {
                self::streamFile($absolutePath, $media['mime_type'], $media['filename'], isset($_GET['download']));
                exit;
            }

            // Interactive browser player/viewer
            self::renderUnlockedView($media);
            exit;
        }

        // File is private and locked: show password prompt screen
        self::renderPasswordScreen($media, $errorMsg);
        exit;
    }

    /**
     * Efficiently streams files with HTTP Range (RFC 7233) support for video/audio seeking.
     */
    public static function streamFile(string $filePath, string $mimeType, string $downloadFilename, bool $forceDownload = false): void
    {
        if (!file_exists($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            echo "File cannot be read.";
            exit;
        }

        // Clean any active output buffers to allow fast chunked streaming
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $fileSize = (int)filesize($filePath);
        $fp = @fopen($filePath, 'rb');
        if ($fp === false) {
            http_response_code(500);
            echo "Error opening file.";
            exit;
        }

        $start = 0;
        $end = $fileSize - 1;

        // Content disposition
        $dispositionType = $forceDownload ? 'attachment' : 'inline';
        $encodedFilename = rawurlencode($downloadFilename);

        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        header('Cache-Control: private, max-age=3600');
        header("Content-Disposition: {$dispositionType}; filename=\"{$downloadFilename}\"; filename*=UTF-8''{$encodedFilename}");

        // Handle HTTP Range request (essential for iOS Safari and Chrome video scrub)
        if (isset($_SERVER['HTTP_RANGE']) && preg_match('/bytes=\h*(\d+)-(\d*)[\D.*]?/i', $_SERVER['HTTP_RANGE'], $matches)) {
            $start = (int)$matches[1];
            if (!empty($matches[2])) {
                $end = (int)$matches[2];
            }
            if ($start > $end || $start > $fileSize - 1 || $end >= $fileSize) {
                header('HTTP/1.1 416 Requested Range Not Satisfiable');
                header("Content-Range: bytes */{$fileSize}");
                fclose($fp);
                exit;
            }

            header('HTTP/1.1 206 Partial Content');
            header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
            $length = ($end - $start) + 1;
            header("Content-Length: {$length}");
        } else {
            header("Content-Length: {$fileSize}");
        }

        fseek($fp, $start);
        $bufferSize = 64 * 1024; // 64KB chunks
        $bytesRemaining = ($end - $start) + 1;

        while ($bytesRemaining > 0 && !feof($fp) && !connection_aborted()) {
            $bytesToRead = min($bufferSize, $bytesRemaining);
            $chunk = fread($fp, $bytesToRead);
            if ($chunk === false) {
                break;
            }
            echo $chunk;
            flush();
            $bytesRemaining -= strlen($chunk);
        }

        fclose($fp);
        exit;
    }

    /**
     * Renders the branded password prompt screen.
     */
    public static function renderPasswordScreen(array $media, ?string $error = null): void
    {
        $siteTitle = Database::getConnection()->query("SELECT value FROM settings WHERE key = 'site_title' LIMIT 1")->fetchColumn() ?: 'CMSbyPHP';
        $fileSizeKb = round((int)$media['file_size'] / 1024, 1);
        $fileSizeFormatted = $fileSizeKb > 1024 ? round($fileSizeKb / 1024, 2) . ' MB' : $fileSizeKb . ' KB';
        $mediaType = self::detectMediaTypeCategory($media['mime_type'], $media['filename']);
        $csrfToken = Security::generateCsrfToken();
        $htmlLang = class_exists('\Core\I18n') ? I18n::getLocale() : 'pl';
        ?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($htmlLang, ENT_QUOTES, 'UTF-8') ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= _e('Password Protected File') ?> &bull; <?= htmlspecialchars((string)$siteTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/svg+xml" href="/site/assets/img/favicon.svg">
    <style>
        :root {
            --bg-body: #0b1120;
            --bg-card: #151d30;
            --border-card: #22304d;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --danger: #ef4444;
            --danger-bg: rgba(239, 68, 68, 0.12);
            --danger-border: rgba(239, 68, 68, 0.3);
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
            --input-bg: #0f172a;
            --input-border: #334155;
            --input-text: #f8fafc;
            --input-placeholder: #64748b;
        }
        @media (prefers-color-scheme: light) {
            html:not([data-theme="dark"]) {
                --bg-body: #f8fafc;
                --bg-card: #ffffff;
                --border-card: #e2e8f0;
                --text-main: #0f172a;
                --text-muted: #64748b;
                --primary: #2563eb;
                --primary-hover: #1d4ed8;
                --shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.08);
                --input-bg: #ffffff;
                --input-border: #cbd5e1;
                --input-text: #0f172a;
                --input-placeholder: #94a3b8;
            }
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            width: 100%;
            max-width: 440px;
        }
        @media (max-width: 480px) {
            body { padding: 12px; }
            .card { padding: 24px 18px !important; border-radius: 14px !important; }
            h1 { font-size: 18px !important; }
            .subtitle { font-size: 12px !important; }
            .file-info-box { padding: 10px 12px !important; gap: 10px !important; }
        }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 16px;
            padding: 36px 32px;
            box-shadow: var(--shadow);
            text-align: center;
            animation: fadeIn 0.25s ease-out;
        }
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        .lock-icon-wrapper {
            width: 68px;
            height: 68px;
            border-radius: 50%;
            background: rgba(59, 130, 246, 0.12);
            color: var(--primary);
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .lock-icon-wrapper svg {
            width: 34px;
            height: 34px;
        }
        h1 {
            font-size: 20px;
            font-weight: 700;
            margin-bottom: 8px;
            letter-spacing: -0.01em;
        }
        p.subtitle {
            font-size: 13.5px;
            color: var(--text-muted);
            line-height: 1.5;
            margin-bottom: 24px;
        }
        .file-info-box {
            display: flex;
            align-items: center;
            gap: 12px;
            background: rgba(255, 255, 255, 0.03);
            border: 1px solid var(--border-card);
            border-radius: 10px;
            padding: 12px 14px;
            margin-bottom: 24px;
            text-align: left;
        }
        .file-info-icon {
            font-size: 24px;
            line-height: 1;
        }
        .file-info-details {
            overflow: hidden;
            flex-grow: 1;
        }
        .file-name {
            font-size: 13.5px;
            font-weight: 600;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            color: var(--text-main);
        }
        .file-meta {
            font-size: 11.5px;
            color: var(--text-muted);
            margin-top: 2px;
        }
        .badge-locked {
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            padding: 2px 7px;
            background: rgba(245, 158, 11, 0.15);
            color: #f59e0b;
            border: 1px solid rgba(245, 158, 11, 0.3);
            border-radius: 6px;
        }
        .alert-error {
            background: var(--danger-bg);
            border: 1px solid var(--danger-border);
            color: var(--danger);
            font-size: 13px;
            padding: 10px 14px;
            border-radius: 8px;
            margin-bottom: 20px;
            text-align: left;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .form-group {
            position: relative;
            margin-bottom: 20px;
            text-align: left;
        }
        .form-control {
            width: 100%;
            padding: 13px 44px 13px 16px;
            font-size: 15px;
            border-radius: 10px;
            border: 1.5px solid var(--input-border);
            background: var(--input-bg);
            color: var(--input-text);
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s, background-color 0.15s;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.04);
        }
        .form-control::placeholder {
            color: var(--input-placeholder);
            opacity: 1;
        }
        .form-control:focus {
            border-color: var(--primary);
            background: var(--input-bg);
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.18);
        }
        .toggle-pw-btn {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            color: var(--text-muted);
            cursor: pointer;
            padding: 4px;
            display: flex;
            align-items: center;
        }
        .btn-submit {
            width: 100%;
            padding: 12px;
            font-size: 14.5px;
            font-weight: 600;
            border-radius: 10px;
            border: none;
            background: var(--primary);
            color: #ffffff;
            cursor: pointer;
            transition: background 0.15s, transform 0.05s;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
        }
        .btn-submit:hover {
            background: var(--primary-hover);
        }
        .btn-submit:active {
            transform: scale(0.99);
        }
        .site-brand {
            margin-top: 24px;
            font-size: 12px;
            color: var(--text-muted);
        }
        .site-brand a {
            color: inherit;
            text-decoration: none;
            transition: color 0.15s;
        }
        .site-brand a:hover {
            color: var(--primary);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="lock-icon-wrapper">
                <svg fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
            </div>

            <h1><?= _e('Password Protected Access') ?></h1>
            <p class="subtitle"><?= _e('Enter the password set by the administrator to unlock the file content.') ?></p>

            <div class="file-info-box">
                <div class="file-info-icon"><?= $mediaType['icon'] ?></div>
                <div class="file-info-details">
                    <div class="file-name" title="<?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="file-meta">
                        <?= $mediaType['label'] ?> &bull; <?= $fileSizeFormatted ?>
                    </div>
                </div>
                <span class="badge-locked"><?= _e('Private') ?></span>
            </div>

            <?php if ($error): ?>
                <div class="alert-error">
                    <svg style="width: 18px; height: 18px; flex-shrink: 0;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                </div>
            <?php endif; ?>

            <form method="POST" action="">
                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                <div class="form-group">
                    <input type="password" id="media-pwd" name="media_password" class="form-control" placeholder="<?= _e('Enter password...') ?>" required autofocus>
                    <button type="button" class="toggle-pw-btn" onclick="togglePasswordVisibility()" title="<?= _e('Show/hide password') ?>">
                        <svg id="eye-icon" style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/>
                            <circle cx="12" cy="12" r="3"/>
                        </svg>
                    </button>
                </div>
                <button type="submit" class="btn-submit">
                    <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                        <path d="M8 11V7a4 4 0 118 0m-4 8v2m-6 4h12a2 2 0 002-2v-6a2 2 0 00-2-2H6a2 2 0 00-2 2v6a2 2 0 002 2z"/>
                    </svg>
                    <?= _e('Unlock and view file') ?>
                </button>
            </form>

            <div class="site-brand">
                <a href="/">&larr; <?= htmlspecialchars((string)$siteTitle, ENT_QUOTES, 'UTF-8') ?></a>
            </div>
        </div>
    </div>

    <script>
    function togglePasswordVisibility() {
        const input = document.getElementById('media-pwd');
        const isPassword = input.type === 'password';
        input.type = isPassword ? 'text' : 'password';
    }
    </script>
</body>
</html>
        <?php
    }

    /**
     * Renders the interactive media player / preview for an unlocked file.
     */
    public static function renderUnlockedView(array $media): void
    {
        $siteTitle = Database::getConnection()->query("SELECT value FROM settings WHERE key = 'site_title' LIMIT 1")->fetchColumn() ?: 'CMSbyPHP';
        $fileSizeKb = round((int)$media['file_size'] / 1024, 1);
        $fileSizeFormatted = $fileSizeKb > 1024 ? round($fileSizeKb / 1024, 2) . ' MB' : $fileSizeKb . ' KB';
        $mediaType = self::detectMediaTypeCategory($media['mime_type'], $media['filename']);
        $streamUrl = '?stream=1';
        $downloadUrl = '?download=1';
        $lockUrl = '?lock=1';
        ?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars((string)$siteTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" type="image/svg+xml" href="/site/assets/img/favicon.svg">
    <style>
        :root {
            --bg-body: #0b1120;
            --bg-card: #151d30;
            --border-card: #22304d;
            --text-main: #f1f5f9;
            --text-muted: #94a3b8;
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --success: #10b981;
            --shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.5);
        }
        @media (prefers-color-scheme: light) {
            html:not([data-theme="dark"]) {
                --bg-body: #f8fafc;
                --bg-card: #ffffff;
                --border-card: #e2e8f0;
                --text-main: #0f172a;
                --text-muted: #64748b;
                --primary: #2563eb;
                --primary-hover: #1d4ed8;
                --shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.08);
            }
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
            background: var(--bg-body);
            color: var(--text-main);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
        }
        .topbar {
            padding: 14px 24px;
            background: var(--bg-card);
            border-bottom: 1px solid var(--border-card);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
        }
        @media (max-width: 640px) {
            .topbar { padding: 10px 14px; gap: 8px; }
            .topbar-title { max-width: 170px !important; font-size: 13px !important; }
            .media-stage { padding: 12px !important; }
            .media-box-unlocked { padding: 16px !important; max-width: 100% !important; }
        }
        .topbar-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .badge-unlocked {
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            background: rgba(16, 185, 129, 0.15);
            color: var(--success);
            border: 1px solid rgba(16, 185, 129, 0.3);
            border-radius: 6px;
            display: flex;
            align-items: center;
            gap: 5px;
        }
        .topbar-title {
            font-size: 14px;
            font-weight: 600;
            max-width: 320px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .topbar-right {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 14px;
            font-size: 13px;
            font-weight: 600;
            border-radius: 8px;
            text-decoration: none;
            transition: all 0.15s;
            cursor: pointer;
        }
        .btn-primary {
            background: var(--primary);
            color: #ffffff;
            border: none;
        }
        .btn-primary:hover {
            background: var(--primary-hover);
        }
        .btn-secondary {
            background: rgba(255, 255, 255, 0.05);
            color: var(--text-muted);
            border: 1px solid var(--border-card);
        }
        .btn-secondary:hover {
            color: var(--text-main);
            border-color: var(--text-muted);
        }
        .main-content {
            flex-grow: 1;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 20px;
        }
        .viewer-box {
            width: 100%;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            gap: 16px;
        }
        .video-wrapper {
            display: inline-flex;
            justify-content: center;
            align-items: center;
            max-width: 100%;
            border-radius: 14px;
            overflow: hidden;
            box-shadow: 0 20px 45px -10px rgba(0, 0, 0, 0.55);
            background: #000000;
        }
        video {
            display: block;
            width: auto;
            height: auto;
            max-width: 100vw;
            max-height: 78vh;
            border-radius: 14px;
            outline: none;
            background: #000000;
        }
        .video-actions {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 12px;
            margin-top: 4px;
        }
        .btn-download-lg {
            padding: 10px 22px;
            font-size: 14px;
            font-weight: 600;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.35);
        }
        audio {
            width: 100%;
            max-width: 520px;
            margin: 20px 0;
            border-radius: 30px;
        }
        img.preview-img {
            max-width: 100%;
            max-height: 78vh;
            border-radius: 12px;
            box-shadow: var(--shadow);
            object-fit: contain;
        }
        iframe.preview-pdf {
            width: 100%;
            height: 80vh;
            border-radius: 12px;
            border: 1px solid var(--border-card);
            box-shadow: var(--shadow);
        }
        .generic-file-card {
            background: var(--bg-card);
            border: 1px solid var(--border-card);
            border-radius: 16px;
            padding: 48px;
            text-align: center;
            box-shadow: var(--shadow);
            max-width: 480px;
            width: 100%;
        }
    </style>
</head>
<body>
    <header class="topbar">
        <div class="topbar-left">
            <span class="badge-unlocked">
                <svg style="width: 14px; height: 14px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 019.9-1"/>
                </svg>
                <?= _e('Unlocked') ?>
            </span>
            <span class="topbar-title" title="<?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?>">
                <?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?>
            </span>
            <span style="font-size: 12px; color: var(--text-muted);">(<?= $fileSizeFormatted ?>)</span>
        </div>
        <div class="topbar-right">
            <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary" download="<?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?>">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <path d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                </svg>
                <?= _e('Download file') ?>
            </a>
            <a href="<?= htmlspecialchars($lockUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-secondary" title="<?= _e('Lock again') ?>">
                <svg style="width: 16px; height: 16px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                    <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                    <path d="M7 11V7a5 5 0 0110 0v4"/>
                </svg>
                <?= _e('Lock') ?>
            </a>
        </div>
    </header>

    <main class="main-content">
        <div class="viewer-box">
            <?php if ($mediaType['type'] === 'video'): ?>
                <div class="video-wrapper">
                    <video id="media-video" controls autoplay playsinline preload="metadata">
                        <source src="<?= htmlspecialchars($streamUrl, ENT_QUOTES, 'UTF-8') ?>" type="<?= htmlspecialchars($media['mime_type'], ENT_QUOTES, 'UTF-8') ?>">
                        <?= _e('Your browser does not support the video tag. You can') ?> <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>"><?= _e('download the video file') ?></a>.
                    </video>
                </div>
                <div class="video-actions">
                    <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary btn-download-lg" download="<?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?>">
                        <svg style="width: 18px; height: 18px;" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 16v1a3 3 0 003 3h10a3 3 0 003-3v-1m-4-4l-4 4m0 0l-4-4m4 4V4"/>
                        </svg>
                        <span><?= _e('Download video') ?> (<?= $fileSizeFormatted ?>)</span>
                    </a>
                </div>
                <script>
                    (function() {
                        const v = document.getElementById('media-video');
                        if (!v) return;
                        function fitVideo() {
                            if (!v.videoWidth || !v.videoHeight) return;
                            const aspect = v.videoWidth / v.videoHeight;
                            const maxH = window.innerHeight * 0.76;
                            const maxW = Math.min(window.innerWidth - 32, 1100);
                            let targetH = maxH;
                            let targetW = targetH * aspect;
                            if (targetW > maxW) {
                                targetW = maxW;
                                targetH = targetW / aspect;
                            }
                            v.style.width = Math.round(targetW) + 'px';
                            v.style.height = Math.round(targetH) + 'px';
                        }
                        v.addEventListener('loadedmetadata', fitVideo);
                        window.addEventListener('resize', fitVideo);
                        if (v.readyState >= 1) fitVideo();
                    })();
                </script>
            <?php elseif ($mediaType['type'] === 'audio'): ?>
                <div class="generic-file-card">
                    <div style="font-size: 54px; margin-bottom: 12px;">🎵</div>
                    <h2 style="font-size: 18px; margin-bottom: 6px;"><?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 20px;"><?= $fileSizeFormatted ?></p>
                    <audio controls autoplay preload="metadata">
                        <source src="<?= htmlspecialchars($streamUrl, ENT_QUOTES, 'UTF-8') ?>" type="<?= htmlspecialchars($media['mime_type'], ENT_QUOTES, 'UTF-8') ?>">
                    </audio>
                </div>
            <?php elseif ($mediaType['type'] === 'image'): ?>
                <img class="preview-img" src="<?= htmlspecialchars($streamUrl, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?>">
            <?php elseif ($mediaType['type'] === 'pdf'): ?>
                <iframe class="preview-pdf" src="<?= htmlspecialchars($streamUrl, ENT_QUOTES, 'UTF-8') ?>"></iframe>
            <?php else: ?>
                <div class="generic-file-card">
                    <div style="font-size: 54px; margin-bottom: 12px;"><?= $mediaType['icon'] ?></div>
                    <h2 style="font-size: 18px; margin-bottom: 6px;"><?= htmlspecialchars($media['filename'], ENT_QUOTES, 'UTF-8') ?></h2>
                    <p style="font-size: 13px; color: var(--text-muted); margin-bottom: 24px;"><?= $mediaType['label'] ?> &bull; <?= $fileSizeFormatted ?></p>
                    <a href="<?= htmlspecialchars($downloadUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-primary" style="padding: 12px 24px; font-size: 15px;">
                        <?= _e('Download file') ?>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </main>
</body>
</html>
        <?php
    }

    /**
     * Helpers for media type classification.
     */
    public static function detectMediaTypeCategory(string $mime, string $filename = ''): array
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'avi', 'mkv', 'ogv'], true)) {
            return ['type' => 'video', 'label' => __('Video') . ' (' . strtoupper($ext ?: 'MP4') . ')', 'icon' => '🎬'];
        }
        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'ogg', 'weba', 'm4a', 'flac'], true)) {
            return ['type' => 'audio', 'label' => __('Audio') . ' (' . strtoupper($ext ?: 'MP3') . ')', 'icon' => '🎵'];
        }
        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'webp', 'gif', 'svg'], true)) {
            return ['type' => 'image', 'label' => __('Image') . ' (' . strtoupper($ext ?: 'IMG') . ')', 'icon' => '🖼️'];
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return ['type' => 'pdf', 'label' => __('PDF Document'), 'icon' => '📄'];
        }
        if (in_array($ext, ['zip', 'rar', '7z', 'tar', 'gz'], true) || str_contains($mime, 'zip')) {
            return ['type' => 'archive', 'label' => __('Archive') . ' (' . strtoupper($ext) . ')', 'icon' => '📦'];
        }
        if (in_array($ext, ['doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'txt'], true)) {
            return ['type' => 'doc', 'label' => __('Document') . ' (' . strtoupper($ext) . ')', 'icon' => '📝'];
        }

        return ['type' => 'file', 'label' => __('File') . ' (' . strtoupper($ext ?: 'BIN') . ')', 'icon' => '📁'];
    }
}
