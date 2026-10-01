<?php
declare(strict_types=1);

require __DIR__ . '/lib/ImageEditor.php';
require __DIR__ . '/lib/IconPack.php';

// Project venv, because Apache runs as a service and cannot see per-user pip packages.
const PYTHON_BIN = __DIR__ . (PHP_OS_FAMILY === 'Windows' ? '/tools/venv/Scripts/python.exe' : '/tools/venv/bin/python');
const MAX_BYTES = 30 * 1024 * 1024;
const MAX_PIXELS = 40_000_000;
const TMP_DIR = __DIR__ . '/storage/tmp';

session_start();
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

/** Runs tools/edit.py without a shell; returns the error text, or null on success. */
function runPython(string $mode, string $in, string $out, string $log, array $extra = []): ?string
{
    $env = getenv() + ['U2NET_HOME' => __DIR__ . '/storage/models'];
    $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
    $proc = @proc_open(
        [PYTHON_BIN, __DIR__ . '/tools/edit.py', $mode, $in, $out, ...$extra],
        [1 => ['file', $null, 'w'], 2 => ['file', $log, 'w']],
        $pipes,
        null,
        $env
    );
    if (!is_resource($proc)) {
        return 'Python could not be started. Create tools/venv and install requirements.txt into it.';
    }
    if (proc_close($proc) === 0 && is_file($out)) {
        return null;
    }
    $lines = array_filter(array_map('trim', explode("\n", (string) @file_get_contents($log))));
    return $lines ? (string) end($lines) : 'The Python step failed.';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    fail(405, 'Use the form on the page.');
}
if (!hash_equals($_SESSION['csrf'] ?? '', (string) ($_POST['csrf'] ?? ''))) {
    fail(403, 'Your session expired. Reload the page and try again.');
}

$file = $_FILES['image'] ?? null;
if (!$file || $file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
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
if (!in_array($op, ['rotate_left', 'rotate_right', 'clean', 'cutout', 'colorkey', 'export'], true)
    || !in_array($format, ['png', 'jpg', 'webp', 'gif', 'bmp', 'ico', 'svg', 'dxf', ...IconPack::PACKS], true)) {
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

if (!is_dir(TMP_DIR)) {
    mkdir(TMP_DIR, 0700, true);
}
$base = TMP_DIR . '/' . bin2hex(random_bytes(16));
$work = $base . '.png';
$out = $base . '.' . $ext;
$log = $base . '.log';
register_shutdown_function(static function () use ($work, $out, $log): void {
    foreach ([$work, $out, $log] as $path) {
        @unlink($path);
    }
});

try {
    $editor = (new ImageEditor($file['tmp_name']))->adjust($brightness, $contrast)->sharpen($sharpness);
    match ($op) {
        'rotate_left' => $editor->rotate(90),
        'rotate_right' => $editor->rotate(-90),
        'export' => $isIcon ? $editor : $editor->resize($width),
        default => $editor,
    };

    $pythonMode = match (true) {
        $op === 'cutout', $op === 'clean', $op === 'colorkey' => $op,
        $format === 'svg', $format === 'dxf' => $format,
        default => null,
    };

    if ($isIcon) {
        $pack = new IconPack($editor->image(), $iconBg, $iconPadding);
        $format === 'ico'
            ? file_put_contents($out, $pack->ico([16, 32, 48, 64, 128, 256]))
            : $pack->zip($format, $out);
    } elseif ($pythonMode === null) {
        $editor->save($out, $format);
    } else {
        $editor->save($work, 'png');
        unset($editor);
        $extra = $pythonMode === 'colorkey' ? [$keyColor, (string) $keyTolerance, $keyEdges] : [];
        $error = runPython($pythonMode, $work, $out, $log, $extra);
        if ($error !== null) {
            throw new RuntimeException($error);
        }
    }

    $types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif',
        'bmp' => 'image/bmp', 'ico' => 'image/x-icon', 'svg' => 'image/svg+xml', 'dxf' => 'application/dxf',
        'zip' => 'application/zip'];
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
