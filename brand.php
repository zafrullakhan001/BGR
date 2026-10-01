<?php
declare(strict_types=1);

require __DIR__ . '/lib/Store.php';

$types = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp', 'gif' => 'image/gif'];
$ext = pathinfo(Store::brand()['logo'], PATHINFO_EXTENSION);
$path = Store::DIR . '/branding/logo.' . $ext;

if (!isset($types[$ext]) || !is_file($path)) {
    http_response_code(404);
    exit;
}
header('Content-Type: ' . $types[$ext]);
header('Content-Length: ' . filesize($path));
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=86400');
readfile($path);
