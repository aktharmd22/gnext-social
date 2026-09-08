<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\PostType;
use App\Models\PostMedia;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Makes media fit, rather than only refusing it.
 *
 * The brief is explicit that an aspect-ratio failure should offer crop or pad,
 * not just reject: someone has a 1:1 export and a Reel to publish, and telling
 * them to go back to the designer is the least useful thing this could do.
 *
 * Always writes a NEW file. The original is left untouched so a bad crop is one
 * click to undo, and so the same asset can be reused elsewhere at its own
 * aspect.
 */
class AutoFitService
{
    public function __construct(
        private readonly MediaProbe $probe = new MediaProbe,
        private readonly ThumbnailGenerator $thumbnails = new ThumbnailGenerator,
    ) {}

    public const CROP = 'crop';

    public const PAD = 'pad';

    /**
     * Refit one attachment to the aspect its post type needs.
     *
     * @param  self::CROP|self::PAD  $mode
     */
    public function refit(PostMedia $media, PostType $type, string $mode = self::CROP): PostMedia
    {
        if ($media->stored_path === null) {
            throw new RuntimeException('This file has not been fetched yet, so there is nothing to crop.');
        }

        [$targetRatio] = $type->aspectRange();

        // Feed posts accept a range; 4:5 is the tallest allowed and wastes the
        // least of a portrait source, so it is the sensible target.
        if ($type === PostType::Post || $type === PostType::Carousel) {
            $targetRatio = 0.8;
        }

        $disk = $media->disk ?? config('gnext.media.disk');
        $source = Storage::disk($disk)->path($media->stored_path);

        $extension = $media->isVideo() ? 'mp4' : 'jpg';
        $destinationPath = 'media/'.now()->format('Y/m').'/'.Str::uuid()->toString().'.'.$extension;
        $destination = Storage::disk($disk)->path($destinationPath);

        $this->ensureDirectory($destination);

        $ok = $media->isVideo()
            ? $this->refitVideo($source, $destination, $targetRatio, $mode)
            : $this->refitImage($source, $destination, $targetRatio, $mode);

        if (! $ok) {
            throw new RuntimeException(
                'That file could not be refitted automatically. Re-export it at the right ratio and re-attach it.'
            );
        }

        $probed = $this->probe->probe($destination);

        $thumbnailPath = preg_replace('#^media/#', 'media/thumbs/', $destinationPath);
        $thumbnailPath = preg_replace('#\.[^.]+$#', '.jpg', (string) $thumbnailPath);

        $this->thumbnails->generate($destination, Storage::disk($disk)->path($thumbnailPath), $probed);

        $media->forceFill([
            'stored_path' => $destinationPath,
            'public_url' => Storage::disk($disk)->url($destinationPath),
            'thumbnail_path' => $thumbnailPath,
            'mime' => $probed->mime,
            'size_bytes' => $probed->sizeBytes,
            'width' => $probed->width,
            'height' => $probed->height,
            'duration_seconds' => $probed->duration,
            'error_message' => null,
        ])->save();

        return $media->refresh();
    }

    /**
     * Centre-crop or letterbox an image to the target ratio.
     */
    private function refitImage(string $source, string $destination, float $targetRatio, string $mode): bool
    {
        $probed = $this->probe->probe($source);

        if (! $probed->width || ! $probed->height) {
            return false;
        }

        $image = match ($probed->mime) {
            'image/jpeg' => @imagecreatefromjpeg($source),
            'image/png' => @imagecreatefrompng($source),
            'image/webp' => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($source) : false,
            'image/gif' => @imagecreatefromgif($source),
            default => false,
        };

        if (! $image instanceof \GdImage) {
            return false;
        }

        $sourceWidth = imagesx($image);
        $sourceHeight = imagesy($image);

        if ($mode === self::CROP) {
            // Keep the largest centred rectangle at the target ratio.
            $cropWidth = $sourceWidth;
            $cropHeight = (int) round($sourceWidth / $targetRatio);

            if ($cropHeight > $sourceHeight) {
                $cropHeight = $sourceHeight;
                $cropWidth = (int) round($sourceHeight * $targetRatio);
            }

            $x = (int) round(($sourceWidth - $cropWidth) / 2);
            $y = (int) round(($sourceHeight - $cropHeight) / 2);

            $canvas = imagecreatetruecolor($cropWidth, $cropHeight);
            imagefilledrectangle($canvas, 0, 0, $cropWidth, $cropHeight, imagecolorallocate($canvas, 255, 255, 255));
            imagecopy($canvas, $image, 0, 0, $x, $y, $cropWidth, $cropHeight);
        } else {
            // Fit the whole image inside a canvas at the target ratio, padded.
            $canvasWidth = $sourceWidth;
            $canvasHeight = (int) round($sourceWidth / $targetRatio);

            if ($canvasHeight < $sourceHeight) {
                $canvasHeight = $sourceHeight;
                $canvasWidth = (int) round($sourceHeight * $targetRatio);
            }

            $canvas = imagecreatetruecolor($canvasWidth, $canvasHeight);

            // White rather than black: it reads as a deliberate border on a
            // feed rather than as a broken image.
            imagefilledrectangle($canvas, 0, 0, $canvasWidth, $canvasHeight, imagecolorallocate($canvas, 255, 255, 255));

            imagecopy(
                $canvas, $image,
                (int) round(($canvasWidth - $sourceWidth) / 2),
                (int) round(($canvasHeight - $sourceHeight) / 2),
                0, 0, $sourceWidth, $sourceHeight
            );
        }

        $written = imagejpeg($canvas, $destination, 92);

        imagedestroy($canvas);
        imagedestroy($image);

        return $written;
    }

    /**
     * The same two operations on video, via ffmpeg.
     *
     * Dimensions are forced even so that H.264 accepts them -- an odd width or
     * height is rejected outright, which is a confusing way to fail.
     */
    private function refitVideo(string $source, string $destination, float $targetRatio, string $mode): bool
    {
        $filter = $mode === self::CROP
            ? sprintf(
                "crop='if(gte(iw/ih,%1\$.4f),ih*%1\$.4f,iw)':'if(gte(iw/ih,%1\$.4f),ih,iw/%1\$.4f)',".
                'scale=trunc(iw/2)*2:trunc(ih/2)*2',
                $targetRatio
            )
            : sprintf(
                "pad='if(gte(iw/ih,%1\$.4f),iw,ih*%1\$.4f)':'if(gte(iw/ih,%1\$.4f),iw/%1\$.4f,ih)':".
                "'(ow-iw)/2':'(oh-ih)/2':white,".
                'scale=trunc(iw/2)*2:trunc(ih/2)*2',
                $targetRatio
            );

        try {
            $result = Process::timeout(600)->run([
                (string) config('gnext.media.ffmpeg'),
                '-y',
                '-i', $source,
                '-vf', $filter,
                // What Instagram actually accepts.
                '-c:v', 'libx264',
                '-preset', 'medium',
                '-pix_fmt', 'yuv420p',
                '-c:a', 'aac',
                '-b:a', '128k',
                '-movflags', '+faststart',
                $destination,
            ]);
        } catch (\Throwable) {
            return false;
        }

        return $result->successful() && is_file($destination) && filesize($destination) > 0;
    }

    private function ensureDirectory(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            mkdir($directory, 0775, true);
        }
    }
}
