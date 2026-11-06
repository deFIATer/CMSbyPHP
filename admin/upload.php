<?php
declare(strict_types=1);

define('IN_ADMIN', true);
require_once __DIR__ . '/../core/bootstrap.php';

use Core\Auth;
use Core\Database;
use Core\Router;

header('Content-Type: application/json; charset=utf-8');

// Verify user authentication
if (!Auth::check()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// Ensure valid POST upload request
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_FILES['file'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No file uploaded']);
    exit;
}

$file = $_FILES['file'];

// Allowed file types (Images, Videos, Audio, Documents, Archives)
$allowedMimes = [
    // Images
    'image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/svg+xml', 'image/x-icon',
    // Videos
    'video/mp4', 'video/webm', 'video/ogg', 'video/quicktime',
    // Audio
    'audio/mpeg', 'audio/wav', 'audio/x-wav', 'audio/ogg', 'audio/webm',
    // Documents & Text
    'application/pdf', 'text/plain',
    'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'application/vnd.ms-powerpoint', 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
    // Archives
    'application/zip', 'application/x-zip-compressed', 'application/x-tar', 'application/gzip'
];

// Helper to convert php.ini size strings (e.g. "64M") to bytes
$parseIniBytes = function (string $val): int {
    $val = trim($val);
    $last = strtolower($val[strlen($val) - 1] ?? '');
    $num = (int)$val;
    return match ($last) {
        'g' => $num * 1024 * 1024 * 1024,
        'm' => $num * 1024 * 1024,
        'k' => $num * 1024,
        default => $num,
    };
};

$iniUploadMax = $parseIniBytes(ini_get('upload_max_filesize') ?: '32M');
$iniPostMax = $parseIniBytes(ini_get('post_max_size') ?: '32M');
$maxSize = min($iniUploadMax, $iniPostMax);
$cfgMax = (int) (\Core\Config::get('upload_max_size') ?? 0);
if ($cfgMax > 0) {
    $maxSize = min($maxSize, $cfgMax * 1024 * 1024);
}
if ($maxSize < 1024 * 1024) {
    $maxSize = 32 * 1024 * 1024; // Fallback to 32MB
}

// Validate upload status and file size
if ($file['error'] !== UPLOAD_ERR_OK || $file['size'] > $maxSize) {
    http_response_code(400);
    $limitMb = round($maxSize / (1024 * 1024), 1);
    $errMsg = match ($file['error']) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => "Plik przekracza maksymalny dopuszczalny rozmiar ({$limitMb} MB).",
        UPLOAD_ERR_PARTIAL => 'Przesyłanie pliku zostało przerwane.',
        UPLOAD_ERR_NO_FILE => 'Nie przesłano żadnego pliku.',
        UPLOAD_ERR_NO_TMP_DIR => 'Brak katalogu tymczasowego na serwerze.',
        UPLOAD_ERR_CANT_WRITE => 'Nie udało się zapisać pliku na dysku serwera.',
        default => ($file['size'] > $maxSize)
            ? "Plik przekracza maksymalny dopuszczalny rozmiar ({$limitMb} MB)."
            : 'Wystąpił błąd podczas przesyłania pliku (kod ' . $file['error'] . ').',
    };
    echo json_encode(['error' => $errMsg]);
    exit;
}

// Validate MIME type via fileinfo
$finfo = new finfo(FILEINFO_MIME_TYPE);
$mime = $finfo->file($file['tmp_name']);

// Extension fallback for certain containers
$origExt = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($mime === 'application/octet-stream' || $mime === 'video/mp4') {
    if ($origExt === 'mp4') $mime = 'video/mp4';
    elseif ($origExt === 'webm') $mime = 'video/webm';
    elseif ($origExt === 'mov') $mime = 'video/quicktime';
    elseif ($origExt === 'mp3') $mime = 'audio/mpeg';
    elseif ($origExt === 'pdf') $mime = 'application/pdf';
    elseif ($origExt === 'zip') $mime = 'application/zip';
}

if (!in_array($mime, $allowedMimes, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Disallowed format (' . htmlspecialchars($mime) . '). Allowed: JPG, PNG, WEBP, GIF, SVG, MP4, WEBM, MOV, MP3, WAV, PDF, DOCX, ZIP']);
    exit;
}

// Sanitize SVG files to protect against Stored XSS
if ($mime === 'image/svg+xml') {
    $svgContent = (string)file_get_contents($file['tmp_name']);
    if (preg_match('/<script|javascript:|onload|onerror|onclick/i', $svgContent)) {
        http_response_code(400);
        echo json_encode(['error' => 'SVG file contains disallowed embedded scripts.']);
        exit;
    }
}

// Map MIME type to safe extension
$extMap = [
    'image/jpeg'    => 'jpg',
    'image/png'     => 'png',
    'image/webp'    => 'webp',
    'image/gif'     => 'gif',
    'image/svg+xml' => 'svg',
    'image/x-icon'  => 'ico',
    'video/mp4'     => 'mp4',
    'video/webm'    => 'webm',
    'video/quicktime'=> 'mov',
    'video/ogg'     => 'ogv',
    'audio/mpeg'    => 'mp3',
    'audio/wav'     => 'wav',
    'audio/x-wav'   => 'wav',
    'audio/ogg'     => 'ogg',
    'audio/webm'    => 'weba',
    'application/pdf'=> 'pdf',
    'text/plain'    => 'txt',
    'application/msword' => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    'application/vnd.ms-excel' => 'xls',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    'application/vnd.ms-powerpoint' => 'ppt',
    'application/vnd.openxmlformats-officedocument.presentationml.presentation' => 'pptx',
    'application/zip' => 'zip',
    'application/x-zip-compressed' => 'zip',
    'application/x-tar' => 'tar',
    'application/gzip' => 'gz'
];
$ext = $extMap[$mime] ?? ($origExt ?: 'bin');

// Zachowaj oryginalną nazwę pliku (sanitize pod kątem systemu plików)
$origName = basename((string)($file['name'] ?? ''));
$rawBase = pathinfo($origName, PATHINFO_FILENAME);
$rawExt = pathinfo($origName, PATHINFO_EXTENSION);
if ($rawExt === '') {
    $rawExt = $ext;
}
$rawExt = strtolower($rawExt);

// Bezpieczne usunięcie znaków niedozwolonych w systemach plików (\ / ? * : ; " < > | oraz bajtów kontrolnych)
$cleanBase = preg_replace('/[\/\\\\?*:;"<>|\x00-\x1f\x7f]+/u', '', $rawBase);
$cleanBase = trim($cleanBase);
if ($cleanBase === '') {
    $cleanBase = 'plik';
}
$displayFilename = $cleanBase . ($rawExt !== '' ? ('.' . $rawExt) : '');

$isPrivateUpload = !empty($_POST['is_private']) && $_POST['is_private'] === '1' && !empty($_POST['password']);
$uploadPassword = $isPrivateUpload ? trim((string)$_POST['password']) : null;

$uploadDir = $isPrivateUpload ? \Core\ProtectedMedia::getProtectedDir() . '/' : __DIR__ . '/../uploads/';
if (!is_dir($uploadDir)) {
    @mkdir($uploadDir, 0775, true);
}

// Jeśli plik o takiej nazwie już istnieje w folderze docelowym, dodaj kolejny numer (np. plik-1.jpg, plik-2.jpg)
$finalName = $displayFilename;
$counter = 1;
while (file_exists($uploadDir . $finalName)) {
    $finalName = $cleanBase . '-' . $counter . ($rawExt !== '' ? ('.' . $rawExt) : '');
    $counter++;
}
$safeName = $finalName;
$displayFilename = $finalName;

if (move_uploaded_file($file['tmp_name'], $uploadDir . $safeName)) {
    $targetFile = $uploadDir . $safeName;

    // Auto-rotate smartphone photos according to EXIF orientation
    if ($mime === 'image/jpeg' && class_exists('Core\ImageOrientation')) {
        $ori = \Core\ImageOrientation::read($targetFile);
        if ($ori > 1 && function_exists('imagecreatefromjpeg') && function_exists('imagejpeg')) {
            $gd = @imagecreatefromjpeg($targetFile);
            if ($gd) {
                $gd = \Core\ImageOrientation::applyGd($gd, $targetFile);
                @imagejpeg($gd, $targetFile, 92);
                @imagedestroy($gd);
            }
        }
    }

    $basePrefix = class_exists('Core\Router') ? Router::getBaseSubdirectory() : '';

    $webpPath = null;
    if (!$isPrivateUpload && str_starts_with($mime, 'image/') && \Core\ImageOptimizer::isEnabled() && \Core\ImageOptimizer::isSupported()) {
        $converted = \Core\ImageOptimizer::convertToWebp($uploadDir . $safeName);
        if ($converted !== null) {
            $webpPath = '/uploads/' . basename($converted);
        }
    }

    $storagePath = $isPrivateUpload ? ('/' . \Core\ProtectedMedia::PROTECTED_DIR . '/' . $safeName) : ('/uploads/' . $safeName);
    $passwordHash = $isPrivateUpload ? password_hash($uploadPassword, PASSWORD_DEFAULT) : null;
    $accessToken = $isPrivateUpload ? bin2hex(random_bytes(16)) : null;

    // Record media entry in database
    $db = Database::getConnection();

    // Safe user ID resolution to ensure foreign key constraint passes
    $uploaderId = Auth::id();
    if ($uploaderId <= 0) {
        $uploaderId = null;
    } else {
        $checkUser = $db->prepare("SELECT id FROM users WHERE id = :uid LIMIT 1");
        $checkUser->execute([':uid' => $uploaderId]);
        if (!$checkUser->fetchColumn()) {
            $uploaderId = null;
        }
    }

    $stmt = $db->prepare("
        INSERT INTO media (filename, filepath, mime_type, file_size, webp_path, is_private, password_hash, access_token, user_id) 
        VALUES (:f, :p, :m, :s, :w, :ip, :ph, :at, :u)
    ");
    $stmt->execute([
        ':f'  => $displayFilename,
        ':p'  => $storagePath,
        ':m'  => $mime,
        ':s'  => $file['size'],
        ':w'  => $webpPath,
        ':ip' => $isPrivateUpload ? 1 : 0,
        ':ph' => $passwordHash,
        ':at' => $accessToken,
        ':u'  => $uploaderId
    ]);

    $newId = (int)$db->lastInsertId();
    $mediaRecord = [
        'id' => $newId,
        'filename' => $displayFilename,
        'filepath' => $storagePath,
        'is_private' => $isPrivateUpload ? 1 : 0
    ];

    $publicUrl = \Core\ProtectedMedia::getAccessUrl($mediaRecord);

    echo json_encode([
        'id'       => $newId,
        'filename' => $displayFilename,
        'location' => $publicUrl,
        'url'      => $publicUrl,
        'is_private' => $isPrivateUpload ? 1 : 0
    ]);
    exit;
}

http_response_code(500);
echo json_encode(['error' => 'Failed to save file on disk']);