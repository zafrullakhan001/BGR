<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use Intervention\Image\ImageManager;
use Intervention\Image\Interfaces\ImageInterface;

final class ImageEditor
{
    private ImageInterface $img;

    public function __construct(string $path)
    {
        try {
            // One frame only, same as the old GD load. Phone rotation comes from EXIF automatically.
            $this->img = ImageManager::gd(decodeAnimation: false)->read($path);
        } catch (Throwable) {
            throw new RuntimeException('The picture could not be read.');
        }
    }

    /** Percentages match the CSS brightness() and contrast() preview in the browser. */
    public function adjust(int $brightness, int $contrast): self
    {
        $img = $this->gd();
        if ($brightness !== 100) {
            $b = $brightness / 100;
            imageconvolution($img, [[0, 0, 0], [0, $b, 0], [0, 0, 0]], 1, 0);
        }
        if ($contrast !== 100) {
            // GD scales contrast by ((100 - v) / 100)^2, so solve for v from the CSS factor.
            $v = (int) round(100 - 100 * sqrt($contrast / 100));
            imagefilter($img, IMG_FILTER_CONTRAST, max(-100, min(100, $v)));
        }
        return $this;
    }

    public function rotate(int $degreesCounterClockwise): self
    {
        $this->img->rotate($degreesCounterClockwise, 'transparent');
        return $this;
    }

    /** 0-100, same kernel as the feConvolveMatrix preview in the browser. */
    public function sharpen(int $amount): self
    {
        if ($amount > 0) {
            $a = $amount / 100;
            imageconvolution($this->gd(), [[0, -$a, 0], [-$a, 1 + 4 * $a, -$a], [0, -$a, 0]], 1, 0);
        }
        return $this;
    }

    public function resize(int $width): self
    {
        if ($width > 0 && $width !== $this->img->width()) {
            $this->img->scale(width: $width);
        }
        return $this;
    }

    public function flip(string $direction): self
    {
        $direction === 'horizontal' ? $this->img->flop() : $this->img->flip();
        return $this;
    }

    /** $x, $y, $w, $h in pixels; values are clamped to the picture. */
    public function crop(int $x, int $y, int $w, int $h): self
    {
        $bw = $this->img->width();
        $bh = $this->img->height();
        $x = max(0, min($x, $bw - 1));
        $y = max(0, min($y, $bh - 1));
        $w = max(1, min($w, $bw - $x));
        $h = max(1, min($h, $bh - $y));
        $this->img->crop($w, $h, $x, $y, 'transparent');
        return $this;
    }

    /** Crops away a uniform border (scans, screenshots, cutouts with empty margins). */
    public function trim(int $tolerance = 16): self
    {
        $img = $this->gd();
        $w = imagesx($img);
        $h = imagesy($img);
        if ($w < 3 || $h < 3) {
            return $this;
        }
        $bg = imagecolorat($img, 0, 0);
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
        $this->img->greyscale();
        return $this;
    }

    public function invert(): self
    {
        $this->img->invert();
        return $this;
    }

    /** Grey first, then a warm colour wash, matching the CSS sepia() look. */
    public function sepia(): self
    {
        $img = $this->gd();
        imagefilter($img, IMG_FILTER_GRAYSCALE);
        imagefilter($img, IMG_FILTER_COLORIZE, 40, 18, -18);
        return $this;
    }

    public function blur(int $amount): self
    {
        if ($amount > 0) {
            $this->img->blur($amount);
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
        $img = $this->gd();
        $w = imagesx($img);
        $h = imagesy($img);
        $sample = imagescale($img, min($w, 200), -1, IMG_BILINEAR_FIXED);
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

        $span = $white - $black;
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($img, $x, $y);
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
                imagesetpixel($img, $x, $y, ($v << 16) | ($v << 8) | $v);
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
        $img = $this->gd();
        $w = imagesx($img);
        $h = imagesy($img);
        $n = $w * $h;
        if ($w < 1 || $h < 1) {
            return $this;
        }

        // 0 = keep, 1 = colour match, 2 = match that touches the picture edge.
        $state = str_repeat("\0", $n);
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($this->nearKey(imagecolorat($img, $x, $y), $kr, $kg, $kb, $maxDist2)) {
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

        $clear = imagecolorallocatealpha($img, 0, 0, 0, 127);
        $drop = $edgesOnly ? "\2" : "\1";
        for ($y = 0; $y < $h; $y++) {
            $row = $y * $w;
            for ($x = 0; $x < $w; $x++) {
                if ($state[$row + $x] === $drop) {
                    imagesetpixel($img, $x, $y, $clear);
                }
            }
        }
        return $this;
    }

    public function image(): GdImage
    {
        return $this->gd();
    }

    /** $maxBytes > 0 makes JPEG/WEBP search the highest quality that stays under the limit. */
    public function save(string $path, string $format, int $maxBytes = 0): void
    {
        $image = in_array($format, ['gif', 'bmp'], true)
            ? (clone $this->img)->blendTransparency('ffffff')
            : $this->img;
        if ($maxBytes > 0 && in_array($format, ['jpg', 'webp'], true)) {
            $quality = $format === 'jpg' ? 92 : 90;
            do {
                ($format === 'jpg' ? $image->toJpeg($quality) : $image->toWebp($quality))->save($path);
                $quality -= 7;
            } while ((int) @filesize($path) > $maxBytes && $quality >= 6);
            return;
        }
        $encoded = match ($format) {
            'png' => $image->toPng(),
            'jpg' => $image->toJpeg(92),
            'webp' => $image->toWebp(90),
            'gif' => $image->toGif(),
            'bmp' => $image->toBitmap(),
            'avif' => $image->toAvif(80),
            default => throw new RuntimeException('The picture could not be saved.'),
        };
        $encoded->save($path);
    }

    /** True when every sampled pixel on row $y is the border colour (or transparent). */
    private function rowIsBackground(int $y, int $w, int $step, array $bg, int $tol): bool
    {
        $img = $this->gd();
        for ($x = 0; $x < $w; $x += $step) {
            if (!$this->isBackground(imagecolorat($img, $x, $y), $bg, $tol)) {
                return false;
            }
        }
        return true;
    }

    private function colIsBackground(int $x, int $h, int $step, array $bg, int $tol): bool
    {
        $img = $this->gd();
        for ($y = 0; $y < $h; $y += $step) {
            if (!$this->isBackground(imagecolorat($img, $x, $y), $bg, $tol)) {
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
        $img = $this->gd();
        $w = imagesx($img);
        $h = imagesy($img);
        $buckets = [];
        $visit = function (int $x, int $y) use (&$buckets, $img): void {
            $c = imagecolorat($img, $x, $y);
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

    /** The GD bitmap Intervention is editing, with alpha left intact for pixel writes. */
    private function gd(): GdImage
    {
        $native = $this->img->core()->native();
        if (!$native instanceof GdImage) {
            throw new RuntimeException('The picture could not be read.');
        }
        imagealphablending($native, false);
        imagesavealpha($native, true);
        return $native;
    }
}
