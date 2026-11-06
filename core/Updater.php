<?php
declare(strict_types=1);

namespace Core;

/**
 * Class Updater
 *
 * Handles CMSbyPHP core updates from the official GitHub repository:
 * https://github.com/deFIATer/CMSbyPHP
 *
 * Features:
 * - Version check against GitHub Releases / Tags API
 * - 12-hour transient cache to avoid GitHub API rate limits
 * - Automated pre-update full ZIP backup (DB + media)
 * - Safe file extraction preserving local configs and data
 * - Cache invalidation and rollback safety
 */
final class Updater
{
    public const VERSION = Version::CURRENT;
    public const GITHUB_REPO = 'deFIATer/CMSbyPHP';
    public const API_BASE = 'https://api.github.com/repos/deFIATer/CMSbyPHP';

    /**
     * Checks if a new version is available on GitHub.
     */
    public static function check(bool $force = false): array
    {
        $cacheFile = __DIR__ . '/../data/cache/update_check.json';
        
        if (!$force && file_exists($cacheFile)) {
            $cached = @json_decode((string)file_get_contents($cacheFile), true);
            if (is_array($cached) && isset($cached['checked_at']) && (time() - $cached['checked_at']) < 43200) {
                return $cached;
            }
        }

        $result = [
            'has_update'      => false,
            'current_version' => self::VERSION,
            'latest_version'  => self::VERSION,
            'release_name'    => '',
            'release_body'    => '',
            'published_at'    => '',
            'download_url'    => '',
            'checked_at'      => time(),
            'error'           => null
        ];

        // Fetch latest release from GitHub
        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: CMSbyPHP/" . self::VERSION . "\r\nAccept: application/vnd.github.v3+json\r\n",
                'timeout' => 8
            ]
        ];
        $context = stream_context_create($opts);

        // 1. Try releases/latest
        $apiUrl = self::API_BASE . '/releases/latest';
        $json = @file_get_contents($apiUrl, false, $context);

        if ($json !== false) {
            $data = @json_decode($json, true);
            if (is_array($data) && isset($data['tag_name'])) {
                $rawTag = (string)$data['tag_name'];
                $cleanTag = ltrim($rawTag, 'vV');
                $result['latest_version'] = $cleanTag;
                $result['release_name'] = $data['name'] ?? ('Wersja ' . $cleanTag);
                $result['release_body'] = $data['body'] ?? '';
                $result['published_at'] = $data['published_at'] ?? '';
                $result['download_url'] = $data['zipball_url'] ?? "https://github.com/" . self::GITHUB_REPO . "/archive/refs/tags/{$rawTag}.zip";
                
                if (version_compare($cleanTag, self::VERSION, '>')) {
                    $result['has_update'] = true;
                }
            }
        } else {
            // 2. Fallback to tags if no official release yet
            $tagsUrl = self::API_BASE . '/tags';
            $tagsJson = @file_get_contents($tagsUrl, false, $context);
            if ($tagsJson !== false) {
                $tags = @json_decode($tagsJson, true);
                if (is_array($tags) && !empty($tags[0]['name'])) {
                    $latestTag = (string)$tags[0]['name'];
                    $cleanTag = ltrim($latestTag, 'vV');
                    $result['latest_version'] = $cleanTag;
                    $result['release_name'] = 'Wersja ' . $cleanTag;
                    $result['download_url'] = $tags[0]['zipball_url'] ?? "https://github.com/" . self::GITHUB_REPO . "/archive/refs/tags/{$latestTag}.zip";
                    if (version_compare($cleanTag, self::VERSION, '>')) {
                        $result['has_update'] = true;
                    }
                }
            } else {
                $result['error'] = 'Nie udało się połączyć z GitHub API (kod błędu lub limit zapytań).';
            }
        }

        // Cache the check
        $cacheDir = dirname($cacheFile);
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0775, true);
        }
        @file_put_contents($cacheFile, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $result;
    }

    /**
     * Executes the update process.
     */
    public static function update(bool $autoBackup = true): array
    {
        Auth::requireCapability('manage_settings');

        $log = [];
        $backupFile = null;

        // Step 1: Pre-update Backup
        if ($autoBackup) {
            $log[] = 'Inicjalizacja automatycznej kopii zapasowej przed aktualizacją...';
            try {
                $backupFile = Backup::createFullArchive();
                $log[] = '✓ Utworzono kopię zapasową: ' . basename($backupFile);
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'message' => 'Błąd tworzenia kopii zapasowej: ' . $e->getMessage(),
                    'log' => $log
                ];
            }
        }

        // Step 2: Download Package
        $check = self::check(true);
        $downloadUrl = $check['download_url'] ?: "https://github.com/" . self::GITHUB_REPO . "/archive/refs/heads/main.zip";
        $log[] = 'Pobieranie paczki aktualizacyjnej z GitHuba...';

        $opts = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: CMSbyPHP/" . self::VERSION . "\r\n",
                'timeout' => 30,
                'follow_location' => 1
            ]
        ];
        $context = stream_context_create($opts);
        $zipData = @file_get_contents($downloadUrl, false, $context);

        if ($zipData === false || strlen($zipData) < 1000) {
            return [
                'success' => false,
                'message' => 'Nie udało się pobrać paczki aktualizacyjnej z repozytorium GitHuba.',
                'log' => $log
            ];
        }

        $tempZip = __DIR__ . '/../data/cache/update_package.zip';
        @file_put_contents($tempZip, $zipData);
        $log[] = '✓ Pobrano paczkę (' . round(strlen($zipData) / 1024, 1) . ' KB)';

        // Step 3: Extract and Apply Update
        $extractDir = __DIR__ . '/../data/cache/update_extracted_' . time();
        @mkdir($extractDir, 0775, true);

        $zip = new \ZipArchive();
        if ($zip->open($tempZip) !== true) {
            @unlink($tempZip);
            return [
                'success' => false,
                'message' => 'Nie udało się otworzyć pobranego archiwum ZIP.',
                'log' => $log
            ];
        }

        $zip->extractTo($extractDir);
        $zip->close();
        @unlink($tempZip);

        // GitHub zipballs contain a root directory (e.g., CMSbyPHP-1.0.1/ or CMSbyPHP-main/)
        $subdirs = glob($extractDir . '/*', GLOB_ONLYDIR);
        $sourceDir = (!empty($subdirs)) ? $subdirs[0] : $extractDir;

        $log[] = 'Instalacja zaktualizowanych plików systemowych...';

        // Protected directories and files that MUST NOT be overwritten or touched
        $protected = [
            'data',
            'uploads',
            'cmsbyphpconfig.php',
            'modoconfig.php',
            'ftp_config.json',
            '.git',
            'deploy_ftp.py',
            'deploy.sh'
        ];

        $targetBase = realpath(__DIR__ . '/..');
        $updatedCount = 0;

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($sourceDir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $item) {
            $relPath = ltrim(substr($item->getPathname(), strlen($sourceDir)), '/\\');
            $relParts = explode('/', str_replace('\\', '/', $relPath));
            $topFolder = $relParts[0];

            if (in_array($topFolder, $protected, true)) {
                continue;
            }

            $destPath = $targetBase . '/' . $relPath;

            if ($item->isDir()) {
                if (!is_dir($destPath)) {
                    @mkdir($destPath, 0775, true);
                }
            } else {
                $destDir = dirname($destPath);
                if (!is_dir($destDir)) {
                    @mkdir($destDir, 0775, true);
                }
                if (@copy($item->getPathname(), $destPath)) {
                    $updatedCount++;
                }
            }
        }

        // Clean up extracted temp directory
        self::deleteDirectoryRecursive($extractDir);

        // Step 4: Flush Caches
        if (class_exists(\Core\PageCache::class)) {
            \Core\PageCache::purge();
        }
        $log[] = "✓ Zaktualizowano pomyślnie {$updatedCount} plików systemowych.";
        $log[] = '✓ Wyczyszczono pamięć podręczną (Cache).';

        // Clear update check transient
        @unlink(__DIR__ . '/../data/cache/update_check.json');

        return [
            'success'       => true,
            'message'       => 'System CMSbyPHP został pomyślnie zaktualizowany!',
            'backup_file'   => $backupFile,
            'updated_count' => $updatedCount,
            'log'           => $log
        ];
    }

    private static function deleteDirectoryRecursive(string $dir): void
    {
        if (!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.', '..']);
        foreach ($files as $file) {
            $p = $dir . '/' . $file;
            is_dir($p) ? self::deleteDirectoryRecursive($p) : @unlink($p);
        }
        @rmdir($dir);
    }
}
