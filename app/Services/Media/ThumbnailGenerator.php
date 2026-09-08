<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Process;

/**
 * Small JPEGs for grids and calendar chips.
 *
 * The calendar renders dozens of these at once, so it must never load original
 * media -- a month of 4MB exports is 120MB down the wire for a page of
 * thumbnails.
 *
 * GD rather than Imagick, because GD is what this PHP build actually has.
 */
class ThumbnailGenerator
{
    public function __construct(private readonly MediaProbe $probe = new MediaProbe) {}

    /**
     * Write a thumbnail next to the source and return its absolute path, or
     * null when one could not be made.
     *
     * A missing thumbnail is never fatal: it degrades a grid, it does not stop
     * a post publishing.
     */
    public function generate(string $absolutePath, string $destination, ProbeResult $media): ?string
    {
        $width = (int) config('gnext.media.thumbnail_width', 480);

        $made = $media->isVideo()
            ? $this->fromVideo($absolutePath, $destination, $width)
            : $this->fromImage($absolutePath, $destination, $width, $media);

        return $made ? $destination : null;
    }

    private function fromImage(string $source, string $destination, int $width, ProbeResult $media): bool
    {
        $image = $this->open($source, $media->mime);

        if ($image === null) {
            return false;
        }

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        if ($sourceWidth < 1 || $sourceHeight < 1) {
            imagedestroy($image);

            return false;
        }

        // Never upscale: a 200px source becomes a 200px thumbnail, not a blurry
        // 480px one.
        $targetWidth = min($width, $sourceWidth);
        $targetHeight = (int) max(1, round($sourceHeight * ($targetWidth / $sourceWidth)));

        $canvas = imagecreatetruecolor($targetWidth, $targetHeight);

        // Flatten transparency onto white rather than producing a black box.
        $white = imagecolorallocate($canvas, 255, 255, 255);
        imagefilledrectangle($canvas, 0, 0, $targetWidth, $targetHeight, $white);

        imagecopyresampled(
            $canvas, $image,
            0, 0, 0, 0,
            $targetWidth, $targetHeight,
            $sourceWidth, $sourceHeight
        );

        $this->ensureDirectory($destination);
        $ok = imagejpeg($canvas, $destination, 82);

        imagedestroy($canvas);
        imagedestroy($image);

        return $ok;
    }

    /**
     * Grab a frame a second in -- frame zero is very often black.
     */
    private function fromVideo(string $source, string $destination, int $width): bool
    {
        $this->ensureDirectory($destination);

        try {
            $result = Process::timeout(120)->run([
                (string) config('gnext.media.ffmpeg'),
                '-y',
                '-ss', '1',
                '-i', $source,
                '-frames:v', '1',
                '-vf', 'scale='.$width.':-2',
                $destination,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return $result->successful() && is_file($destination);
    }

    private function open(string $path, string $mime): ?\GdImage
    {
        $image = match ($mime) {
            'image/jpeg' => @imagecreatefromjpeg($path),
            'image/png' => @imagecreatefrompng($path),
            'image/gif' => @imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($path) : false,
            default => false,
        };

        return $image instanceof \GdImage ? $image : null;
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
