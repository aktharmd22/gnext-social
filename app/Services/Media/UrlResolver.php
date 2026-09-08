<?php

declare(strict_types=1);

namespace App\Services\Media;

/**
 * Turns a link a human pasted into a URL that actually returns bytes.
 *
 * This exists because of one specific, repeated failure: the source spreadsheet
 * stores Google Drive links like
 *
 *     https://drive.google.com/file/d/FILE_ID/view?usp=drive_link
 *
 * That URL returns an HTML viewer page, not an image. Meta fetches media from
 * whatever URL we hand it, server-side, with no browser and no Google session.
 * Handing it a Drive share link produces a cryptic media error at publish time,
 * hours after anyone was looking.
 *
 * So we never hand Meta a Drive link at all. We resolve it here, download the
 * bytes ourselves, and give Meta a URL on a disk we control.
 */
class UrlResolver
{
    /**
     * Drive file id, from every share URL shape Google has used.
     */
    private const DRIVE_PATTERNS = [
        '#drive\.google\.com/file/d/([A-Za-z0-9_-]{10,})#i',
        '#drive\.google\.com/open\?(?:.*&)?id=([A-Za-z0-9_-]{10,})#i',
        '#drive\.google\.com/uc\?(?:.*&)?id=([A-Za-z0-9_-]{10,})#i',
        '#drive\.google\.com/thumbnail\?(?:.*&)?id=([A-Za-z0-9_-]{10,})#i',
        '#docs\.google\.com/[a-z]+/d/([A-Za-z0-9_-]{10,})#i',
    ];

    public function isDriveUrl(string $url): bool
    {
        return $this->driveFileId($url) !== null;
    }

    public function driveFileId(string $url): ?string
    {
        foreach (self::DRIVE_PATTERNS as $pattern) {
            if (preg_match($pattern, $url, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }

    /**
     * A Drive *folder* is not a file, and the difference is worth catching
     * early: pasting a folder link is a common mistake and produces a
     * completely unhelpful error much later otherwise.
     */
    public function isDriveFolder(string $url): bool
    {
        return preg_match('#drive\.google\.com/drive/(u/\d+/)?folders/#i', $url) === 1;
    }

    /**
     * Candidate download URLs to try, in order.
     *
     * Drive gets two: the documented `uc?export=download` form first, then the
     * large-file host that Drive itself redirects to. Anything else is used
     * as-is.
     *
     * @return list<string>
     */
    public function candidates(string $url): array
    {
        $url = trim($url);
        $fileId = $this->driveFileId($url);

        if ($fileId === null) {
            return [$url];
        }

        return [
            sprintf(config('gnext.media.drive_download_template'), $fileId),
            'https://drive.usercontent.google.com/download?id='.$fileId.'&export=download',
        ];
    }

    /**
     * Google interposes a virus-scan warning page for large files rather than
     * serving the bytes. It is HTML, it is a 200, and it will be stored as a
     * perfectly valid, completely useless "image" unless we notice.
     *
     * When we find one, we pull the confirmation token back out and retry.
     */
    public function confirmationUrl(string $url, string $html): ?string
    {
        $fileId = $this->driveFileId($url);

        if ($fileId === null) {
            return null;
        }

        // Newer interstitial: a form with a confirm token.
        if (preg_match('#name="confirm"\s+value="([^"]+)"#i', $html, $m) === 1) {
            return 'https://drive.usercontent.google.com/download?id='.$fileId
                .'&export=download&confirm='.$m[1];
        }

        // Older interstitial: confirm token in a link.
        if (preg_match('#confirm=([0-9A-Za-z_-]+)#i', $html, $m) === 1) {
            return sprintf(config('gnext.media.drive_download_template'), $fileId)
                .'&confirm='.$m[1];
        }

        return null;
    }

    /**
     * Did we get an HTML page where we expected media?
     *
     * Checked on the bytes rather than the Content-Type header, because Drive
     * and several CDNs answer with a generic octet-stream while serving HTML.
     */
    public function looksLikeHtml(string $contents, ?string $contentType = null): bool
    {
        if ($contentType !== null && str_contains(strtolower($contentType), 'text/html')) {
            return true;
        }

        $head = ltrim(substr($contents, 0, 512));

        return $head !== ''
            && (stripos($head, '<!doctype html') === 0
                || stripos($head, '<html') === 0
                || stripos($head, '<?xml') === 0 && stripos($head, '<html') !== false);
    }

    /**
     * A human-readable explanation of why a link cannot work, or null if it
     * looks usable. Surfaced in the composer, before scheduling.
     */
    public function rejectionReason(string $url): ?string
    {
        $url = trim($url);

        if ($url === '') {
            return 'Add a link or upload a file.';
        }

        if ($this->isDriveFolder($url)) {
            return 'That is a Google Drive folder, not a file. Open the image or video and copy its own share link.';
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        if (! in_array($scheme, ['http', 'https'], true)) {
            return 'That does not look like a web link. It should start with https://.';
        }

        if (preg_match('#docs\.google\.com/(document|spreadsheets|presentation)/#i', $url) === 1) {
            return 'That is a Google Doc, not an image or video. Share the media file itself.';
        }

        return null;
    }
}
