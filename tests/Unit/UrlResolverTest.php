<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Media\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Google Drive links are not media URLs.
 *
 * The source spreadsheet is full of them, Meta fetches media server-side with
 * no browser and no Google session, and a share link returns HTML. This class
 * is the whole defence, so it is tested against every URL shape Google uses.
 */
class UrlResolverTest extends TestCase
{
    private UrlResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->resolver = new UrlResolver;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function driveUrls(): array
    {
        return [
            'share link' => ['https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view?usp=drive_link'],
            'sharing link' => ['https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view?usp=sharing'],
            'no query' => ['https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view'],
            'open form' => ['https://drive.google.com/open?id=1A2b3C4d5E6f7G8h'],
            'uc form' => ['https://drive.google.com/uc?export=download&id=1A2b3C4d5E6f7G8h'],
            'thumbnail form' => ['https://drive.google.com/thumbnail?id=1A2b3C4d5E6f7G8h&sz=w1000'],
        ];
    }

    #[DataProvider('driveUrls')]
    public function test_it_recognises_every_drive_url_shape(string $url): void
    {
        $this->assertTrue($this->resolver->isDriveUrl($url));
        $this->assertSame('1A2b3C4d5E6f7G8h', $this->resolver->driveFileId($url));
    }

    public function test_it_rewrites_a_share_link_to_a_direct_download(): void
    {
        $candidates = $this->resolver->candidates(
            'https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view?usp=drive_link'
        );

        $this->assertSame(
            'https://drive.google.com/uc?export=download&id=1A2b3C4d5E6f7G8h',
            $candidates[0]
        );

        // A fallback host, because Drive redirects large files elsewhere.
        $this->assertStringContainsString('drive.usercontent.google.com', $candidates[1]);
    }

    public function test_a_plain_url_is_passed_through_untouched(): void
    {
        $url = 'https://cdn.example.test/creative/reel-final.mp4';

        $this->assertSame([$url], $this->resolver->candidates($url));
        $this->assertFalse($this->resolver->isDriveUrl($url));
    }

    public function test_a_drive_folder_is_rejected_with_an_explanation(): void
    {
        $reason = $this->resolver->rejectionReason(
            'https://drive.google.com/drive/folders/1A2b3C4d5E6f7G8h'
        );

        $this->assertNotNull($reason);
        $this->assertStringContainsString('folder', $reason);
    }

    public function test_a_google_doc_is_rejected_with_an_explanation(): void
    {
        $reason = $this->resolver->rejectionReason(
            'https://docs.google.com/document/d/1A2b3C4d5E6f7G8h/edit'
        );

        $this->assertNotNull($reason);
        $this->assertStringContainsString('not an image or video', $reason);
    }

    public function test_a_usable_link_has_no_rejection_reason(): void
    {
        $this->assertNull($this->resolver->rejectionReason('https://cdn.example.test/a.jpg'));
        $this->assertNull($this->resolver->rejectionReason(
            'https://drive.google.com/file/d/1A2b3C4d5E6f7G8h/view'
        ));
    }

    /**
     * The failure that silently stores a web page as an image.
     */
    public function test_it_detects_an_html_page_masquerading_as_media(): void
    {
        $this->assertTrue($this->resolver->looksLikeHtml('<!DOCTYPE html><html><body>Sign in</body></html>'));
        $this->assertTrue($this->resolver->looksLikeHtml('<html lang="en">...'));

        // Content-Type alone is enough, even when the body is opaque.
        $this->assertTrue($this->resolver->looksLikeHtml('anything', 'text/html; charset=utf-8'));

        // Real JPEG magic bytes are not HTML.
        $this->assertFalse($this->resolver->looksLikeHtml("\xFF\xD8\xFF\xE0".'JFIF', 'image/jpeg'));
    }

    public function test_it_extracts_the_confirmation_token_from_the_virus_scan_page(): void
    {
        $html = '<form action="https://drive.usercontent.google.com/download">'
            .'<input type="hidden" name="confirm" value="t-9f8e7d6c">'
            .'</form>';

        $confirmed = $this->resolver->confirmationUrl(
            'https://drive.google.com/uc?export=download&id=1A2b3C4d5E6f7G8h',
            $html
        );

        $this->assertNotNull($confirmed);
        $this->assertStringContainsString('confirm=t-9f8e7d6c', $confirmed);
        $this->assertStringContainsString('1A2b3C4d5E6f7G8h', $confirmed);
    }
}
