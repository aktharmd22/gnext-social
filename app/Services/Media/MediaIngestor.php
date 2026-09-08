<?php

declare(strict_types=1);

namespace App\Services\Media;

use App\Enums\MediaStatus;
use App\Models\PostMedia;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Fetches media from wherever a human pointed us, and puts it somewhere Meta
 * can actually read.
 *
 * This is the load-bearing piece of the whole integration. Meta's servers fetch
 * media themselves, with no browser and no Google session, so the URL we hand
 * them must return raw bytes. A Google Drive share link does not; it returns an
 * HTML viewer page. Every naive build of this system dies here.
 *
 * The sequence is always: resolve -> download -> verify it is not HTML ->
 * store -> probe -> thumbnail -> mark ready.
 */
class MediaIngestor
{
    public function __construct(
        private readonly UrlResolver $urls = new UrlResolver,
        private readonly MediaProbe $probe = new MediaProbe,
        private readonly ThumbnailGenerator $thumbnails = new ThumbnailGenerator,
    ) {}

    /**
     * Ingest one media row. Never throws: failure is recorded on the row so the
     * composer can show it inline against the offending attachment.
     */
    public function ingest(PostMedia $media): PostMedia
    {
        $media->forceFill([
            'status' => MediaStatus::Fetching,
            'error_message' => null,
        ])->save();

        try {
            $this->fetch($media);
        } catch (Throwable $exception) {
            $media->forceFill([
                'status' => MediaStatus::Failed,
                'error_message' => $exception->getMessage(),
            ])->save();
        }

        return $media->refresh();
    }

    /**
     * Take a file that is already on this machine -- a direct upload.
     *
     * No download step, but everything after it is identical: the bytes are
     * still probed for their real type and dimensions, because a .jpg that is
     * actually HEIC fails at Meta exactly the same way whether it arrived by
     * link or by drag and drop.
     */
    public function ingestUploadedFile(PostMedia $media, string $absolutePath, ?string $originalName = null): PostMedia
    {
        $media->forceFill(['status' => MediaStatus::Fetching, 'error_message' => null])->save();

        try {
            if (! is_file($absolutePath)) {
                throw new RuntimeException('That upload could not be read.');
            }

            $size = filesize($absolutePath) ?: 0;
            $maxBytes = (int) config('gnext.media.max_bytes');

            if ($size > $maxBytes) {
                throw new RuntimeException(sprintf(
                    'That file is %s. The limit is %s.',
                    $this->humanBytes($size),
                    $this->humanBytes($maxBytes)
                ));
            }

            $this->store($media, (string) file_get_contents($absolutePath), null, $originalName);
        } catch (Throwable $exception) {
            $media->forceFill([
                'status' => MediaStatus::Failed,
                'error_message' => $exception->getMessage(),
            ])->save();
        }

        return $media->refresh();
    }

    private function fetch(PostMedia $media): void
    {
        $source = (string) $media->source_url;

        if ($rejection = $this->urls->rejectionReason($source)) {
            throw new RuntimeException($rejection);
        }

        [$body, $contentType] = $this->download($source);

        $this->store($media, $body, $contentType, $source);
    }

    /**
     * Everything after the bytes are in hand: store, probe, correct the
     * extension, thumbnail, mark ready.
     *
     * Shared by the download path and the upload path so that a file behaves
     * identically however it arrived.
     */
    private function store(PostMedia $media, string $body, ?string $contentType, ?string $nameHint): void
    {
        $disk = (string) config('gnext.media.disk');
        $extension = $this->extensionFor($contentType, (string) $nameHint);
        $path = 'media/'.now()->format('Y/m').'/'.Str::uuid()->toString().($extension ? '.'.$extension : '');

        Storage::disk($disk)->put($path, $body);

        $absolute = Storage::disk($disk)->path($path);
        $probed = $this->probe->probe($absolute);

        // The extension is a guess until the bytes are read. Correct it now, so
        // Meta is handed a URL whose extension matches its content.
        $path = $this->correctExtension($disk, $path, $probed->mime);
        $absolute = Storage::disk($disk)->path($path);

        $thumbnailPath = $this->makeThumbnail($disk, $path, $absolute, $probed);

        $media->forceFill([
            'disk' => $disk,
            'stored_path' => $path,
            'public_url' => Storage::disk($disk)->url($path),
            'thumbnail_path' => $thumbnailPath,
            'mime' => $probed->mime,
            'size_bytes' => $probed->sizeBytes,
            'width' => $probed->width,
            'height' => $probed->height,
            'duration_seconds' => $probed->duration,
            'status' => MediaStatus::Ready,
            'error_message' => null,
        ])->save();
    }

    /**
     * @return array{0: string, 1: ?string} body and content type
     */
    private function download(string $source): array
    {
        $maxBytes = (int) config('gnext.media.max_bytes');
        $attempted = [];

        foreach ($this->urls->candidates($source) as $candidate) {
            $attempted[] = $candidate;

            $response = Http::withHeaders([
                // Drive serves different responses to clients it does not
                // recognise as browsers.
                'User-Agent' => 'Mozilla/5.0 (compatible; GnextSocial/1.0)',
            ])
                ->connectTimeout(15)
                ->timeout(180)
                ->withOptions(['http_errors' => false, 'allow_redirects' => ['max' => 5]])
                ->get($candidate);

            if ($response->failed()) {
                continue;
            }

            $body = $response->body();
            $contentType = $response->header('Content-Type') ?: null;

            /*
             * The virus-scan interstitial. Google answers 200 with an HTML page
             * for large files rather than the bytes. Without this check we
             * would happily store a web page as an "image" and only find out
             * when Meta refused it.
             */
            if ($this->urls->looksLikeHtml($body, $contentType)) {
                $confirmed = $this->urls->confirmationUrl($candidate, $body);

                if ($confirmed === null) {
                    continue;
                }

                $response = Http::withHeaders(['User-Agent' => 'Mozilla/5.0 (compatible; GnextSocial/1.0)'])
                    ->connectTimeout(15)
                    ->timeout(180)
                    ->withOptions(['http_errors' => false, 'allow_redirects' => ['max' => 5]])
                    ->get($confirmed);

                $body = $response->body();
                $contentType = $response->header('Content-Type') ?: null;

                if ($this->urls->looksLikeHtml($body, $contentType)) {
                    continue;
                }
            }

            if ($body === '') {
                continue;
            }

            if (strlen($body) > $maxBytes) {
                throw new RuntimeException(sprintf(
                    'That file is %s. The limit is %s.',
                    $this->humanBytes(strlen($body)),
                    $this->humanBytes($maxBytes)
                ));
            }

            return [$body, $contentType];
        }

        throw new RuntimeException($this->downloadFailureMessage($source, $attempted));
    }

    /**
     * Say what to do about it, not just that it failed.
     *
     * @param  list<string>  $attempted
     */
    private function downloadFailureMessage(string $source, array $attempted): string
    {
        if ($this->urls->isDriveUrl($source)) {
            return 'We could not download this from Google Drive. '
                .'Open the file, choose Share, and set access to "Anyone with the link" — '
                .'Meta fetches the file itself and cannot sign in to your Drive.';
        }

        return 'We could not download anything from that link. '
            .'Check it opens in a private browser window without signing in.';
    }

    private function makeThumbnail(string $disk, string $path, string $absolute, ProbeResult $probed): ?string
    {
        $thumbnailPath = preg_replace('#^media/#', 'media/thumbs/', $path) ?? null;

        if ($thumbnailPath === null) {
            return null;
        }

        $thumbnailPath = preg_replace('#\.[^.]+$#', '.jpg', $thumbnailPath) ?? $thumbnailPath;

        $made = $this->thumbnails->generate(
            $absolute,
            Storage::disk($disk)->path($thumbnailPath),
            $probed
        );

        return $made !== null ? $thumbnailPath : null;
    }

    private function correctExtension(string $disk, string $path, string $mime): string
    {
        $correct = $this->extensionForMime($mime);

        if ($correct === null) {
            return $path;
        }

        $current = strtolower((string) pathinfo($path, PATHINFO_EXTENSION));

        if ($current === $correct) {
            return $path;
        }

        $corrected = preg_replace('#\.[^.]*$#', '', $path).'.'.$correct;

        if ($corrected !== null && $corrected !== $path) {
            Storage::disk($disk)->move($path, $corrected);

            return $corrected;
        }

        return $path;
    }

    private function extensionFor(?string $contentType, string $source): ?string
    {
        if ($contentType !== null) {
            $extension = $this->extensionForMime(strtolower(trim(explode(';', $contentType)[0])));

            if ($extension !== null) {
                return $extension;
            }
        }

        $fromPath = strtolower((string) pathinfo((string) parse_url($source, PHP_URL_PATH), PATHINFO_EXTENSION));

        return $fromPath !== '' && preg_match('/^[a-z0-9]{2,5}$/', $fromPath) === 1
            ? $fromPath
            : null;
    }

    private function extensionForMime(string $mime): ?string
    {
        return match ($mime) {
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp',
            'image/heic' => 'heic',
            'video/mp4' => 'mp4',
            'video/quicktime' => 'mov',
            'video/webm' => 'webm',
            'video/x-matroska' => 'mkv',
            default => null,
        };
    }

    private function humanBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $value = (float) $bytes;
        $i = 0;

        while ($value >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return round($value, $i === 0 ? 0 : 1).' '.$units[$i];
    }
}
