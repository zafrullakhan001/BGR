<?php
declare(strict_types=1);

/** The running updater installs lib/ before it is allowed to write these root files. */
function app_publish_server_files(): void
{
    $root = dirname(__DIR__);
    foreach (['.user.ini', 'web.config'] as $name) {
        $src = __DIR__ . '/deploy/' . $name;
        if (!is_file($src)) {
            continue;
        }
        $data = (string) file_get_contents($src);
        $dst = $root . '/' . $name;
        if (!is_file($dst) || file_get_contents($dst) !== $data) {
            @file_put_contents($dst, $data, LOCK_EX);
        }
    }
}

function app_session(): void
{
    app_publish_server_files();
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    $dir = dirname(__DIR__) . '/storage/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}
