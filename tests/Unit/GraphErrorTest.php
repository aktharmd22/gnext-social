<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\Meta\GraphError;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Retrying an expired token three times, 21 minutes apart, helps nobody.
 *
 * This classification is what stops the scheduler burning its whole retry
 * budget on failures that could never succeed.
 */
class GraphErrorTest extends TestCase
{
    private function error(int $code, ?int $subcode = null, ?int $status = 400): GraphError
    {
        return GraphError::fromResponse([
            'error' => [
                'code' => $code,
                'error_subcode' => $subcode,
                'message' => 'Meta said no.',
                'type' => 'OAuthException',
            ],
        ], $status);
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function retryableCodes(): array
    {
        return [
            'unknown transient' => [1],
            'service unavailable' => [2],
            'app request limit' => [4],
            'user request limit' => [17],
            'page request limit' => [32],
            'application limit' => [341],
            'api rate limit' => [613],
            'ig publish limit' => [80004],
        ];
    }

    #[DataProvider('retryableCodes')]
    public function test_transient_and_rate_limited_errors_are_retryable(int $code): void
    {
        $this->assertTrue($this->error($code)->isRetryable());
    }

    /**
     * @return array<string, array{0: int}>
     */
    public static function terminalCodes(): array
    {
        return [
            'invalid token' => [190],
            'permission denied' => [200],
            'no permission' => [10],
            'invalid parameter' => [100],
            'media fetch failed' => [9004],
            'unsupported video' => [2207026],
        ];
    }

    #[DataProvider('terminalCodes')]
    public function test_terminal_errors_are_not_retried(int $code): void
    {
        $this->assertFalse($this->error($code)->isRetryable());
    }

    public function test_a_transport_failure_is_always_retryable(): void
    {
        $error = GraphError::fromTransport('Connection timed out after 10000ms');

        $this->assertTrue($error->isRetryable());
        $this->assertSame('transport', $error->shortCode());
    }

    public function test_a_server_error_is_retryable_whatever_the_code(): void
    {
        $this->assertTrue($this->error(100, null, 503)->isRetryable());
    }

    public function test_it_recognises_a_dead_token_by_code_or_subcode(): void
    {
        $this->assertTrue($this->error(190)->isTokenProblem());
        $this->assertTrue($this->error(102, 463)->isTokenProblem());
        $this->assertTrue($this->error(102, 467)->isTokenProblem());
        $this->assertFalse($this->error(100)->isTokenProblem());
    }

    public function test_error_codes_are_stable_and_groupable(): void
    {
        $this->assertSame('token_expired', $this->error(190)->shortCode());
        $this->assertSame('permission_denied', $this->error(200)->shortCode());
        $this->assertSame('rate_limited', $this->error(4)->shortCode());
        $this->assertSame('media_rejected', $this->error(9004)->shortCode());
        $this->assertSame('graph_100', $this->error(100)->shortCode());
    }

    /**
     * The brief is explicit: not "Invalid token." but what expired and what to
     * do about it.
     */
    public function test_a_dead_token_explains_itself_and_names_the_fix(): void
    {
        $message = $this->error(190)->userMessage('Spark Tires FB');

        $this->assertStringContainsString('Spark Tires FB', $message);
        $this->assertStringContainsString('Reconnect', $message);
        $this->assertStringNotContainsString('OAuthException', $message);
    }

    public function test_the_instagram_daily_cap_explains_that_it_will_self_resolve(): void
    {
        $message = $this->error(80004)->userMessage('Spark Tires IG');

        $this->assertStringContainsString('50 published posts in 24 hours', $message);
        $this->assertStringContainsString('automatically', $message);
    }

    public function test_a_media_rejection_points_at_the_media(): void
    {
        $message = $this->error(9004)->userMessage();

        $this->assertStringContainsString('could not read the media', $message);
        $this->assertStringContainsString('public URL', $message);
    }

    public function test_it_never_leaks_a_raw_meta_envelope_into_user_copy(): void
    {
        // An unmapped code falls back to Meta's own message, tidied, rather
        // than to "An error occurred."
        $message = $this->error(999_999)->userMessage();

        $this->assertSame('Meta said no.', $message);
    }
}
