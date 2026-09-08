<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Exports\PostsExport;
use App\Models\CalendarEvent;
use App\Models\Post;
use App\Services\ActivityLogger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Exporting the current view.
 *
 * The filters are read from the query string, which is why the calendar keeps
 * its filters there: "export what I am looking at" then needs no state passed
 * between components.
 */
class ExportController extends Controller
{
    /**
     * XLSX or CSV of the filtered posts.
     */
    public function posts(Request $request, string $format, ActivityLogger $log)
    {
        Gate::authorize('export-posts');

        abort_unless(in_array($format, ['xlsx', 'csv'], true), 404);

        $posts = $this->query($request)->get();

        $log->log('export.posts', null, [
            'format' => $format,
            'rows' => $posts->count(),
        ]);

        $filename = 'gnextsocial-posts-'.now()->format('Y-m-d').'.'.$format;

        return Excel::download(
            new PostsExport($posts, $request->user()->displayTimezone()),
            $filename,
            $format === 'csv'
                ? \Maatwebsite\Excel\Excel::CSV
                : \Maatwebsite\Excel\Excel::XLSX,
        );
    }

    /**
     * A month grid as a PDF, for client sign-off.
     */
    public function calendar(Request $request, ActivityLogger $log)
    {
        Gate::authorize('export-posts');

        $tz = $request->user()->displayTimezone();

        $cursor = $request->filled('month')
            ? Carbon::parse($request->string('month').'-01', $tz)
            : Carbon::now($tz)->startOfMonth();

        $gridStart = $cursor->copy()->startOfMonth()->startOfWeek(Carbon::MONDAY);
        $gridEnd = $cursor->copy()->endOfMonth()->endOfWeek(Carbon::SUNDAY);

        $posts = Post::query()
            ->with(['targets.socialAccount', 'media'])
            ->whereBetween('scheduled_at', [
                $gridStart->copy()->startOfDay()->utc(),
                $gridEnd->copy()->endOfDay()->utc(),
            ])
            ->orderBy('scheduled_at')
            ->get()
            ->groupBy(fn (Post $post) => $post->scheduled_at->copy()->setTimezone($tz)->toDateString());

        $events = CalendarEvent::query()
            ->active()
            ->overlapping($gridStart, $gridEnd)
            ->get();

        $days = [];
        for ($day = $gridStart->copy(); $day->lte($gridEnd); $day->addDay()) {
            $days[] = $day->copy();
        }

        $log->log('export.calendar-pdf', null, ['month' => $cursor->format('Y-m')]);

        $pdf = Pdf::loadView('exports.calendar', [
            'cursor' => $cursor,
            'days' => $days,
            'posts' => $posts,
            'events' => $events,
            'tz' => $tz,
            'workspace' => $request->user()->workspace,
        ])->setPaper('a4', 'landscape');

        return $pdf->download('content-calendar-'.$cursor->format('Y-m').'.pdf');
    }

    /**
     * The same filters the calendar and posts list use.
     */
    private function query(Request $request)
    {
        $query = Post::query()->with([
            'targets.socialAccount',
            'targets.insights',
            'media',
            'creator',
        ]);

        if ($request->filled('from')) {
            $query->where('scheduled_at', '>=', Carbon::parse($request->string('from'))->startOfDay()->utc());
        }

        if ($request->filled('to')) {
            $query->where('scheduled_at', '<=', Carbon::parse($request->string('to'))->endOfDay()->utc());
        }

        if ($request->filled('s')) {
            $query->whereIn('status', (array) $request->input('s'));
        }

        if ($request->filled('t')) {
            $query->whereIn('type', (array) $request->input('t'));
        }

        if ($request->filled('by')) {
            $query->where('created_by', (int) $request->input('by'));
        }

        if ($request->filled('p')) {
            $query->whereHas(
                'targets.socialAccount',
                fn ($q) => $q->whereIn('platform', (array) $request->input('p'))
            );
        }

        /*
         * A user exports their own work; an admin exports everything. Filtered
         * at the query rather than denied outright, so the button does not
         * simply vanish for half the team.
         */
        if (! $request->user()->isAdmin()) {
            $query->where('created_by', $request->user()->id);
        }

        return $query->orderBy('scheduled_at');
    }
}
