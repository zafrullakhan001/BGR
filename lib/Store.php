<?php
declare(strict_types=1);

/** App settings in storage/app.sqlite; the GitHub token is kept encrypted with storage/.encryption_key. */
final class Store
{
    public const DIR = __DIR__ . '/../storage';
    /** Branding sizes in px: [min, max, default]. */
    public const SIZES = ['logo_size' => [20, 96, 38], 'title_size' => [18, 44, 28], 'tagline_size' => [11, 22, 15]];
    private const DB = self::DIR . '/app.sqlite';
    private const KEY = self::DIR . '/.encryption_key';

    private static ?PDO $db = null;

    public static function db(): PDO
    {
        if (self::$db === null) {
            if (!is_dir(self::DIR)) {
                mkdir(self::DIR, 0700, true);
            }
            $db = new PDO('sqlite:' . self::DB, null, null, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
            $db->exec('PRAGMA journal_mode = WAL');
            $db->exec('PRAGMA busy_timeout = 5000');
            $db->exec('CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL,
                updated_at INTEGER NOT NULL
            )');
            self::$db = $db;
        }
        return self::$db;
    }

    public static function get(string $key, string $default = ''): string
    {
        $st = self::db()->prepare('SELECT value FROM settings WHERE key = ?');
        $st->execute([$key]);
        $value = $st->fetchColumn();
        return $value === false ? $default : (string) $value;
    }

    public static function set(string $key, string $value): void
    {
        self::db()->prepare('INSERT INTO settings (key, value, updated_at) VALUES (?, ?, ?)
            ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at')
            ->execute([$key, $value, time()]);
    }

    public static function delete(string $key): void
    {
        self::db()->prepare('DELETE FROM settings WHERE key = ?')->execute([$key]);
    }

    public static function clampSize(string $key, mixed $value): int
    {
        [$min, $max, $default] = self::SIZES[$key];
        return is_numeric($value) ? max($min, min($max, (int) $value)) : $default;
    }

    /** Branding with defaults, safe to read on every page view. */
    public static function brand(): array
    {
        try {
            $brand = [
                'name' => self::get('app_name', 'Picture desk'),
                'tagline' => self::get('tagline', 'Clean up photos, drawings and app icons.'),
                'logo' => self::get('logo_file'),
                'logo_plate' => self::get('logo_plate', '1') === '1',
                'accent' => self::get('accent'),
            ];
            foreach (array_keys(self::SIZES) as $key) {
                $brand[$key] = self::clampSize($key, self::get($key));
            }
            return $brand;
        } catch (Throwable $e) {
            error_log('BGR Store::brand: ' . $e->getMessage());
            return ['name' => 'Picture desk', 'tagline' => 'Clean up photos, drawings and app icons.', 'logo' => '',
                'logo_plate' => true, 'accent' => '', ...array_map(static fn ($s) => $s[2], self::SIZES)];
        }
    }

    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            throw new RuntimeException('Could not encrypt the token.');
        }
        return base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(string $stored): string
    {
        $raw = base64_decode($stored, true);
        if ($raw === false || strlen($raw) < 29) {
            return '';
        }
        $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
        return $plain === false ? '' : $plain;
    }

    private static function key(): string
    {
        if (!is_file(self::KEY)) {
            if (!is_dir(self::DIR)) {
                mkdir(self::DIR, 0700, true);
            }
            file_put_contents(self::KEY, base64_encode(random_bytes(32)), LOCK_EX);
            @chmod(self::KEY, 0600);
        }
        $key = base64_decode(trim((string) file_get_contents(self::KEY)), true);
        if ($key === false || strlen($key) !== 32) {
            throw new RuntimeException('storage/.encryption_key is damaged.');
        }
        return $key;
    }
}
