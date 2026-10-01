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
