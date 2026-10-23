<?php
declare(strict_types=1);

/**
 * Built-in PHP development server router for CMSbyPHP.
 * Usage: php -S 127.0.0.1:8000 router.php
 */

$uri = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$filePath = __DIR__ . $uri;

// 1. Direct static asset requests (exclude protected data and /file/ routes)
if ($uri !== '/' && !str_starts_with($uri, '/data/') && !str_starts_with($uri, '/file/') && file_exists($filePath) && !is_dir($filePath)) {
    return false; // let the built-in server serve the static file directly
}

// 1b. Fallback: Route /admin/uploads/* to /uploads/*
if (str_starts_with($uri, '/admin/uploads/')) {
    $realUploadPath = __DIR__ . substr($uri, 6); // strips '/admin'
    if (file_exists($realUploadPath) && !is_dir($realUploadPath)) {
        $mime = mime_content_type($realUploadPath) ?: 'application/octet-stream';
        header('Content-Type: ' . $mime);
        readfile($realUploadPath);
        exit;
    }
}

// 2. Directory requests containing index.php (e.g. /admin/ or /admin)
if (rtrim($uri, '/') === '/admin') {
    if (!str_ends_with($uri, '/')) {
        header('Location: /admin/', true, 301);
        exit;
    }
    require __DIR__ . '/admin/index.php';
    exit;
}

if (str_starts_with($uri, '/admin/') && file_exists($filePath) && !is_dir($filePath)) {
    return false;
}

// 3. Fallback all other frontend routes to index.php
require __DIR__ . '/index.php';
