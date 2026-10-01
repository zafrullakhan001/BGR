<?php
declare(strict_types=1);

require __DIR__ . '/lib/ImageEditor.php';
require __DIR__ . '/lib/IconPack.php';
require __DIR__ . '/lib/session.php';

const MAX_BYTES = 30 * 1024 * 1024;
const MAX_PIXELS = 40_000_000;
const TMP_DIR = __DIR__ . '/storage/tmp';

app_session();
session_write_close();
set_time_limit(300);
ini_set('memory_limit', '1024M');

function fail(int $code, string $message): never
{
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode(['error' => $message]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Use the form on the page.');
}
if ($_POST === [] && $_FILES === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    fail(413, 'The server discarded the picture before PHP could read it. Raise post_max_size and upload_max_filesize above 32 MB.');
}
if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    fail(403, 'Your session expired. Reload the page and try again.');
}

$file = $_FILES['image'] ?? null;
$uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
    fail(413, 'The picture is larger than this server allows. Raise upload_max_filesize above 32 MB.');
}
if (!$file || $uploadError !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
    fail(400, 'The picture did not upload. Try again.');
}
if ($file['size'] > MAX_BYTES) {
    fail(413, 'The picture is larger than 30 MB.');
}
$mime = (new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name']);
if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'image/bmp', 'image/x-ms-bmp'], true)) {
    fail(415, 'Only JPG, PNG, WEBP, GIF and BMP pictures are supported.');
}
$size = @getimagesize($file['tmp_name']);
if (!$size || $size[0] * $size[1] > MAX_PIXELS) {
    fail(422, 'The picture is unreadable or larger than 40 megapixels.');
}

$op = (string) ($_POST['op'] ?? '');
$format = $op === 'export' ? (string) ($_POST['format'] ?? '') : 'png';
if (!in_array($op, ['rotate_left', 'rotate_right', 'grayscale', 'clean', 'cutout', 'colorkey', 'wand', 'export'], true)
    || !in_array($format, ['png', 'jpg', 'webp', 'gif', 'bmp', 'ico', ...IconPack::PACKS], true)) {
    fail(400, 'Unknown action.');
}
$isIcon = $format === 'ico' || in_array($format, IconPack::PACKS, true);
$ext = in_array($format, IconPack::PACKS, true) ? 'zip' : $format;
$brightness = max(0, min(200, (int) ($_POST['brightness'] ?? 100)));
$contrast = max(0, min(200, (int) ($_POST['contrast'] ?? 100)));
$sharpness = max(0, min(100, (int) ($_POST['sharpness'] ?? 0)));
$width = max(0, min(12000, (int) ($_POST['width'] ?? 0)));
$iconBg = preg_match('/^#[0-9a-f]{6}$/i', (string) ($_POST['icon_bg'] ?? '')) ? strtolower($_POST['icon_bg']) : '#ffffff';
$iconPadding = max(0, min(40, (int) ($_POST['icon_padding'] ?? 0))) / 100;
$keyColor = preg_match('/^#[0-9a-f]{6}$/i', (string) ($_POST['key_color'] ?? '')) ? strtolower($_POST['key_color']) : '#ffffff';
$keyTolerance = max(0, min(100, (int) ($_POST['key_tolerance'] ?? 20)));
$keyEdges = ($_POST['key_edges'] ?? '1') === '1' ? '1' : '0';
$keyX = is_numeric($_POST['key_x'] ?? null) ? (float) $_POST['key_x'] : -1.0;
$keyY = is_numeric($_POST['key_y'] ?? null) ? (float) $_POST['key_y'] : -1.0;
if ($op === 'wand' && ($keyX < 0 || $keyX > 1 || $keyY < 0 || $keyY > 1)) {
    fail(400, 'Click the area to remove.');
}

if (!is_dir(TMP_DIR)) {
    mkdir(TMP_DIR, 0700, true);
}
$out = TMP_DIR . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
register_shutdown_function(static function () use ($out): void {
    @unlink($out);
});

try {
    $editor = (new ImageEditor($file['tmp_name']))->adjust($brightness, $contrast)->sharpen($sharpness);
    match ($op) {
        'rotate_left' => $editor->rotate(90),
        'rotate_right' => $editor->rotate(-90),
        'grayscale' => $editor->grayscale(),
        'clean' => $editor->cleanDrawing(),
        'cutout' => $editor->removeEdgeBackground($keyTolerance),
        'colorkey' => $editor->removeColor($keyColor, $keyTolerance, $keyEdges === '1'),
        'wand' => $editor->removeWand($keyX, $keyY, $keyTolerance),
        'export' => $isIcon ? $editor : $editor->resize($width),
    };

    if ($isIcon) {
        $pack = new IconPack($editor->image(), $iconBg, $iconPadding);
        $format === 'ico'
            ? file_put_contents($out, $pack->ico([16, 32, 48, 64, 128, 256]))
            : $pack->zip($format, $out);
    } else {
        $editor->save($out, $format);
    }

    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'zip' => 'application/zip'];
    header('Content-Type: ' . $types[$ext]);
    header('Content-Length: ' . filesize($out));
    header('Content-Disposition: attachment; filename="picture.' . $ext . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    readfile($out);
} catch (RuntimeException $e) {
    fail(500, $e->getMessage());
} catch (Throwable $e) {
    error_log('BGR process.php: ' . $e);
    fail(500, 'The picture could not be processed.');
}
