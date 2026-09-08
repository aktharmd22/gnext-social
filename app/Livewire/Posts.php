<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PostSource;
use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\Post;
use App\Models\PostMedia;
use App\Models\PostTarget;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Every post, in a table you can filter, act on in bulk, and export.
 *
 * Bulk actions apply per-post permission checks rather than one blanket check
 * at the top: a writer selecting twelve rows, three of which are a colleague's,
 * should move the nine they own and be told about the three they do not --
 * silently doing nothing, or silently doing everything, are both wrong.
 */
class Posts extends Component
{
    use WithPagination;

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * A single status, not a set.
     *
     * A multi-select rendered as a one-line listbox reads as "0 selected",
     * which tells nobody anything. The calendar carries the full multi-filter
     * panel; this table wants one obvious control.
     */
    #[Url(as: 's')]
    public string $status = '';

    /** @var array<int, string> */
    #[Url(as: 't')]
    public array $types = [];

    #[Url(as: 'by')]
    public ?int $author = null;

    /** @var array<int, int> */
    public array $selected = [];

    public bool $selectPage = false;

    // Bulk action inputs
    public int $shiftDays = 7;

    public string $bulkTime = '09:00';

    public function updated($property): void
    {
        if (in_array($property, ['search', 'status', 'types', 'author'], true)) {
            $this->resetPage();
            $this->selected = [];
            $this->selectPage = false;
        }
    }

    public function updatedSelectPage(bool $value): void
    {
        $this->selected = $value
            ? $this->query()->pluck('id')->all()
            : [];
    }

    public function clearFilters(): void
    {
        $this->reset(['search', 'status', 'types', 'author']);
        $this->resetPage();
    }

    #[On('posts:changed')]
    public function refreshList(): void
    {
        $this->selected = [];
        $this->selectPage = false;
    }

    // =====================================================================
    // Bulk actions
    // =====================================================================

    /**
     * Move everything selected by a whole number of days, keeping the time.
     */
    public function shiftSelected(ActivityLogger $log): void
    {
        $this->applyToSelected('post.bulk_shifted', function (Post $post) {
            if (! Gate::allows('reschedule', $post) || $post->scheduled_at === null) {
                return false;
            }

            $post->forceFill([
                'scheduled_at' => $post->scheduled_at->copy()->addDays($this->shiftDays),
            ])->save();

            return true;
        }, $log, ['days' => $this->shiftDays]);
    }

    /**
     * Set everything selected to the same time of day, keeping each date.
     */
    public function retimeSelected(ActivityLogger $log): void
    {
        if (preg_match('/^\d{2}:\d{2}$/', $this->bulkTime) !== 1) {
            $this->addError('bulkTime', 'Use a 24-hour time, like 09:00.');

            return;
        }

        $tz = auth()->user()->displayTimezone();
        [$hour, $minute] = array_map('intval', explode(':', $this->bulkTime));

        $this->applyToSelected('post.bulk_retimed', function (Post $post) use ($tz, $hour, $minute) {
            if (! Gate::allows('reschedule', $post) || $post->scheduled_at === null) {
                return false;
            }

            $local = $post->scheduled_at->copy()->setTimezone($tz)->setTime($hour, $minute);

            $post->forceFill(['scheduled_at' => $local->utc()])->save();

            return true;
        }, $log, ['time' => $this->bulkTime]);
    }

    public function approveSelected(ActivityLogger $log): void
    {
        $this->applyToSelected('post.bulk_approved', function (Post $post) {
            if (! Gate::allows('approve', $post) || $post->status !== PostStatus::PendingApproval) {
                return false;
            }

            $post->forceFill([
                'status' => PostStatus::Approved,
                'approved_by' => auth()->id(),
                'approved_at' => now(),
                'rejection_note' => null,
            ])->save();

            return true;
        }, $log);
    }

    /**
     * Copy everything selected a month forward, as drafts.
     *
     * Drafts on purpose: duplicating a month of content straight into the queue
     * would publish it unreviewed.
     */
    public function duplicateSelected(ActivityLogger $log): void
    {
        $copied = 0;
        $skipped = 0;

        DB::transaction(function () use (&$copied, &$skipped): void {
            foreach ($this->selectedPosts() as $post) {
                if (! Gate::allows('view', $post)) {
                    $skipped++;

                    continue;
                }

                $this->duplicate($post, $post->scheduled_at?->copy()->addMonth());
                $copied++;
            }
        });

        $log->log('post.bulk_duplicated', null, ['count' => $copied]);

        $this->finishBulk($copied, $skipped, 'duplicated into next month');
    }

    /**
     * Recycle one published post into a future slot.
     */
    public function recycle(int $postId, ActivityLogger $log)
    {
        $post = Post::query()->findOrFail($postId);

        Gate::authorize('view', $post);

        $copy = $this->duplicate($post, now()->addWeek());

        $log->log('post.recycled', $copy, ['from' => $post->id]);

        session()->flash('toast', 'Copied into a new draft a week out. Adjust it and schedule.');

        return $this->redirect(
            route('posts.edit', ['post' => $copy, 'from' => 'posts']),
            navigate: true,
        );
    }

    public function deleteSelected(ActivityLogger $log): void
    {
        $this->applyToSelected('post.bulk_deleted', function (Post $post) {
            if (! Gate::allows('delete', $post)) {
                return false;
            }

            $post->delete();

            return true;
        }, $log);
    }

    /**
     * @param  \Closure(Post): bool  $action
     * @param  array<string, mixed>  $context
     */
    private function applyToSelected(string $logAction, \Closure $action, ActivityLogger $log, array $context = []): void
    {
        $changed = 0;
        $skipped = 0;

        foreach ($this->selectedPosts() as $post) {
            $action($post) ? $changed++ : $skipped++;
        }

        $log->log($logAction, null, array_merge($context, ['count' => $changed]));

        $this->finishBulk($changed, $skipped, match ($logAction) {
            'post.bulk_shifted' => 'moved',
            'post.bulk_retimed' => 'retimed',
            'post.bulk_approved' => 'approved',
            'post.bulk_deleted' => 'deleted',
            default => 'updated',
        });
    }

    private function finishBulk(int $changed, int $skipped, string $verb): void
    {
        $this->selected = [];
        $this->selectPage = false;

        $this->dispatch('posts:changed');

        // Say what did NOT happen, and why, rather than reporting a clean
        // success over a partial one.
        $this->dispatch('toast', message: $skipped === 0
            ? $changed.' '.str('post')->plural($changed).' '.$verb.'.'
            : $changed.' '.$verb.', '.$skipped.' skipped (already published, or not yours).');
    }

    private function duplicate(Post $post, ?Carbon $when): Post
    {
        $post->loadMissing(['media', 'targets', 'overrides']);

        $copy = Post::create([
            'workspace_id' => $post->workspace_id,
            'title' => $post->title,
            'caption' => $post->caption,
            'caption_ar' => $post->caption_ar,
            'type' => $post->type,
            'first_comment' => $post->first_comment,
            'append_brand_footer' => $post->append_brand_footer,
            'scheduled_at' => $when,
            'status' => PostStatus::Draft,
            'created_by' => auth()->id(),
            'source' => PostSource::Duplicate,
        ]);

        foreach ($post->targets as $target) {
            PostTarget::create([
                'post_id' => $copy->id,
                'social_account_id' => $target->social_account_id,
            ]);
        }

        // Media is copied by reference to the already-fetched file: it is the
        // same asset, and re-downloading it would be pointless.
        foreach ($post->media as $media) {
            PostMedia::create([
                'post_id' => $copy->id,
                'position' => $media->position,
                'source_type' => $media->source_type,
                'source_url' => $media->source_url,
                'disk' => $media->disk,
                'stored_path' => $media->stored_path,
                'public_url' => $media->public_url,
                'thumbnail_path' => $media->thumbnail_path,
                'mime' => $media->mime,
                'size_bytes' => $media->size_bytes,
                'width' => $media->width,
                'height' => $media->height,
                'duration_seconds' => $media->duration_seconds,
                'status' => $media->status,
            ]);
        }

        return $copy;
    }

    /**
     * @return \Illuminate\Support\Collection<int, Post>
     */
    private function selectedPosts()
    {
        return Post::query()->whereIn('id', $this->selected)->get();
    }

    private function query()
    {
        $query = Post::query()
            ->with(['targets.socialAccount', 'media', 'creator'])
            ->orderByDesc('scheduled_at');

        if ($this->search !== '') {
            $term = '%'.$this->search.'%';

            $query->where(fn ($q) => $q
                ->where('title', 'like', $term)
                ->orWhere('caption', 'like', $term)
                ->orWhere('caption_ar', 'like', $term));
        }

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        if ($this->types !== []) {
            $query->whereIn('type', $this->types);
        }

        if ($this->author !== null) {
            $query->where('created_by', $this->author);
        }

        return $query;
    }

    public function render()
    {
        return view('livewire.posts', [
            'posts' => $this->query()->paginate(50),
            'tz' => auth()->user()->displayTimezone(),
            'statusOptions' => PostStatus::cases(),
            'typeOptions' => PostType::cases(),
            'authors' => User::query()->orderBy('name')->get(['id', 'name']),
        ]);
    }
}
