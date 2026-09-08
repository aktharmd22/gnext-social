<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Tests\TestCase;

/**
 * Errors say what happened and what to do, on every path out of the product.
 *
 * "419 PAGE EXPIRED" on an unstyled page is exactly the failure the brief calls
 * out: it tells a person nothing they can act on.
 *
 * The CSRF cases go through the exception handler directly rather than over
 * HTTP, because Laravel's VerifyCsrfToken middleware short-circuits whenever
 * `runningUnitTests()` is true -- a real stale token cannot be simulated
 * through the test client.
 */
class ErrorPagesTest extends TestCase
{
    use RefreshDatabase;

    private function render(Request $request)
    {
        return app(ExceptionHandler::class)->render($request, new TokenMismatchException);
    }

    /**
     * The common case: a form left open while the session lapsed, or a deploy
     * that cleared sessions. It returns the person to the form with an
     * explanation, rather than to a red page.
     */
    public function test_an_expired_session_sends_a_guest_back_to_sign_in(): void
    {
        $request = Request::create('/login', 'POST');
        $request->setLaravelSession(app('session.store'));

        $response = $this->render($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(route('login'), $response->headers->get('Location'));

        $this->assertStringContainsString(
            'session expired',
            (string) app('session.store')->get('error')
        );
    }

    /**
     * Someone already signed in goes back where they were, not to the login
     * screen they do not need.
     */
    public function test_an_expired_session_returns_a_signed_in_user_where_they_were(): void
    {
        $user = User::factory()->create();

        $request = Request::create('/posts', 'POST');
        $request->setLaravelSession(app('session.store'));
        $request->setUserResolver(fn () => $user);

        $response = $this->render($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertNotSame(route('login'), $response->headers->get('Location'));
    }

    /**
     * A Livewire or API caller gets JSON, not a redirect it cannot follow.
     */
    public function test_an_expired_session_answers_json_when_json_was_asked_for(): void
    {
        $request = Request::create('/login', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        $request->setLaravelSession(app('session.store'));

        $response = $this->render($request);

        $this->assertSame(419, $response->getStatusCode());
        $this->assertStringContainsString(
            'Reload the page and try again',
            (string) $response->getContent()
        );
    }

    public function test_the_login_screen_surfaces_that_message(): void
    {
        $this->withSession(['error' => 'Your session expired before that was submitted, so nothing was saved.'])
            ->get('/login')
            ->assertOk()
            ->assertSee('session expired', false);
    }

    // =====================================================================
    // The error pages themselves carry the brand, not Laravel's default.
    // =====================================================================

    public function test_a_missing_page_explains_itself(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Nothing here')
            ->assertSee('Back to the calendar');
    }

    public function test_a_forbidden_page_says_what_to_do_about_it(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/activity')
            ->assertForbidden()
            ->assertSee('Not yours to open')
            ->assertSee('ask an admin');
    }

    /**
     * Error pages must render without a session or a signed-in user, because
     * those are exactly what may have failed.
     */
    public function test_error_pages_render_for_a_guest(): void
    {
        $this->get('/no-such-page')
            ->assertNotFound()
            ->assertSee('Nothing here');
    }

    /**
     * The one thing someone actually worries about on a 500: did my scheduled
     * post go out?
     */
    public function test_the_server_error_page_reassures_about_scheduled_posts(): void
    {
        $rendered = view('errors.500', ['exception' => new \RuntimeException('boom')])->render();

        $this->assertStringContainsString('Something broke on our side', $rendered);
        $this->assertStringContainsString('Scheduled posts are unaffected', $rendered);
        $this->assertStringContainsString('not your fault', $rendered);
    }

    public function test_every_error_page_renders(): void
    {
        foreach ([403, 404, 419, 429, 500, 503] as $code) {
            $rendered = view("errors.{$code}", ['exception' => new \RuntimeException('x')])->render();

            $this->assertStringContainsString((string) $code, $rendered, "errors.{$code} should show its code.");
            $this->assertStringContainsString('GnextSocial', $rendered, "errors.{$code} should carry the brand.");
        }
    }
}
