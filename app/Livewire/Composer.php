<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\MediaSourceType;
use App\Enums\MediaStatus;
use App\Enums\Platform;
use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Jobs\FetchMediaJob;
use App\Models\CaptionTemplate;
use App\Models\HashtagSet;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostPlatformOverride;
use App\Models\PostTarget;
use App\Models\SocialAccount;
use App\Services\ActivityLogger;
use App\Services\CaptionComposer;
use App\Services\DuplicateDetector;
use App\Services\Media\AutoFitService;
use App\Services\Media\MediaIngestor;
use App\Services\Media\ProbeResult;
use App\Services\Media\SpecValidator;
use App\Services\Media\UrlResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * Write a post, see it as it will appear, schedule it.
 *
 * Opens as a right drawer over the calendar on desktop and full-screen on
 * mobile. Everything it validates, it validates now -- before scheduling --
 * because Meta's rejections arrive at the scheduled minute with nobody awake.
 */
#[Layout('components.layouts.app')]
class Composer extends Component
{
    use WithFileUploads;

    /**
     * Retained so the form markup can stay one template. Always true now that
     * the composer is a page of its own rather than a drawer.
     */
    public bool $open = true;

    public ?int $postId = null;

    // ------------------------------------------------------------- the post

    public string $title = '';

    public string $type = 'post';

    public string $caption = '';

    public string $captionAr = '';

    public string $firstComment = '';

    public bool $appendBrandFooter = true;

    public string $scheduledDate = '';

    public string $scheduledTime = '09:00';

    /** @var array<int, int> social_account ids */
    public array $destinations = [];

    // --------------------------------------------------------- per platform

    public bool $splitCaptions = false;

    public string $captionFacebook = '';

    public string $captionInstagram = '';

    // -------------------------------------------------------------- media

    public string $mediaUrl = '';

    /** Direct upload, as an alternative to pasting a link. */
    public $upload;

    // ---------------------------------------------------------------- ui

    public bool $showArabic = false;

    public string $previewPlatform = 'instagram';

    /**
     * Entry point for both /posts/create and /posts/{post}/edit.
     *
     * A page rather than a drawer, so a post has a URL: it can be linked in
     * chat, opened in its own tab from the calendar, bookmarked, and reached
     * with the browser's back button.
     */
    public function mount(?Post $post = null): void
    {
        if ($post?->exists) {
            $this->openExisting($post->id);

            return;
        }

        $this->openNew(
            request()->query('date'),
            request()->query('time'),
        );
    }

    // =====================================================================
    // Opening and closing
    // =====================================================================

    public function openNew(?string $date = null, ?string $time = null): void
    {
        Gate::authorize('create', Post::class);

        $this->reset([
            'postId', 'title', 'caption', 'captionAr', 'firstComment',
            'destinations', 'splitCaptions', 'captionFacebook', 'captionInstagram',
            'mediaUrl', 'showArabic',
        ]);

        $this->resetValidation();

        $this->type = 'post';
        $this->appendBrandFooter = true;
        $this->scheduledDate = $date ?? now($this->timezone())->addDay()->toDateString();
        $this->scheduledTime = $time ?? '09:00';

        // Default to every connected destination: posting to one of two
        // accounts is the exception, not the rule.
        $this->destinations = $this->availableAccounts()->pluck('id')->all();

        $this->open = true;
    }

    public function openExisting(int $postId): void
    {
        $post = Post::query()->with(['targets', 'overrides', 'media'])->findOrFail($postId);

        Gate::authorize('update', $post);

        $this->postId = $post->id;
        $this->title = (string) $post->title;
        $this->type = $post->type->value;
        $this->caption = (string) $post->caption;
        $this->captionAr = (string) $post->caption_ar;
        $this->firstComment = (string) $post->first_comment;
        $this->appendBrandFooter = $post->append_brand_footer;
        $this->showArabic = filled($post->caption_ar);

        $local = $post->scheduled_at?->copy()->setTimezone($this->timezone());
        $this->scheduledDate = $local?->toDateString() ?? now($this->timezone())->addDay()->toDateString();
        $this->scheduledTime = $local?->format('H:i') ?? '09:00';

        $this->destinations = $post->targets->pluck('social_account_id')->all();

        $facebook = $post->overrides->firstWhere('platform', Platform::Facebook);
        $instagram = $post->overrides->firstWhere('platform', Platform::Instagram);

        $this->captionFacebook = (string) ($facebook?->caption ?? '');
        $this->captionInstagram = (string) ($instagram?->caption ?? '');
        $this->splitCaptions = filled($this->captionFacebook) || filled($this->captionInstagram);

        $this->resetValidation();
        $this->open = true;
    }

    /**
     * Where to go when the composer is done with. Defaults to the calendar,
     * but honours wherever the operator came from.
     */
    private function returnTo(): string
    {
        $target = request()->query('from');

        return in_array($target, ['posts', 'media', 'approvals'], true)
            ? route($target === 'posts' ? 'posts.index' : ($target === 'media' ? 'media.index' : 'approvals'))
            : route('calendar');
    }

    public function close()
    {
        return $this->redirect($this->returnTo(), navigate: true);
    }

    // =====================================================================
    // Derived state, recomputed on every keystroke
    // =====================================================================

    public function getPostTypeProperty(): PostType
    {
        return PostType::tryFrom($this->type) ?? PostType::Post;
    }

    /**
     * The caption that will actually go to one platform, override and footer
     * included. This is what the preview and the counters both read, so what
     * you see is what publishes.
     */
    public function effectiveCaption(Platform $platform): string
    {
        $base = $this->caption;

        if ($this->splitCaptions) {
            $override = $platform === Platform::Facebook ? $this->captionFacebook : $this->captionInstagram;
            $base = filled($override) ? $override : $this->caption;
        }

        $base = trim($base);

        if (! $this->appendBrandFooter) {
            return $base;
        }

        $footer = trim((string) CaptionTemplate::query()->where('is_footer', true)->value('body'));

        if ($footer === '' || ($base !== '' && str_contains($base, $footer))) {
            return $base;
        }

        return $base === '' ? $footer : $base."\n\n".$footer;
    }

    /**
     * @return array<string, array{length: int, limit: int, hashtags: int, hashtagLimit: ?int, mentions: int, mentionLimit: ?int, state: string}>
     */
    public function getCountersProperty(): array
    {
        $validator = new SpecValidator;
        $counters = [];

        foreach ($this->selectedPlatforms() as $platform) {
            $text = $this->effectiveCaption($platform);
            $length = mb_strlen($text);
            $limit = $platform->captionLimit();

            $hashtags = $validator->countHashtags($text);
            $mentions = $validator->countMentions($text);

            $hashtagLimit = $platform->hashtagLimit();
            $mentionLimit = $platform->mentionLimit();

            $over = $length > $limit
                || ($hashtagLimit !== null && $hashtags > $hashtagLimit)
                || ($mentionLimit !== null && $mentions > $mentionLimit);

            $near = ! $over && (
                $length > $limit * 0.9
                || ($hashtagLimit !== null && $hashtags > $hashtagLimit - 3)
                || ($mentionLimit !== null && $mentions > $mentionLimit - 3)
            );

            $counters[$platform->value] = [
                'length' => $length,
                'limit' => $limit,
                'hashtags' => $hashtags,
                'hashtagLimit' => $hashtagLimit,
                'mentions' => $mentions,
                'mentionLimit' => $mentionLimit,
                'state' => $over ? 'over' : ($near ? 'near' : 'fine'),
            ];
        }

        return $counters;
    }

    /**
     * Everything wrong with this post, per platform, right now.
     *
     * @return array<string, list<\App\Services\Media\ValidationVerdict>>
     */
    public function getVerdictsProperty(): array
    {
        $validator = new SpecValidator;
        $media = $this->mediaRows();
        $verdicts = [];

        foreach ($this->selectedPlatforms() as $platform) {
            $found = $validator->validateCaption($this->effectiveCaption($platform), $platform);

            $found = array_merge(
                $found,
                $validator->validateAttachmentCount($media->count(), $this->postType, $platform)
            );

            foreach ($media as $item) {
                if ($item->status !== MediaStatus::Ready) {
                    continue;
                }

                $found = array_merge($found, $validator->validateMedia(
                    new ProbeResult(
                        mime: (string) $item->mime,
                        width: $item->width,
                        height: $item->height,
                        duration: $item->duration_seconds,
                    ),
                    $platform,
                    $this->postType
                ));
            }

            $verdicts[$platform->value] = $found;
        }

        return $verdicts;
    }

    public function getHasBlockingProblemsProperty(): bool
    {
        foreach ($this->verdicts as $found) {
            foreach ($found as $verdict) {
                if ($verdict->isError()) {
                    return true;
                }
            }
        }

        return $this->mediaRows()->contains(fn (PostMedia $m) => $m->status !== MediaStatus::Ready);
    }

    /**
     * @return array{post: Post, similarity: int}|null
     */
    public function getDuplicateProperty(): ?array
    {
        if (mb_strlen(trim($this->caption)) < 40) {
            return null;
        }

        $probe = new Post(['caption' => $this->caption]);
        $probe->id = $this->postId ?? 0;

        return app(DuplicateDetector::class)->findSimilar($probe);
    }

    // =====================================================================
    // Media
    // =====================================================================

    public function addMedia(): void
    {
        $url = trim($this->mediaUrl);

        if ($url === '') {
            return;
        }

        if ($reason = (new UrlResolver)->rejectionReason($url)) {
            $this->addError('mediaUrl', $reason);

            return;
        }

        // Media needs a post to hang from, so an unsaved composer saves first.
        $post = $this->persist(PostStatus::Draft, silent: true);

        $media = PostMedia::create([
            'post_id' => $post->id,
            'position' => $post->media()->count(),
            'source_type' => (new UrlResolver)->isDriveUrl($url)
                ? MediaSourceType::Drive
                : MediaSourceType::Url,
            'source_url' => $url,
            'status' => MediaStatus::Pending,
        ]);

        FetchMediaJob::dispatch($media->id);

        $this->mediaUrl = '';
        $this->resetValidation('mediaUrl');
    }

    /**
     * A file chosen straight from the composer.
     *
     * Fires on selection rather than behind a second button: an upload that
     * needs another click to take effect is one people forget to click.
     */
    public function updatedUpload(MediaIngestor $ingestor): void
    {
        $this->validate([
            'upload' => [
                'required', 'file',
                'mimetypes:image/jpeg,image/png,image/webp,image/gif,video/mp4,video/quicktime',
                'max:512000',
            ],
        ], [
            'upload.mimetypes' => 'Meta accepts JPEG, PNG, WebP, GIF, MP4 and MOV.',
            'upload.max' => 'That file is over 500MB.',
        ]);

        $post = $this->persist(PostStatus::Draft, silent: true);

        $media = PostMedia::create([
            'post_id' => $post->id,
            'position' => $post->media()->count(),
            'source_type' => MediaSourceType::Upload,
            'source_url' => null,
            'status' => MediaStatus::Pending,
        ]);

        // Already on this machine, so it is ingested inline rather than queued:
        // there is nothing to download, and the operator is watching.
        $ingestor->ingestUploadedFile(
            $media,
            $this->upload->getRealPath(),
            $this->upload->getClientOriginalName()
        );

        $this->reset('upload');
    }

    /**
     * Make an attachment fit, rather than only telling someone it does not.
     */
    public function refit(int $mediaId, string $mode, AutoFitService $autoFit): void
    {
        $media = PostMedia::query()->findOrFail($mediaId);

        Gate::authorize('update', Post::query()->findOrFail($media->post_id));

        try {
            $autoFit->refit($media, $this->postType, $mode);
        } catch (\Throwable $exception) {
            $this->addError('media', $exception->getMessage());

            return;
        }

        $this->dispatch('toast', message: $mode === AutoFitService::CROP
            ? 'Cropped to fit.'
            : 'Padded to fit.');
    }

    public function removeMedia(int $mediaId): void
    {
        $media = PostMedia::query()->findOrFail($mediaId);

        $post = Post::query()->findOrFail($media->post_id);
        Gate::authorize('update', $post);

        $media->delete();
    }

    public function refetchMedia(int $mediaId): void
    {
        $media = PostMedia::query()->findOrFail($mediaId);

        $media->forceFill(['status' => MediaStatus::Pending, 'error_message' => null])->save();

        FetchMediaJob::dispatch($media->id);
    }

    // =====================================================================
    // Templates and hashtag sets
    // =====================================================================

    public function insertTemplate(int $templateId): void
    {
        $template = CaptionTemplate::query()->findOrFail($templateId);

        $this->caption = trim($this->caption) === ''
            ? $template->body
            : trim($this->caption)."\n\n".$template->body;
    }

    public function insertHashtagSet(int $setId): void
    {
        $set = HashtagSet::query()->findOrFail($setId);

        $this->caption = trim($this->caption) === ''
            ? $set->toCaptionBlock()
            : trim($this->caption)."\n\n".$set->toCaptionBlock();
    }

    // =====================================================================
    // Saving
    // =====================================================================

    public function saveDraft()
    {
        $this->persist(PostStatus::Draft);

        session()->flash('toast', 'Saved as a draft.');

        return $this->finish();
    }

    public function submitForApproval()
    {
        if (! $this->passesSchedulingChecks()) {
            return;
        }

        $post = $this->persist(PostStatus::PendingApproval);

        Gate::authorize('submit', $post);

        session()->flash('toast', 'Sent for approval.');

        return $this->finish();
    }

    public function schedule()
    {
        if (! $this->passesSchedulingChecks()) {
            return;
        }

        // Only an admin puts something straight into the queue; a user's work
        // goes through approval first.
        $status = auth()->user()->isAdmin()
            ? PostStatus::Scheduled
            : PostStatus::PendingApproval;

        $post = $this->persist($status);

        $when = $post->scheduled_at->copy()->setTimezone($this->timezone());

        session()->flash('toast', $status === PostStatus::Scheduled
            ? 'Scheduled for '.$when->format('D j M, H:i').' '.$this->zoneLabel().'.'
            : 'Sent for approval, to publish '.$when->format('D j M, H:i').' '.$this->zoneLabel().'.');

        return $this->finish();
    }

    public function deletePost()
    {
        if ($this->postId === null) {
            $this->close();

            return;
        }

        $post = Post::query()->findOrFail($this->postId);

        Gate::authorize('delete', $post);

        $post->delete();

        session()->flash('toast', 'Post deleted.');

        return $this->finish();
    }

    /**
     * Everything that must be true before a post may leave the composer.
     *
     * Returns false rather than throwing on a platform-spec failure, because
     * those are already rendered inline against the offending attachment --
     * throwing a second time would just duplicate the message.
     */
    private function passesSchedulingChecks(): bool
    {
        $this->validate([
            'caption' => ['required', 'string', 'min:1'],
            'destinations' => ['required', 'array', 'min:1'],
            'scheduledDate' => ['required', 'date'],
            'scheduledTime' => ['required', 'date_format:H:i'],
        ], [
            'caption.required' => 'Write a caption before scheduling this.',
            'destinations.required' => 'Choose at least one account to publish to.',
            'scheduledTime.date_format' => 'Use a 24-hour time, like 09:00.',
        ]);

        if ($this->hasBlockingProblems) {
            $this->addError('blocking', 'Fix the problems listed above before scheduling.');

            return false;
        }

        return true;
    }

    /**
     * Write the post and its targets. Idempotent: called on every save path.
     */
    private function persist(PostStatus $status, bool $silent = false): Post
    {
        return DB::transaction(function () use ($status): Post {
            $post = $this->postId !== null
                ? Post::query()->findOrFail($this->postId)
                : new Post(['workspace_id' => auth()->user()->workspace_id]);

            if ($post->exists) {
                Gate::authorize('update', $post);
            } else {
                Gate::authorize('create', Post::class);
                $post->created_by = auth()->id();
                $post->source = PostSource::Manual;
            }

            $post->fill([
                'title' => $this->title !== '' ? $this->title : null,
                'caption' => $this->caption,
                'caption_ar' => $this->captionAr !== '' ? $this->captionAr : null,
                'type' => $this->type,
                'first_comment' => $this->firstComment !== '' ? $this->firstComment : null,
                'append_brand_footer' => $this->appendBrandFooter,
                'scheduled_at' => $this->scheduledAtUtc(),
                'status' => $status,
            ]);

            $post->save();

            $this->postId = $post->id;

            $this->syncTargets($post);
            $this->syncOverrides($post);

            app(ActivityLogger::class)->logChanges(
                $post->wasRecentlyCreated ? 'post.created' : 'post.updated',
                $post
            );

            return $post;
        });
    }

    private function syncTargets(Post $post): void
    {
        $allowed = $this->availableAccounts()->pluck('id')->all();
        $wanted = array_values(array_intersect($this->destinations, $allowed));

        $existing = PostTarget::query()->where('post_id', $post->id)->get();

        // Never touch a destination that has already been published to: that
        // row is history, and removing it would erase a permalink.
        $removable = $existing->filter(fn (PostTarget $t) => $t->status->isPending());

        foreach ($removable as $target) {
            if (! in_array($target->social_account_id, $wanted, true)) {
                $target->delete();
            }
        }

        foreach ($wanted as $accountId) {
            PostTarget::query()->firstOrCreate(
                ['post_id' => $post->id, 'social_account_id' => $accountId],
            );
        }
    }

    private function syncOverrides(Post $post): void
    {
        $map = [
            Platform::Facebook->value => $this->splitCaptions ? trim($this->captionFacebook) : '',
            Platform::Instagram->value => $this->splitCaptions ? trim($this->captionInstagram) : '',
        ];

        foreach ($map as $platform => $caption) {
            if ($caption === '') {
                PostPlatformOverride::query()
                    ->where('post_id', $post->id)
                    ->where('platform', $platform)
                    ->delete();

                continue;
            }

            PostPlatformOverride::query()->updateOrCreate(
                ['post_id' => $post->id, 'platform' => $platform],
                ['caption' => $caption],
            );
        }
    }

    private function finish()
    {
        return $this->redirect($this->returnTo(), navigate: true);
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function scheduledAtUtc(): Carbon
    {
        return Carbon::parse(
            $this->scheduledDate.' '.$this->scheduledTime,
            $this->timezone()
        )->utc();
    }

    private function timezone(): string
    {
        return auth()->user()?->displayTimezone() ?? config('gnext.default_timezone');
    }

    private function zoneLabel(): string
    {
        return \App\Support\Zone::label($this->timezone());
    }

    /**
     * @return \Illuminate\Support\Collection<int, SocialAccount>
     */
    private function availableAccounts()
    {
        return SocialAccount::query()->active()->orderBy('name')->get();
    }

    /**
     * @return list<Platform>
     */
    private function selectedPlatforms(): array
    {
        $platforms = $this->availableAccounts()
            ->whereIn('id', $this->destinations)
            ->map(fn (SocialAccount $a) => $a->platform)
            ->unique(fn (Platform $p) => $p->value)
            ->values()
            ->all();

        // With nothing chosen yet, show Instagram limits: they are the strict
        // ones, so a caption written against them is safe everywhere.
        return $platforms !== [] ? $platforms : [Platform::Instagram];
    }

    /**
     * @return \Illuminate\Support\Collection<int, PostMedia>
     */
    private function mediaRows()
    {
        if ($this->postId === null) {
            return collect();
        }

        return PostMedia::query()
            ->where('post_id', $this->postId)
            ->orderBy('position')
            ->get();
    }

    /**
     * Move the schedule to a slot the audience has actually responded to.
     */
    public function useSuggestedSlot(int $isoWeekday, int $hour): void
    {
        $tz = $this->timezone();

        // The next occurrence of that weekday, never one in the past.
        $target = Carbon::parse($this->scheduledDate, $tz)->startOfDay();

        if ($target->isPast()) {
            $target = Carbon::now($tz)->startOfDay();
        }

        while ((int) $target->isoWeekday() !== $isoWeekday || $target->copy()->setTime($hour, 0)->isPast()) {
            $target->addDay();
        }

        $this->scheduledDate = $target->toDateString();
        $this->scheduledTime = sprintf('%02d:00', $hour);
    }

    public function render(\App\Services\ScheduleSuggester $suggester)
    {
        return view('livewire.composer', [
            'accounts' => $this->availableAccounts(),
            'media' => $this->mediaRows(),
            'templates' => CaptionTemplate::query()->where('is_footer', false)->orderBy('name')->get(),
            'hashtagSets' => HashtagSet::query()->orderBy('name')->get(),
            'timezoneLabel' => $this->zoneLabel(),
            // A hint from measured history, not a constraint: the operator
            // knows about the campaign launching on Thursday.
            'suggestedSlots' => $this->open ? $suggester->bestSlots($this->timezone()) : collect(),
        ]);
    }
}
