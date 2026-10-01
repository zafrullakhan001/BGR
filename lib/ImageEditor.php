<?php
declare(strict_types=1);

final class ImageEditor
{
    private GdImage $img;

    public function __construct(string $path)
    {
        $img = imagecreatefromstring((string) file_get_contents($path));
        if (!$img) {
            throw new RuntimeException('The picture could not be read.');
        }
        imagepalettetotruecolor($img);
        $this->img = $img;
        $this->keepAlpha();
        $this->applyExifOrientation($path);
    }

    /** Percentages match the CSS brightness() and contrast() preview in the browser. */
    public function adjust(int $brightness, int $contrast): self
    {
        if ($brightness !== 100) {
            $b = $brightness / 100;
            imageconvolution($this->img, [[0, 0, 0], [0, $b, 0], [0, 0, 0]], 1, 0);
        }
        if ($contrast !== 100) {
            // GD scales contrast by ((100 - v) / 100)^2, so solve for v from the CSS factor.
            $v = (int) round(100 - 100 * sqrt($contrast / 100));
            imagefilter($this->img, IMG_FILTER_CONTRAST, max(-100, min(100, $v)));
        }
        return $this;
    }

    public function rotate(int $degreesCounterClockwise): self
    {
        $clear = imagecolorallocatealpha($this->img, 0, 0, 0, 127);
        $this->img = imagerotate($this->img, $degreesCounterClockwise, $clear);
        $this->keepAlpha();
        return $this;
    }

    /** 0-100, same kernel as the feConvolveMatrix preview in the browser. */
    public function sharpen(int $amount): self
    {
        if ($amount > 0) {
            $a = $amount / 100;
            imageconvolution($this->img, [[0, -$a, 0], [-$a, 1 + 4 * $a, -$a], [0, -$a, 0]], 1, 0);
        }
        return $this;
    }

    public function resize(int $width): self
    {
        if ($width > 0 && $width !== imagesx($this->img)) {
            $this->img = imagescale($this->img, $width, -1, IMG_BICUBIC);
            $this->keepAlpha();
        }
        return $this;
    }

    public function flip(string $direction): self
    {
        imageflip($this->img, $direction === 'horizontal' ? IMG_FLIP_HORIZONTAL : IMG_FLIP_VERTICAL);
        return $this;
    }

    /** $x, $y, $w, $h in pixels; values are clamped to the picture. */
    public function crop(int $x, int $y, int $w, int $h): self
    {
        $bw = imagesx($this->img);
        $bh = imagesy($this->img);
        $x = max(0, min($x, $bw - 1));
        $y = max(0, min($y, $bh - 1));
        $w = max(1, min($w, $bw - $x));
        $h = max(1, min($h, $bh - $y));
        $out = imagecrop($this->img, ['x' => $x, 'y' => $y, 'width' => $w, 'height' => $h]);
        if ($out !== false) {
            $this->img = $out;
            $this->keepAlpha();
        }
        return $this;
    }

    /** Crops away a uniform border (scans, screenshots, cutouts with empty margins). */
    public function trim(int $tolerance = 16): self
    {
        $w = imagesx($this->img);
        $h = imagesy($this->img);
        if ($w < 3 || $h < 3) {
            return $this;
        }
        $bg = imagecolorat($this->img, 0, 0);
        $bg = [($bg >> 16) & 0xFF, ($bg >> 8) & 0xFF, $bg & 0xFF];
        $step = max(1, intdiv(max($w, $h), 400));
        $top = 0;
        while ($top < $h - 1 && $this->rowIsBackground($top, $w, $step, $bg, $tolerance)) {
            $top++;
        }
        $bottom = $h - 1;
        while ($bottom > $top && $this->rowIsBackground($bottom, $w, $step, $bg, $tolerance)) {
            $bottom--;
        }
        $left = 0;
        while ($left < $w - 1 && $this->colIsBackground($left, $h, $step, $bg, $tolerance)) {
            $left++;
        }
        $right = $w - 1;
        while ($right > $left && $this->colIsBackground($right, $h, $step, $bg, $tolerance)) {
            $right--;
        }
        if ($left > 0 || $top > 0 || $right < $w - 1 || $bottom < $h - 1) {
            $this->crop($left, $top, $right - $left + 1, $bottom - $top + 1);
        }
        return $this;
    }

    public function grayscale(): self
    {
        imagefilter($this->img, IMG_FILTER_GRAYSCALE);
        return $this;
    }

    public function invert(): self
    {
        imagefilter($this->img, IMG_FILTER_NEGATE);
        return $this;
    }

    /** Grey first, then a warm colour wash, matching the CSS sepia() look. */
    public function sepia(): self
    {
        imagefilter($this->img, IMG_FILTER_GRAYSCALE);
        imagefilter($this->img, IMG_FILTER_COLORIZE, 40, 18, -18);
        return $this;
    }

    public function blur(int $amount): self
    {
        if ($amount > 0) {
            for ($i = 0; $i < $amount; $i++) {
                imagefilter($this->img, IMG_FILTER_GAUSSIAN_BLUR);
            }
        }
        return $this;
    }

    /** Grayscale / sepia / invert / blur, all native GD, applied in one predictable order. */
    public function tone(bool $grayscale, bool $sepia, bool $invert, int $blur): self
    {
        return ($grayscale ? $this->grayscale() : $this)
            ->then($sepia ? 'sepia' : null)
            ->then($invert ? 'invert' : null)
            ->blur($blur);
    }

    private function then(?string $method): self
    {
        return $method === null ? $this : $this->{$method}();
    }

    /** Lifts a scan or photo of a plan so the paper is white and the lines stay dark. */
    public function cleanDrawing(): self
    {
        $w = imagesx($this->img);
        $h = imagesy($this->img);
        $sample = imagescale($this->img, min($w, 200), -1, IMG_BILINEAR_FIXED);
        if (!$sample) {
            return $this;
        }
        $levels = [];
        $sw = imagesx($sample);
        $sh = imagesy($sample);
        for ($y = 0; $y < $sh; $y++) {
            for ($x = 0; $x < $sw; $x++) {
                $levels[] = $this->luma(imagecolorat($sample, $x, $y));
            }
        }
        unset($sample);
        sort($levels);
        $last = count($levels) - 1;
        $black = $levels[(int) round($last * 0.02)];
        $white = $levels[(int) round($last * 0.92)];
        if ($white - $black < 12) {
            return $this;
        }

        $this->keepAlpha();
        $span = $white - $black;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($this->img, $x, $y);
                if ((($c >> 24) & 0x7F) > 100) {
                    continue;
                }
                $v = intdiv(($this->luma($c) - $black) * 255, $span);
                $v = max(0, min(255, $v));
                if ($v > 245) {
                    $v = 255;
                } elseif ($v < 10) {
                    $v = 0;
                }
                imagesetpixel($this->img, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }
        return $this;
    }

    /** Drops the colour that dominates the edges. Tolerance is 0–100. */
    public function removeEdgeBackground(int $tolerance = 24): self
    {
        $hex = $this->dominantEdgeColor();
        return $hex === null ? $this : $this->removeColor($hex, $tolerance, true);
    }

    /** Makes matching pixels transparent. $edgesOnly keeps enclosed areas of that colour. */
    public function removeColor(string $hex, int $tolerance, bool $edgesOnly): self
    {
        $rgb = sscanf($hex, '#%02x%02x%02x');
        if (!is_array($rgb) || count($rgb) !== 3) {
            throw new RuntimeException('The colour is not valid.');
        }
        [$kr, $kg, $kb] = $rgb;
        $maxDist2 = (int) round(($tolerance / 100) ** 2 * (255 ** 2 * 3));
        $w = imagesx($this->img);
        $h = imagesy($this->img);
        $n = $w * $h;
        if ($w < 1 || $h < 1) {
            return $this;
        }

        // 0 = keep, 1 = colour match, 2 = match that touches the picture edge.
        $state = str_repeat("\0", $n);
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($this->nearKey(imagecolorat($this->img, $x, $y), $kr, $kg, $kb, $maxDist2)) {
                    $state[$row + $x] = "\1";
                }
            }
        }

        if ($edgesOnly) {
            $queue = [];
            $head = 0;
            $mark = static function (int $i) use (&$state, &$queue): void {
                if ($state[$i] === "\1") {
                    $state[$i] = "\2";
                    $queue[] = $i;
                }
            };
            for ($x = 0; $x < $w; $x++) {
                $mark($x);
                $mark(($h - 1) * $w + $x);
            }
            for ($y = 1; $y < $h - 1; $y++) {
                $mark($y * $w);
                $mark($y * $w + $w - 1);
            }
            while ($head < count($queue)) {
                if ($head >= 65536) {
                    $queue = array_slice($queue, $head);
                    $head = 0;
                }
                $i = $queue[$head++];
                $x = $i % $w;
                $y = intdiv($i, $w);
                if ($x > 0) {
                    $mark($i - 1);
                }
                if ($x + 1 < $w) {
                    $mark($i + 1);
                }
                if ($y > 0) {
                    $mark($i - $w);
                }
                if ($y + 1 < $h) {
                    $mark($i + $w);
                }
            }
        }

        $this->keepAlpha();
        $clear = imagecolorallocatealpha($this->img, 0, 0, 0, 127);
        $drop = $edgesOnly ? "\2" : "\1";
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($state[$row + $x] === $drop) {
                    imagesetpixel($this->img, $x, $y, $clear);
                }
            }
        }
        return $this;
    }

    public function image(): GdImage
    {
        return $this->img;
    }

    /** $maxBytes > 0 makes JPEG/WEBP search the highest quality that stays under the limit. */
    public function save(string $path, string $format, int $maxBytes = 0): void
    {
        $img = in_array($format, ['jpg', 'gif', 'bmp'], true) ? $this->flattened() : $this->img;
        if ($maxBytes > 0 && in_array($format, ['jpg', 'webp'], true)) {
            $quality = $format === 'jpg' ? 92 : 90;
            do {
                $format === 'jpg' ? imagejpeg($img, $path, $quality) : imagewebp($img, $path, $quality);
                $quality -= 7;
            } while ((int) @filesize($path) > $maxBytes && $quality >= 6);
            return;
        }
        $ok = match ($format) {
            'png' => imagepng($img, $path, 6),
            'jpg' => imagejpeg($img, $path, 92),
            'webp' => imagewebp($img, $path, 90),
            'gif' => imagegif($img, $path),
            'bmp' => imagebmp($img, $path),
            'avif' => imageavif($img, $path, 80),
        };
        if (!$ok) {
            throw new RuntimeException('The picture could not be saved.');
        }
    }

    /** True when every sampled pixel on row $y is the border colour (or transparent). */
    private function rowIsBackground(int $y, int $w, int $step, array $bg, int $tol): bool
    {
        for ($x = 0; $x < $w; $x += $step) {
            if (!$this->isBackground(imagecolorat($this->img, $x, $y), $bg, $tol)) {
                return false;
            }
        }
        return true;
    }

    private function colIsBackground(int $x, int $h, int $step, array $bg, int $tol): bool
    {
        for ($y = 0; $y < $h; $y += $step) {
            if (!$this->isBackground(imagecolorat($this->img, $x, $y), $bg, $tol)) {
                return false;
            }
        }
        return true;
    }

    private function isBackground(int $c, array $bg, int $tol): bool
    {
        if ((($c >> 24) & 0x7F) > 100) {
            return true; // nearly transparent
        }
        return abs((($c >> 16) & 0xFF) - $bg[0]) <= $tol
            && abs((($c >> 8) & 0xFF) - $bg[1]) <= $tol
            && abs(($c & 0xFF) - $bg[2]) <= $tol;
    }

    private function luma(int $c): int
    {
        return intdiv(299 * (($c >> 16) & 0xFF) + 587 * (($c >> 8) & 0xFF) + 114 * ($c & 0xFF), 1000);
    }

    /** Euclidean RGB distance, with already-transparent pixels counting as a match. */
    private function nearKey(int $c, int $kr, int $kg, int $kb, int $maxDist2): bool
    {
        if ((($c >> 24) & 0x7F) > 100) {
            return true;
        }
        $dr = (($c >> 16) & 0xFF) - $kr;
        $dg = (($c >> 8) & 0xFF) - $kg;
        $db = ($c & 0xFF) - $kb;
        return $dr * $dr + $dg * $dg + $db * $db <= $maxDist2;
    }

    /** Most common opaque colour along the edges, or null when the edges are clear. */
    private function dominantEdgeColor(): ?string
    {
        $w = imagesx($this->img);
        $h = imagesy($this->img);
        $buckets = [];
        $visit = function (int $x, int $y) use (&$buckets): void {
            $c = imagecolorat($this->img, $x, $y);
            if ((($c >> 24) & 0x7F) > 100) {
                return;
            }
            $r = ($c >> 16) & 0xFF;
            $g = ($c >> 8) & 0xFF;
            $b = $c & 0xFF;
            $k = ($r >> 4) << 8 | ($g >> 4) << 4 | ($b >> 4);
            $bucket = $buckets[$k] ?? ['n' => 0, 'r' => 0, 'g' => 0, 'b' => 0];
            $bucket['n']++;
            $bucket['r'] += $r;
            $bucket['g'] += $g;
            $bucket['b'] += $b;
            $buckets[$k] = $bucket;
        };
        for ($x = 0; $x < $w; $x++) {
            $visit($x, 0);
            if ($h > 1) {
                $visit($x, $h - 1);
            }
        }
        for ($y = 1; $y < $h - 1; $y++) {
            $visit(0, $y);
            if ($w > 1) {
                $visit($w - 1, $y);
            }
        }
        if ($buckets === []) {
            return null;
        }
        $top = $buckets[array_key_first($buckets)];
        foreach ($buckets as $bucket) {
            if ($bucket['n'] > $top['n']) {
                $top = $bucket;
            }
        }
        return sprintf('#%02x%02x%02x', intdiv($top['r'], $top['n']), intdiv($top['g'], $top['n']), intdiv($top['b'], $top['n']));
    }

    private function flattened(): GdImage
    {
        $w = imagesx($this->img);
        $h = imagesy($this->img);
        $out = imagecreatetruecolor($w, $h);
        imagefill($out, 0, 0, imagecolorallocate($out, 255, 255, 255));
        imagecopy($out, $this->img, 0, 0, 0, 0, $w, $h);
        return $out;
    }

    private function keepAlpha(): void
    {
        imagealphablending($this->img, false);
        imagesavealpha($this->img, true);
    }

    /** Phone photos store rotation in EXIF; browsers honour it, GD does not. */
    private function applyExifOrientation(string $path): void
    {
        $exif = @exif_read_data($path);
        $angle = match ((int) ($exif['Orientation'] ?? 1)) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };
        if ($angle !== 0) {
            $this->rotate($angle);
        }
    }
}
