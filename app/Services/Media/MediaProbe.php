<?php

declare(strict_types=1);

namespace App\Services\Media;

use Illuminate\Support\Facades\Process;
use RuntimeException;

/**
 * Reads a local file and reports what it really is.
 *
 * Extensions and Content-Type headers both lie routinely -- a .jpg that is
 * actually HEIC, a Drive download served as octet-stream. Everything downstream
 * (validation, the composer preview, the publisher) reads this instead.
 */
class MediaProbe
{
    public function __construct(private readonly UrlResolver $urls = new UrlResolver) {}

    /**
     * @throws RuntimeException when the file cannot be read at all
     */
    public function probe(string $absolutePath): ProbeResult
    {
        if (! is_file($absolutePath)) {
            throw new RuntimeException("No file at [{$absolutePath}].");
        }

        $size = filesize($absolutePath) ?: null;
        $mime = $this->sniffMime($absolutePath);

        if (str_starts_with($mime, 'image/')) {
            return $this->probeImage($absolutePath, $mime, $size);
        }

        return $this->probeVideo($absolutePath, $mime, $size);
    }

    /**
     * Real MIME from the file's magic bytes, never from its name.
     */
    public function sniffMime(string $absolutePath): string
    {
        $info = finfo_open(FILEINFO_MIME_TYPE);

        if ($info === false) {
            return 'application/octet-stream';
        }

        $mime = finfo_file($info, $absolutePath);
        finfo_close($info);

        return $mime !== false ? $mime : 'application/octet-stream';
    }

    private function probeImage(string $path, string $mime, ?int $size): ProbeResult
    {
        $dimensions = @getimagesize($path);

        return new ProbeResult(
            mime: $mime,
            width: $dimensions[0] ?? null,
            height: $dimensions[1] ?? null,
            sizeBytes: $size,
        );
    }

    /**
     * Video needs ffprobe. A Reel that is 2.5 seconds long, or has no audio
     * stream, is rejected by Instagram at publish time with an unhelpful error,
     * so we would rather know now.
     */
    private function probeVideo(string $path, string $mime, ?int $size): ProbeResult
    {
        $json = $this->runFfprobe($path);

        if ($json === null) {
            return new ProbeResult(mime: $mime, sizeBytes: $size);
        }

        $streams = $json['streams'] ?? [];
        $video = null;
        $audio = null;

        foreach ($streams as $stream) {
            $type = $stream['codec_type'] ?? null;

            if ($type === 'video' && $video === null) {
                $video = $stream;
            }

            if ($type === 'audio' && $audio === null) {
                $audio = $stream;
            }
        }

        $duration = $json['format']['duration'] ?? $video['duration'] ?? null;

        return new ProbeResult(
            mime: $mime,
            width: isset($video['width']) ? (int) $video['width'] : null,
            height: isset($video['height']) ? (int) $video['height'] : null,
            duration: $duration !== null ? round((float) $duration, 2) : null,
            videoCodec: isset($video['codec_name']) ? (string) $video['codec_name'] : null,
            audioCodec: isset($audio['codec_name']) ? (string) $audio['codec_name'] : null,
            sizeBytes: $size,
        );
    }

    /**
     * @return array<string, mixed>|null
     */
    private function runFfprobe(string $path): ?array
    {
        $binary = (string) config('gnext.media.ffprobe');

        try {
            $result = Process::timeout(60)->run([
                $binary,
                '-v', 'quiet',
                '-print_format', 'json',
                '-show_format',
                '-show_streams',
                $path,
            ]);
        } catch (\Throwable) {
            // ffprobe missing or unrunnable. Degrade to "we know the MIME and
            // the size"; validation will flag what it cannot confirm rather
            // than blocking the whole pipeline.
            return null;
        }

        if (! $result->successful()) {
            return null;
        }

        $decoded = json_decode($result->output(), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function ffprobeAvailable(): bool
    {
        try {
            return Process::timeout(10)
                ->run([(string) config('gnext.media.ffprobe'), '-version'])
                ->successful();
        } catch (\Throwable) {
            return false;
        }
    }
}
