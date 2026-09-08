<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\ReviewAction;
use App\Services\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The shareable client review link.
 *
 * Read-only, signed, expiring, and account-free by design: a client should not
 * need a login to say "yes, publish that". Everything they do is recorded
 * against a pseudonymous identity, so repeat visits are correlatable without
 * storing anything about who they are.
 */
class ReviewController extends Controller
{
    public function show(Request $request, string $uuid)
    {
        $post = $this->post($uuid);

        return view('review.show', [
            'post' => $post,
            'timezone' => $post->workspace?->timezone ?? config('gnext.default_timezone'),
            'actions' => $post->reviewActions()->latest('created_at')->get(),
            'alreadyActed' => $post->reviewActions()
                ->where('reviewer_hash', $this->reviewerHash($request, $post))
                ->exists(),
        ]);
    }

    public function decide(Request $request, string $uuid, ActivityLogger $log)
    {
        $post = $this->post($uuid);

        // The link is public, so it gets its own limiter rather than
        // inheriting an authenticated one.
        $key = 'review:'.$this->reviewerHash($request, $post);

        if (RateLimiter::tooManyAttempts($key, (int) config('gnext.review.rate_limit_per_minute', 10))) {
            return back()->with('error', 'That is a lot of clicks. Try again in a minute.');
        }

        RateLimiter::hit($key, 60);

        $validated = $request->validate([
            'action' => ['required', 'in:approve,comment'],
            'note' => ['nullable', 'string', 'max:2000', 'required_if:action,comment'],
        ], [
            'note.required_if' => 'Add a comment so the team knows what to change.',
        ]);

        ReviewAction::create([
            'post_id' => $post->id,
            'reviewer_hash' => $this->reviewerHash($request, $post),
            'action' => $validated['action'],
            'note' => $validated['note'] ?? null,
            'ip_address' => $request->ip(),
            'user_agent' => substr((string) $request->userAgent(), 0, 1000),
        ]);

        /*
         * A client approval is a signal, not a publish authorisation. The post
         * stays in the internal queue: someone on the team still confirms it.
         * Anything else would let a forwarded link put content live.
         */
        $log->log(
            $validated['action'] === 'approve' ? 'review.approved' : 'review.commented',
            $post,
            ['via' => 'share link']
        );

        return back()->with('status', $validated['action'] === 'approve'
            ? 'Thank you — your approval has been passed to the team.'
            : 'Thank you — your comment has been passed to the team.');
    }

    private function post(string $uuid): Post
    {
        $post = Post::withoutGlobalScopes()
            ->with(['media', 'targets.socialAccount', 'workspace', 'reviewActions'])
            ->where('public_uuid', $uuid)
            ->firstOrFail();

        // A published post is no longer under review, and a deleted one should
        // 404 rather than resurrect through an old link.
        abort_if($post->status === PostStatus::Cancelled, 410);

        return $post;
    }

    /**
     * A salted digest of who is looking.
     *
     * Correlates repeat visits from the same recipient without storing anything
     * that identifies them. Salted with the app key and the post, so the same
     * person reviewing two posts produces two unrelated hashes.
     */
    private function reviewerHash(Request $request, Post $post): string
    {
        return hash('sha256', implode('|', [
            config('app.key'),
            $post->public_uuid,
            $request->ip(),
            $request->userAgent(),
        ]));
    }
}
