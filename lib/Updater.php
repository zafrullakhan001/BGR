<?php
declare(strict_types=1);

/** Updates the app from a GitHub zipball. Needs no git binary and no .git folder; storage/ is never touched. */
final class Updater
{
    private const ROOT = __DIR__ . '/..';
    private const ALLOW_FILES = ['index.php', 'process.php', 'brand.php', 'control.php'];
    private const ALLOW_DIRS = ['lib/', 'assets/'];
    private const INFO = self::ROOT . '/BUILD_INFO.json';
    private const BACKUP = Store::DIR . '/backup/last';
    private const LOCK = Store::DIR . '/update.lock';

    public function __construct(private string $repo, private string $branch, private string $token)
    {
        if (!preg_match('~^[A-Za-z0-9-]+/[A-Za-z0-9._-]+$~', $repo) || str_contains($repo, '..')) {
            throw new RuntimeException('Repository must look like owner/name.');
        }
        if (!preg_match('~^[\w./-]+$~', $branch) || str_contains($branch, '..')) {
            throw new RuntimeException('Branch name is not valid.');
        }
    }

    public static function installed(): array
    {
        $info = is_file(self::INFO) ? json_decode((string) file_get_contents(self::INFO), true) : null;
        return is_array($info) ? $info : [];
    }

    public static function hasBackup(): bool
    {
        return is_dir(self::BACKUP);
    }

    /** Latest commit on the tracked branch, compared with the installed one. */
    public function check(): array
    {
        $c = json_decode($this->request('https://api.github.com/repos/' . $this->repo . '/commits/' . rawurlencode($this->branch)), true);
        if (!is_array($c) || !isset($c['sha'])) {
            throw new RuntimeException('GitHub did not return a commit.');
        }
        $current = (string) (self::installed()['sha'] ?? '');
        return [
            'sha' => $c['sha'],
            'message' => strtok((string) ($c['commit']['message'] ?? ''), "\n"),
            'date' => (string) ($c['commit']['committer']['date'] ?? ''),
            'current' => $current,
            'available' => $current !== $c['sha'],
        ];
    }

    public function apply(string $sha): void
    {
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new RuntimeException('Check for updates first.');
        }
        $this->locked(function () use ($sha): void {
            $tmp = Store::DIR . '/tmp/update-' . bin2hex(random_bytes(8)) . '.zip';
            if (!is_dir(dirname($tmp))) {
                mkdir(dirname($tmp), 0700, true);
            }
            try {
                $this->request('https://api.github.com/repos/' . $this->repo . '/zipball/' . $sha, $tmp);
                $files = $this->readZip($tmp);
            } finally {
                @unlink($tmp);
            }
            if (!isset($files['index.php'])) {
                throw new RuntimeException('The download does not look like this app.');
            }
            $this->backup();
            foreach ($files as $rel => $data) {
                self::write(self::ROOT . '/' . $rel, $data);
            }
            self::write(self::INFO, json_encode([
                'sha' => $sha,
                'repo' => $this->repo,
                'branch' => $this->branch,
                'applied_at' => gmdate('c'),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            self::resetOpcache();
        });
    }

    /** Puts back the files saved before the last update. */
    public function restore(): void
    {
        if (!self::hasBackup()) {
            throw new RuntimeException('There is no previous version to restore.');
        }
        $this->locked(function (): void {
            $base = realpath(self::BACKUP);
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                    self::write(self::ROOT . '/' . $rel, (string) file_get_contents($file->getPathname()));
                }
            }
            if (!is_file(self::BACKUP . '/BUILD_INFO.json')) {
                @unlink(self::INFO);
            }
            self::resetOpcache();
        });
    }

    private function locked(callable $fn): void
    {
        if (!is_dir(Store::DIR)) {
            mkdir(Store::DIR, 0700, true);
        }
        $lock = fopen(self::LOCK, 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            throw new RuntimeException('Another update is already running.');
        }
        try {
            $fn();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /** Allowed files from the zip, keyed by app-relative path. Nothing is written until the whole zip reads cleanly. */
    private function readZip(string $path): array
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('The download is not a valid zip.');
        }
        try {
            $files = [];
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = (string) $zip->getNameIndex($i);
                $rel = substr($name, strpos($name, '/') + 1);
                if ($rel === '' || str_ends_with($rel, '/') || !self::allowed($rel)) {
                    continue;
                }
                $data = $zip->getFromIndex($i);
                if ($data === false) {
                    throw new RuntimeException('Could not read ' . $rel . ' from the download.');
                }
                $files[$rel] = $data;
            }
            return $files;
        } finally {
            $zip->close();
        }
    }

    private static function allowed(string $rel): bool
    {
        if (preg_match('~(^|/)\.\.(/|$)|^/|\\\\|:|\x00~', $rel)) {
            return false;
        }
        if (in_array($rel, self::ALLOW_FILES, true)) {
            return true;
        }
        foreach (self::ALLOW_DIRS as $dir) {
            if (str_starts_with($rel, $dir)) {
                return true;
            }
        }
        return false;
    }

    private function backup(): void
    {
        self::removeTree(self::BACKUP);
        $keep = array_filter([...self::ALLOW_FILES, 'BUILD_INFO.json'], static fn ($f) => is_file(self::ROOT . '/' . $f));
        foreach (self::ALLOW_DIRS as $dir) {
            $base = realpath(self::ROOT . '/' . $dir);
            if ($base === false) {
                continue;
            }
            $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS));
            foreach ($it as $file) {
                if ($file->isFile()) {
                    $keep[] = $dir . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
                }
            }
        }
        foreach ($keep as $rel) {
            self::write(self::BACKUP . '/' . $rel, (string) file_get_contents(self::ROOT . '/' . $rel));
        }
    }

    private static function write(string $path, string $data): void
    {
        $dir = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new RuntimeException('Could not create ' . $dir);
        }
        $tmp = $path . '.new';
        if (file_put_contents($tmp, $data, LOCK_EX) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not write ' . basename($path) . '. Check folder permissions.');
        }
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
        rmdir($dir);
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
    }

    /** GET a GitHub URL; with $saveTo the body is streamed to that file (the zipball can be hundreds of MB). */
    private function request(string $url, ?string $saveTo = null): string
    {
        $headers = ['Accept: application/vnd.github+json', 'X-GitHub-Api-Version: 2022-11-28', 'User-Agent: BGR-updater'];
        if ($this->token !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->token;
        }
        $out = $saveTo === null ? null : fopen($saveTo, 'wb');
        try {
            if (function_exists('curl_init')) {
                $c = curl_init($url);
                curl_setopt_array($c, [
                    CURLOPT_FOLLOWLOCATION => true,
                    CURLOPT_MAXREDIRS => 5,
                    CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
                    CURLOPT_CONNECTTIMEOUT => 15,
                    CURLOPT_TIMEOUT => 900,
                    CURLOPT_HTTPHEADER => $headers,
                ] + ($out ? [CURLOPT_FILE => $out] : [CURLOPT_RETURNTRANSFER => true]));
                $body = curl_exec($c);
                $code = (int) curl_getinfo($c, CURLINFO_HTTP_CODE);
                $error = curl_error($c);
            } else {
                $in = @fopen($url, 'rb', false, stream_context_create(['http' => [
                    'header' => implode("\r\n", $headers),
                    'timeout' => 900,
                    'ignore_errors' => true,
                ]]));
                $code = 0;
                foreach ($in ? stream_get_meta_data($in)['wrapper_data'] : [] as $line) {
                    if (preg_match('~^HTTP/\S+ (\d{3})~', (string) $line, $m)) {
                        $code = (int) $m[1];
                    }
                }
                $body = !$in ? false : ($out ? stream_copy_to_stream($in, $out) !== false : stream_get_contents($in));
                $error = $in ? '' : 'request failed';
                if ($in) {
                    fclose($in);
                }
            }
        } finally {
            if ($out) {
                fclose($out);
            }
        }
        if ($body === false || $code === 0) {
            throw new RuntimeException('Could not reach GitHub: ' . $error);
        }
        if ($code === 401 || $code === 403) {
            throw new RuntimeException('GitHub refused the request (' . $code . '). Check the token and its repository access.');
        }
        if ($code === 404) {
            throw new RuntimeException('Repository or branch not found. Private repositories need a token.');
        }
        if ($code >= 400) {
            throw new RuntimeException('GitHub returned HTTP ' . $code . '.');
        }
        return $saveTo === null ? (string) $body : '';
    }
}
