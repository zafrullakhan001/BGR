<?php
declare(strict_types=1);

require __DIR__ . '/lib/Store.php';
require __DIR__ . '/lib/Updater.php';
require __DIR__ . '/lib/session.php';

const MAX_FAILS = 5;
const LOCK_SECONDS = 60;
const IDLE_SECONDS = 1800;
const LOGO_TYPES = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp', 'image/gif' => 'gif'];
const LOGO_DIR = Store::DIR . '/branding';

app_session();
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; frame-ancestors 'none'; form-action 'self'");

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function done(string $type, string $message): never
{
    $_SESSION['flash'] = [$type, $message];
    header('Location: control.php', true, 303);
    exit;
}

function updater(): Updater
{
    $token = Store::get('github_token');
    return new Updater(
        Store::get('github_repo', 'zafrullakhan001/BGR'),
        Store::get('github_branch', 'main'),
        $token === '' ? '' : Store::decrypt($token),
    );
}

function checkNewPassword(string $new, string $confirm): void
{
    if (strlen($new) < 8) {
        done('error', 'Use at least 8 characters for the password.');
    }
    if (!hash_equals($new, $confirm)) {
        done('error', 'The two passwords do not match.');
    }
}

function saveLogo(array $file): void
{
    if ($file['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($file['tmp_name'])) {
        done('error', 'The logo did not upload. Try again.');
    }
    if ($file['size'] > 2 * 1024 * 1024) {
        done('error', 'The logo must be 2 MB or smaller.');
    }
    $ext = LOGO_TYPES[(new finfo(FILEINFO_MIME_TYPE))->file($file['tmp_name'])] ?? null;
    if ($ext === null || !@getimagesize($file['tmp_name'])) {
        done('error', 'The logo must be a PNG, JPG, WEBP or GIF picture.');
    }
    if (!is_dir(LOGO_DIR)) {
        mkdir(LOGO_DIR, 0700, true);
    }
    array_map('unlink', glob(LOGO_DIR . '/logo.*') ?: []);
    move_uploaded_file($file['tmp_name'], LOGO_DIR . '/logo.' . $ext);
    Store::set('logo_file', 'logo.' . $ext);
}

$hash = Store::get('admin_password_hash');
$authed = !empty($_SESSION['control_ok']) && time() - (int) ($_SESSION['control_seen'] ?? 0) < IDLE_SECONDS;
if ($authed) {
    $_SESSION['control_seen'] = time();
} else {
    unset($_SESSION['control_ok']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        done('error', 'Your session expired. Try again.');
    }
    $action = (string) ($_POST['action'] ?? '');
    $post = static fn (string $k): string => trim((string) ($_POST[$k] ?? ''));

    if ($hash === '' && $action === 'setup') {
        checkNewPassword((string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
        Store::set('admin_password_hash', password_hash((string) $_POST['password'], PASSWORD_DEFAULT));
        session_regenerate_id(true);
        $_SESSION['control_ok'] = true;
        $_SESSION['control_seen'] = time();
        done('ok', 'Password set. You are signed in.');
    }

    if ($hash !== '' && $action === 'login') {
        $wait = (int) Store::get('login_locked_until', '0') - time();
        if ($wait > 0) {
            done('error', "Too many attempts. Try again in {$wait} seconds.");
        }
        $password = (string) ($_POST['password'] ?? '');
        if (!password_verify($password, $hash)) {
            $fails = (int) Store::get('login_fails', '0') + 1;
            if ($fails >= MAX_FAILS) {
                Store::set('login_locked_until', (string) (time() + LOCK_SECONDS));
                $fails = 0;
            }
            Store::set('login_fails', (string) $fails);
            done('error', 'Wrong password.');
        }
        Store::set('login_fails', '0');
        if (password_needs_rehash($hash, PASSWORD_DEFAULT)) {
            Store::set('admin_password_hash', password_hash($password, PASSWORD_DEFAULT));
        }
        session_regenerate_id(true);
        $_SESSION['control_ok'] = true;
        $_SESSION['control_seen'] = time();
        done('ok', 'Signed in.');
    }

    if (!$authed) {
        done('error', 'Sign in first.');
    }

    try {
        switch ($action) {
            case 'logout':
                unset($_SESSION['control_ok'], $_SESSION['control_seen'], $_SESSION['update_check']);
                session_regenerate_id(true);
                done('ok', 'Signed out.');

            case 'password':
                if (!password_verify((string) ($_POST['current'] ?? ''), $hash)) {
                    done('error', 'The current password is wrong.');
                }
                checkNewPassword((string) ($_POST['password'] ?? ''), (string) ($_POST['confirm'] ?? ''));
                Store::set('admin_password_hash', password_hash((string) $_POST['password'], PASSWORD_DEFAULT));
                session_regenerate_id(true);
                done('ok', 'Password changed.');

            case 'brand':
                $name = $post('app_name');
                $accent = $post('accent');
                if ($name === '' || mb_strlen($name) > 60) {
                    done('error', 'App name must be 1 to 60 characters.');
                }
                if (mb_strlen($post('tagline')) > 140) {
                    done('error', 'Tagline must be 140 characters or fewer.');
                }
                if ($accent !== '' && !preg_match('/^#[0-9a-f]{6}$/i', $accent)) {
                    done('error', 'Accent must be a colour like #E2477A.');
                }
                Store::set('app_name', $name);
                Store::set('tagline', $post('tagline'));
                isset($_POST['reset_accent']) ? Store::delete('accent') : Store::set('accent', strtolower($accent));
                foreach (array_keys(Store::SIZES) as $key) {
                    Store::set($key, (string) Store::clampSize($key, $_POST[$key] ?? null));
                }
                Store::set('logo_plate', isset($_POST['logo_plate']) ? '1' : '0');
                if (isset($_POST['remove_logo'])) {
                    array_map('unlink', glob(LOGO_DIR . '/logo.*') ?: []);
                    Store::delete('logo_file');
                } elseif (($_FILES['logo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                    saveLogo($_FILES['logo']);
                }
                done('ok', 'Branding saved.');

            case 'github':
                new Updater($post('repo'), $post('branch') ?: 'main', '');
                Store::set('github_repo', $post('repo'));
                Store::set('github_branch', $post('branch') ?: 'main');
                if (isset($_POST['clear_token'])) {
                    Store::delete('github_token');
                } elseif ($post('token') !== '') {
                    Store::set('github_token', Store::encrypt($post('token')));
                }
                unset($_SESSION['update_check']);
                done('ok', 'GitHub settings saved.');

            case 'check':
                $_SESSION['update_check'] = updater()->check();
                done('ok', $_SESSION['update_check']['available'] ? 'An update is available.' : 'You are on the latest version.');

            case 'apply':
                set_time_limit(900);
                updater()->apply((string) ($_SESSION['update_check']['sha'] ?? ''));
                unset($_SESSION['update_check']);
                done('ok', 'Update installed. The previous files are saved for restore.');

            case 'restore':
                updater()->restore();
                unset($_SESSION['update_check']);
                done('ok', 'Previous version restored.');
        }
    } catch (RuntimeException $e) {
        done('error', $e->getMessage());
    } catch (Throwable $e) {
        error_log('BGR control.php: ' . $e);
        done('error', 'Something went wrong. Details are in the PHP error log.');
    }
    done('error', 'Unknown action.');
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = h($_SESSION['csrf']);
$brand = Store::brand();
$v = static fn (string $f): int => filemtime(__DIR__ . '/assets/' . $f);
$field = static fn (): string => '<input type="hidden" name="csrf" value="' . $csrf . '">';
$sizeSlider = static function (string $key, string $label, string $var) use ($brand): string {
    [$min, $max] = Store::SIZES[$key];
    return sprintf(
        '<label class="slider brand-size"><span>%1$s <output>%2$d px</output></span>'
        . '<input type="range" name="%3$s" min="%4$d" max="%5$d" value="%2$d" data-var="%6$s" aria-label="%1$s"></label>',
        h($label), $brand[$key], $key, $min, $max, $var
    );
};
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    <title>Control panel · <?= h($brand['name']) ?></title>
    <script src="assets/theme.js?v=<?= $v('theme.js') ?>"></script>
    <script src="assets/control.js?v=<?= $v('control.js') ?>" defer></script>
    <link rel="stylesheet" href="assets/fonts/fonts.css?v=<?= $v('fonts/fonts.css') ?>">
    <link rel="stylesheet" href="assets/app.css?v=<?= $v('app.css') ?>">
    <?php if ($brand['accent'] !== ''): ?><style>:root { --magenta: <?= h($brand['accent']) ?>; }</style><?php endif ?>
    <style>
        .panel { max-width: 640px; margin: 0 auto; padding: 24px 0 48px; }
        .panel .cell { margin: 0 12px 14px; }
        .panel .title { padding-inline: 12px; }
        .flash { margin: 0 12px 14px; padding: 10px 14px; border-radius: 12px; border: 1px solid var(--line); background: var(--card); }
        .flash.error { color: var(--error); }
        .panel .brand-preview {
            width: 100%;
            max-width: 360px;
            padding: 22px 20px 18px;
            background: var(--panel);
            border: 1px dashed var(--line);
            border-radius: 14px;
            overflow: hidden;
        }
        .slider.brand-size { --track: linear-gradient(90deg, var(--sky), var(--violet)); }
        .row form .btn { width: 100%; }
        .check { display: flex; gap: 8px; align-items: center; font-size: 0.86rem; color: var(--muted); }
        code { font-size: 0.82rem; }
        .meta a { color: var(--sky); }
    </style>
</head>
<body>
<svg width="0" height="0" style="position:absolute" aria-hidden="true">
    <defs>
        <symbol id="i-sun" viewBox="0 0 24 24"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.9 4.9l1.4 1.4M17.7 17.7l1.4 1.4M2 12h2M20 12h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/></symbol>
        <symbol id="i-moon" viewBox="0 0 24 24"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8z"/></symbol>
    </defs>
</svg>
<main class="panel">
    <header class="title">
        <div>
            <h1>Control panel</h1>
            <p><a href="./" style="color: inherit">Back to <?= h($brand['name']) ?></a></p>
        </div>
        <button type="button" class="theme-toggle" id="theme" aria-label="Switch to day mode" title="Day / night">
            <svg class="moon"><use href="#i-moon"/></svg><svg class="sun"><use href="#i-sun"/></svg>
        </button>
    </header>

    <?php if ($flash): ?>
        <p class="flash <?= $flash[0] === 'error' ? 'error' : '' ?>" role="status"><?= h($flash[1]) ?></p>
    <?php endif ?>

    <?php if ($hash === ''): ?>
        <form class="cell" method="post">
            <h2>Set a control panel password</h2>
            <p class="meta">No password exists yet. Choose one now; you will need it to open this page.</p>
            <?= $field() ?><input type="hidden" name="action" value="setup">
            <label class="field"><span>New password (8+ characters)</span><input type="password" name="password" minlength="8" required autocomplete="new-password" autofocus></label>
            <label class="field"><span>Repeat password</span><input type="password" name="confirm" minlength="8" required autocomplete="new-password"></label>
            <button class="btn primary">Set password</button>
        </form>

    <?php elseif (!$authed): ?>
        <form class="cell" method="post">
            <h2>Sign in</h2>
            <?= $field() ?><input type="hidden" name="action" value="login">
            <label class="field"><span>Password</span><input type="password" name="password" required autocomplete="current-password" autofocus></label>
            <button class="btn primary">Sign in</button>
        </form>

    <?php else:
        $hasToken = Store::get('github_token') !== '';
        $installed = Updater::installed();
        $check = $_SESSION['update_check'] ?? null;
        ?>
        <form class="cell" method="post" enctype="multipart/form-data">
            <h2>Branding</h2>
            <?= $field() ?><input type="hidden" name="action" value="brand">
            <p class="meta">Preview at the app's sidebar width.</p>
            <div class="title brand-preview" id="brandPreview" aria-hidden="true"
                 style="--logo-h: <?= $brand['logo_size'] ?>px; --title-size: <?= $brand['title_size'] ?>px; --tagline-size: <?= $brand['tagline_size'] ?>px">
                <?php if ($brand['logo'] !== ''): ?>
                    <img class="brand-logo<?= $brand['logo_plate'] ? '' : ' plain' ?>" id="logoPreview"
                         src="brand.php?v=<?= (int) @filemtime(LOGO_DIR . '/' . $brand['logo']) ?>" alt="Current logo">
                <?php endif ?>
                <div class="brand-text">
                    <h1 id="namePreview"><?= h($brand['name']) ?></h1>
                    <p id="taglinePreview"<?= $brand['tagline'] === '' ? ' hidden' : '' ?>><?= h($brand['tagline']) ?></p>
                </div>
            </div>
            <label class="field"><span>App name</span><input name="app_name" id="appName" maxlength="60" required value="<?= h($brand['name']) ?>"></label>
            <?= $sizeSlider('title_size', 'App name size', '--title-size') ?>
            <label class="field"><span>Tagline</span><input name="tagline" id="tagline" maxlength="140" value="<?= h($brand['tagline']) ?>"></label>
            <?= $sizeSlider('tagline_size', 'Tagline size', '--tagline-size') ?>
            <div class="row">
                <label class="field color narrow"><span>Accent colour</span><input type="color" name="accent" value="<?= h($brand['accent'] ?: '#e2477a') ?>"></label>
                <label class="check"><input type="checkbox" name="reset_accent"> Reset to the default colours</label>
            </div>
            <label class="field"><span>Logo (PNG, JPG, WEBP or GIF, up to 2 MB)</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp,image/gif"></label>
            <?php if ($brand['logo'] !== ''): ?>
                <?= $sizeSlider('logo_size', 'Logo size', '--logo-h') ?>
                <label class="check"><input type="checkbox" name="logo_plate" id="logoPlate" <?= $brand['logo_plate'] ? 'checked' : '' ?>> White background behind the logo</label>
                <label class="check"><input type="checkbox" name="remove_logo"> Remove logo</label>
            <?php else: ?>
                <input type="hidden" name="logo_size" value="<?= $brand['logo_size'] ?>">
                <?php if ($brand['logo_plate']): ?><input type="hidden" name="logo_plate" value="1"><?php endif ?>
            <?php endif ?>
            <button class="btn primary">Save branding</button>
        </form>

        <form class="cell" method="post">
            <h2>GitHub</h2>
            <?php
            $repoValue = Store::get('github_repo', 'zafrullakhan001/BGR');
            $tokenUrl = 'https://github.com/settings/personal-access-tokens/new?' . http_build_query([
                'name' => 'BGR updates',
                'description' => 'Read repository contents so BGR can download updates',
                'target_name' => explode('/', $repoValue, 2)[0],
                'expires_in' => '366',
                'contents' => 'read',
            ], '', '&', PHP_QUERY_RFC3986);
            ?>
            <p class="meta">Updates download as a zip from GitHub. Git does not need to be installed. Private repositories need a <a id="githubToken" href="<?= h($tokenUrl) ?>" target="_blank" rel="noopener noreferrer">personal access token</a> with read access to contents. GitHub opens with that permission, this repository’s owner, and a one-year expiry filled in. Under repository access, select Only select repositories and pick this repository, then paste the token below.</p>
            <?= $field() ?><input type="hidden" name="action" value="github">
            <div class="row">
                <label class="field"><span>Repository (owner/name)</span><input name="repo" required value="<?= h($repoValue) ?>"></label>
                <label class="field narrow"><span>Branch</span><input name="branch" value="<?= h(Store::get('github_branch', 'main')) ?>"></label>
            </div>
            <label class="field">
                <span>Personal access token <?= $hasToken ? '(saved; leave blank to keep it)' : '(none saved)' ?></span>
                <input type="password" name="token" autocomplete="off" placeholder="<?= $hasToken ? '••••••••••••' : 'github_pat_… or ghp_…' ?>">
            </label>
            <?php if ($hasToken): ?><label class="check"><input type="checkbox" name="clear_token"> Remove the saved token</label><?php endif ?>
            <button class="btn">Save GitHub settings</button>
        </form>

        <section class="cell">
            <h2>Updates</h2>
            <p class="meta">
                Installed: <?= isset($installed['sha']) ? '<code>' . h(substr((string) $installed['sha'], 0, 7)) . '</code> from ' . h((string) ($installed['applied_at'] ?? '')) : 'unknown (no update applied yet)' ?>
            </p>
            <?php if ($check): ?>
                <p class="meta">
                    Latest on GitHub: <code><?= h(substr($check['sha'], 0, 7)) ?></code> <?= h($check['message']) ?>
                    <?= $check['date'] !== '' ? '(' . h($check['date']) . ')' : '' ?>
                </p>
            <?php endif ?>
            <div class="row">
                <form method="post"><?= $field() ?><input type="hidden" name="action" value="check"><button class="btn wide">Check for updates</button></form>
                <?php if ($check && $check['available']): ?>
                    <form method="post">
                        <?= $field() ?><input type="hidden" name="action" value="apply"><button class="btn primary wide">Install update</button>
                    </form>
                <?php endif ?>
                <?php if (Updater::hasBackup()): ?>
                    <form method="post"><?= $field() ?><input type="hidden" name="action" value="restore"><button class="btn wide">Restore previous</button></form>
                <?php endif ?>
            </div>
            <p class="meta">Your settings, logo and database in <code>storage/</code> are never replaced by an update.</p>
        </section>

        <form class="cell" method="post">
            <h2>Change password</h2>
            <?= $field() ?><input type="hidden" name="action" value="password">
            <label class="field"><span>Current password</span><input type="password" name="current" required autocomplete="current-password"></label>
            <label class="field"><span>New password (8+ characters)</span><input type="password" name="password" minlength="8" required autocomplete="new-password"></label>
            <label class="field"><span>Repeat new password</span><input type="password" name="confirm" minlength="8" required autocomplete="new-password"></label>
            <button class="btn">Change password</button>
        </form>

        <form class="cell" method="post">
            <?= $field() ?><input type="hidden" name="action" value="logout">
            <button class="btn">Sign out</button>
        </form>
    <?php endif ?>
</main>
</body>
</html>
