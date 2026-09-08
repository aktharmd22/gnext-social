<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\PostStatus;
use App\Enums\PostType;
use App\Models\CalendarEvent;
use App\Models\Post;
use App\Models\SocialAccount;
use App\Models\User;
use App\Services\ActivityLogger;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The calendar. The product, really -- everything else is plumbing.
 *
 * Renders a month from ONE query plus eager loads. Never a query per day cell:
 * a month grid is 42 cells, and an N+1 here is 42 round trips for a page that
 * is opened dozens of times a day.
 */
class Calendar extends Component
{
    #[Url(as: 'v')]
    public string $view = 'month';

    /** Anchor date for the visible period, as Y-m-d. */
    #[Url(as: 'd')]
    public string $anchor = '';

    // ------------------------------------------------------------- filters

    /** @var array<int, string> */
    #[Url(as: 'p')]
    public array $platforms = [];

    /** @var array<int, string> */
    #[Url(as: 's')]
    public array $statuses = [];

    /** @var array<int, string> */
    #[Url(as: 't')]
    public array $types = [];

    #[Url(as: 'by')]
    public ?int $author = null;

    public bool $filtersOpen = false;

    public function mount(): void
    {
        if ($this->anchor === '') {
            $this->anchor = now($this->timezone())->toDateString();
        }
    }

    // =====================================================================
    // Navigation
    // =====================================================================

    public function previous(): void
    {
        $this->anchor = $this->cursor()
            ->sub($this->view === 'week' ? '1 week' : '1 month')
            ->toDateString();
    }

    public function next(): void
    {
        $this->anchor = $this->cursor()
            ->add($this->view === 'week' ? '1 week' : '1 month')
            ->toDateString();
    }

    public function today(): void
    {
        $this->anchor = now($this->timezone())->toDateString();
    }

    public function setView(string $view): void
    {
        $this->view = in_array($view, ['month', 'week', 'list', 'kanban'], true) ? $view : 'month';
    }

    public function clearFilters(): void
    {
        $this->reset(['platforms', 'statuses', 'types', 'author']);
    }

    public function getHasFiltersProperty(): bool
    {
        return $this->platforms !== [] || $this->statuses !== [] || $this->types !== [] || $this->author !== null;
    }

    // =====================================================================
    // Actions
    // =====================================================================

    /**
     * Drag a chip to a new day, or move it with the keyboard.
     *
     * The time of day is preserved: moving a post from Tuesday to Thursday
     * should not silently reschedule it from 19:00 to midnight.
     */
    public function reschedule(int $postId, string $date, ActivityLogger $log): void
    {
        $post = Post::query()->findOrFail($postId);

        Gate::authorize('reschedule', $post);

        $tz = $this->timezone();
        $current = $post->scheduled_at?->copy()->setTimezone($tz);

        $target = Carbon::parse($date, $tz)->setTimeFrom(
            $current ?? Carbon::parse('09:00', $tz)
        );

        $post->forceFill(['scheduled_at' => $target->utc()])->save();

        $log->log('post.rescheduled', $post, [
            'from' => $current?->toIso8601String(),
            'to' => $target->toIso8601String(),
        ]);

        $this->dispatch('toast', message: 'Moved to '.$target->format('D j M, H:i').' '.$this->zoneLabel().'.');
    }

    /**
     * "Fill this gap" opens the composer on the first empty day of the run.
     *
     * The marker itself is a plain link so it can be opened in a new tab; this
     * exists for callers that need to navigate programmatically.
     */
    public function fillGap(string $date)
    {
        return $this->redirect(route('posts.create', ['date' => $date]), navigate: true);
    }

    #[On('posts:changed')]
    public function refreshBoard(): void
    {
        // The render pass picks up the new data; nothing else to do.
    }

    // =====================================================================
    // Data
    // =====================================================================

    public function cursor(): Carbon
    {
        return Carbon::parse($this->anchor, $this->timezone());
    }

    /**
     * @return array{0: Carbon, 1: Carbon} inclusive local-date bounds
     */
    private function range(): array
    {
        $cursor = $this->cursor();

        return match ($this->view) {
            'week' => [
                $cursor->copy()->startOfWeek(Carbon::MONDAY),
                $cursor->copy()->endOfWeek(Carbon::SUNDAY),
            ],
            'list', 'kanban' => [
                $cursor->copy()->startOfMonth(),
                $cursor->copy()->endOfMonth(),
            ],
            default => [
                $cursor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY),
                $cursor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY),
            ],
        };
    }

    /**
     * Every post in the visible period, grouped by local date.
     *
     * One query. The eager loads are what keep the chips from triggering a
     * lookup each, which with lazy loading disabled would throw rather than
     * silently cost 40 queries.
     *
     * @return Collection<string, Collection<int, Post>>
     */
    private function posts(): Collection
    {
        [$from, $to] = $this->range();
        $tz = $this->timezone();

        $query = Post::query()
            ->with(['targets.socialAccount', 'media'])
            ->whereBetween('scheduled_at', [
                $from->copy()->startOfDay()->utc(),
                $to->copy()->endOfDay()->utc(),
            ]);

        if ($this->statuses !== []) {
            $query->whereIn('status', $this->statuses);
        }

        if ($this->types !== []) {
            $query->whereIn('type', $this->types);
        }

        if ($this->author !== null) {
            $query->where('created_by', $this->author);
        }

        if ($this->platforms !== []) {
            $query->whereHas('targets.socialAccount', fn ($q) => $q->whereIn('platform', $this->platforms));
        }

        return $query
            ->orderBy('scheduled_at')
            ->get()
            // Grouped by LOCAL date: a post at 02:00 GST is stored on the
            // previous UTC day and belongs in the cell a human looks in.
            ->groupBy(fn (Post $post) => $post->scheduled_at->copy()->setTimezone($tz)->toDateString());
    }

    /**
     * Runs of three or more consecutive empty days inside the current month.
     *
     * Only within the month being looked at: flagging a gap in the trailing
     * days of the previous month is noise, not insight.
     *
     * @param  Collection<string, Collection<int, Post>>  $posts
     * @return array<string, array{length: int, start: string, end: string}>
     */
    private function gaps(Collection $posts): array
    {
        if ($this->view !== 'month') {
            return [];
        }

        $threshold = (int) config('gnext.composer.gap_threshold_days', 3);
        $cursor = $this->cursor();

        $start = $cursor->copy()->startOfMonth();
        $end = $cursor->copy()->endOfMonth();

        $run = [];
        $gaps = [];

        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();

            if ($posts->has($key) && $posts->get($key)->isNotEmpty()) {
                $this->recordGap($gaps, $run, $threshold);
                $run = [];

                continue;
            }

            $run[] = $key;
        }

        $this->recordGap($gaps, $run, $threshold);

        return $gaps;
    }

    /**
     * @param  array<string, array{length: int, start: string, end: string}>  $gaps
     * @param  list<string>  $run
     */
    private function recordGap(array &$gaps, array $run, int $threshold): void
    {
        if (count($run) < $threshold) {
            return;
        }

        // Keyed on the first empty day, which is where the marker renders.
        $gaps[$run[0]] = [
            'length' => count($run),
            'start' => $run[0],
            'end' => $run[count($run) - 1],
        ];
    }

    /**
     * UAE observances overlapping the visible period, indexed by date.
     *
     * @return array<string, list<CalendarEvent>>
     */
    private function events(): array
    {
        [$from, $to] = $this->range();

        $events = CalendarEvent::query()
            ->active()
            ->overlapping($from, $to)
            ->orderBy('starts_on')
            ->get();

        $byDate = [];

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $matching = $events->filter(fn (CalendarEvent $e) => $e->coversDate($day))->values();

            if ($matching->isNotEmpty()) {
                $byDate[$day->toDateString()] = $matching->all();
            }
        }

        return $byDate;
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function timezone(): string
    {
        return auth()->user()?->displayTimezone() ?? config('gnext.default_timezone');
    }

    private function zoneLabel(): string
    {
        return \App\Support\Zone::label($this->timezone());
    }

    public function render()
    {
        $posts = $this->posts();
        [$from, $to] = $this->range();

        $days = [];
        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            $days[] = $day->copy();
        }

        return view('livewire.calendar', [
            'tz' => $this->timezone(),
            'zone' => $this->zoneLabel(),
            'cursorDate' => $this->cursor(),
            'days' => $days,
            'posts' => $posts,
            'gaps' => $this->gaps($posts),
            'events' => $this->events(),
            'accounts' => SocialAccount::query()->active()->orderBy('name')->get(),
            'authors' => User::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'statusOptions' => PostStatus::cases(),
            'typeOptions' => PostType::cases(),
            'total' => $posts->flatten()->count(),
        ]);
    }
}
