<?php
declare(strict_types=1);

/** Builds favicon, PWA, Android and iOS icon sets from one picture. */
final class IconPack
{
    public const PACKS = ['favicon', 'pwa', 'android', 'ios', 'macos', 'chat', 'appicons'];

    /** Android density buckets: legacy launcher size and adaptive layer size in px. */
    private const ANDROID = ['mdpi' => [48, 108], 'hdpi' => [72, 162], 'xhdpi' => [96, 216], 'xxhdpi' => [144, 324], 'xxxhdpi' => [192, 432]];

    private array $rgb;

    public function __construct(private GdImage $src, private string $bgHex, private float $padding, private string $shape = 'none', private array $meta = [])
    {
        $this->rgb = sscanf($bgHex, '#%02x%02x%02x');
        $this->meta += ['name' => 'My App', 'short' => 'App', 'desc' => '', 'theme' => $bgHex];
    }

    /** Square PNG with the picture fitted inside $scale of the canvas, minus the user's padding. */
    public function png(int $size, float $scale = 1.0, bool $fill = false, string $shape = 'none'): string
    {
        $canvas = imagecreatetruecolor($size, $size);
        imagealphablending($canvas, false);
        imagesavealpha($canvas, true);
        $bg = $fill
            ? imagecolorallocate($canvas, ...$this->rgb)
            : imagecolorallocatealpha($canvas, 0, 0, 0, 127);
        imagefilledrectangle($canvas, 0, 0, $size, $size, $bg);

        $box = $size * $scale * (1 - $this->padding);
        $w = imagesx($this->src);
        $h = imagesy($this->src);
        $ratio = min($box / $w, $box / $h);
        $dw = max(1, (int) round($w * $ratio));
        $dh = max(1, (int) round($h * $ratio));
        imagealphablending($canvas, true);
        imagecopyresampled($canvas, $this->src, intdiv($size - $dw, 2), intdiv($size - $dh, 2), 0, 0, $dw, $dh, $w, $h);

        if ($shape !== 'none') {
            $this->shapeMask($canvas, $size, $shape);
        }
        ob_start();
        imagepng($canvas, null, 9);
        return (string) ob_get_clean();
    }

    /** ICO holding PNG frames, which every current browser and Windows reads. */
    public function ico(array $sizes = [16, 32, 48]): string
    {
        $frames = array_map(fn (int $s): string => $this->png($s), $sizes);
        $offset = 6 + 16 * count($frames);
        $dir = pack('vvv', 0, 1, count($frames));
        foreach ($sizes as $i => $s) {
            $dim = $s >= 256 ? 0 : $s;
            $dir .= pack('CCCCvvVV', $dim, $dim, 0, 0, 1, 32, strlen($frames[$i]), $offset);
            $offset += strlen($frames[$i]);
        }
        return $dir . implode('', $frames);
    }

    public function zip(string $pack, string $path): void
    {
        $zip = new ZipArchive();
        if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The icon ZIP could not be created.');
        }
        foreach ($pack === 'appicons' ? ['favicon', 'pwa', 'android', 'ios', 'macos'] : [$pack] as $p) {
            $prefix = $pack === 'appicons' ? "$p/" : '';
            foreach ($this->files($p) as $name => $data) {
                $zip->addFromString($prefix . $name, $data);
            }
        }
        $zip->close();
    }

    /** @return array<string, string> file name => contents */
    private function files(string $pack): array
    {
        return match ($pack) {
            'favicon' => [
                'favicon.ico' => $this->ico(),
                'favicon-16x16.png' => $this->png(16),
                'favicon-32x32.png' => $this->png(32),
                'apple-touch-icon.png' => $this->png(180, 1.0, true),
                'android-chrome-192x192.png' => $this->png(192),
                'android-chrome-512x512.png' => $this->png(512),
                'site.webmanifest' => $this->manifest('/android-chrome-', 'x', false),
                'head-snippet.html' => implode("\n", [
                    '<link rel="icon" href="/favicon.ico" sizes="any">',
                    '<link rel="icon" type="image/png" sizes="32x32" href="/favicon-32x32.png">',
                    '<link rel="icon" type="image/png" sizes="16x16" href="/favicon-16x16.png">',
                    '<link rel="apple-touch-icon" href="/apple-touch-icon.png">',
                    '<link rel="manifest" href="/site.webmanifest">',
                    '',
                ]),
            ],
            'pwa' => [
                'icons/icon-192.png' => $this->png(192),
                'icons/icon-512.png' => $this->png(512),
                // Maskable icons keep the picture inside the 80% safe circle on a solid background.
                'icons/maskable-192.png' => $this->png(192, 0.8, true),
                'icons/maskable-512.png' => $this->png(512, 0.8, true),
                'icons/apple-touch-icon.png' => $this->png(180, 1.0, true),
                'manifest.webmanifest' => $this->manifest('/icons/icon-', '', true),
            ],
            'android' => $this->androidFiles(),
            'macos' => $this->macosFiles(),
            'chat' => [
                'slack-192.png' => $this->png(192, 1.0, true),
                'slack-512.png' => $this->png(512, 1.0, true),
                'discord-512.png' => $this->png(512, 1.0, true),
                'teams-192.png' => $this->png(192, 1.0, true),
                'teams-32.png' => $this->png(32, 1.0, true),
            ],
            'ios' => [
                'AppIcon.appiconset/AppIcon-1024.png' => $this->png(1024, 1.0, true),
                'AppIcon.appiconset/Contents.json' => json_encode([
                    'images' => [['filename' => 'AppIcon-1024.png', 'idiom' => 'universal', 'platform' => 'ios', 'size' => '1024x1024']],
                    'info' => ['author' => 'xcode', 'version' => 1],
                ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
            ],
        };
    }

    private function androidFiles(): array
    {
        $files = [];
        foreach (self::ANDROID as $density => [$legacy, $adaptive]) {
            $files["res/mipmap-$density/ic_launcher.png"] = $this->png($legacy, 1.0, true);
            $files["res/mipmap-$density/ic_launcher_round.png"] = $this->png($legacy, 1.0, true, 'circle');
            // Adaptive foreground: launchers crop to the centre 66dp of 108dp.
            $files["res/mipmap-$density/ic_launcher_foreground.png"] = $this->png($adaptive, 0.61);
        }
        $adaptiveXml = <<<XML
            <?xml version="1.0" encoding="utf-8"?>
            <adaptive-icon xmlns:android="http://schemas.android.com/apk/res/android">
                <background android:drawable="@color/ic_launcher_background"/>
                <foreground android:drawable="@mipmap/ic_launcher_foreground"/>
            </adaptive-icon>

            XML;
        $files['res/mipmap-anydpi-v26/ic_launcher.xml'] = $adaptiveXml;
        $files['res/mipmap-anydpi-v26/ic_launcher_round.xml'] = $adaptiveXml;
        $files['res/values/ic_launcher_background.xml'] = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n<resources>\n"
            . "    <color name=\"ic_launcher_background\">{$this->bgHex}</color>\n</resources>\n";
        $files['playstore-icon.png'] = $this->png(512, 1.0, true);
        return $files;
    }

    private function manifest(string $prefix, string $sizeSep, bool $maskable): string
    {
        $icons = [];
        foreach ([192, 512] as $s) {
            $label = $sizeSep === '' ? (string) $s : "{$s}{$sizeSep}{$s}";
            $icons[] = ['src' => "$prefix$label.png", 'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => 'any'];
            if ($maskable) {
                $icons[] = ['src' => "/icons/maskable-$s.png", 'sizes' => "{$s}x{$s}", 'type' => 'image/png', 'purpose' => 'maskable'];
            }
        }
        return json_encode([
            'name' => $this->meta['name'],
            'short_name' => $this->meta['short'],
            'description' => $this->meta['desc'],
            'icons' => $icons,
            'theme_color' => $this->meta['theme'],
            'background_color' => $this->meta['theme'],
            'display' => 'standalone',
            'start_url' => '/',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /** macOS AppIcon.iconset, ready for `iconutil -c icns`. */
    private function macosFiles(): array
    {
        $files = [];
        foreach ([[16, 1], [16, 2], [32, 1], [32, 2], [128, 1], [128, 2], [256, 1], [256, 2], [512, 1], [512, 2]] as [$base, $scale]) {
            $px = $base * $scale;
            $files["AppIcon.iconset/icon_{$base}x{$base}" . ($scale === 2 ? '@2x' : '') . '.png'] = $this->png($px, 1.0, true, $this->shape);
        }
        return $files;
    }

    /** Masks the canvas to a circle, rounded square or squircle with a one-pixel soft edge. */
    private function shapeMask(GdImage $img, int $size, string $shape): void
    {
        imagealphablending($img, false);
        $r = $size / 2;
        $radius = $size * 0.2237;
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $coverage = match ($shape) {
                    'circle' => max(0.0, min(1.0, $r - hypot($x + 0.5 - $r, $y + 0.5 - $r) + 0.5)),
                    'rounded' => $this->roundedCoverage($x, $y, $size, $radius),
                    'squircle' => $this->squircleCoverage($x, $y, $r),
                    default => 1.0,
                };
                if ($coverage >= 1.0) {
                    continue;
                }
                $c = imagecolorat($img, $x, $y);
                $alpha = 127 - (int) round((127 - (($c >> 24) & 0x7F)) * $coverage);
                imagesetpixel($img, $x, $y, ($alpha << 24) | ($c & 0xFFFFFF));
            }
        }
    }

    private function roundedCoverage(int $x, int $y, int $size, float $radius): float
    {
        $px = $x + 0.5;
        $py = $y + 0.5;
        $dx = max(0.0, $radius - $px, $px - ($size - $radius));
        $dy = max(0.0, $radius - $py, $py - ($size - $radius));
        return max(0.0, min(1.0, $radius - hypot($dx, $dy) + 0.5));
    }

    /** iOS-style superellipse |x|^5 + |y|^5 = 1. */
    private function squircleCoverage(int $x, int $y, float $r): float
    {
        $px = $x + 0.5;
        $py = $y + 0.5;
        $v = (abs($px - $r) ** 5 + abs($py - $r) ** 5) ** 0.2;
        return max(0.0, min(1.0, $r - $v + 0.5));
    }
}
