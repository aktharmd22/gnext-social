<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\ActivityLog as ActivityLogModel;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Who changed a caption, who approved, who replaced the app secret.
 *
 * Read-only and unfilterable-away: there is no delete, and no way to hide an
 * entry. A log you can edit is not a log.
 */
class ActivityLog extends Component
{
    use WithPagination;

    #[Url(as: 'who')]
    public ?int $userId = null;

    #[Url(as: 'do')]
    public string $action = '';

    public function updated(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->reset(['userId', 'action']);
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('view-activity-log');

        $query = ActivityLogModel::query()
            ->with('user')
            ->latest('created_at');

        if ($this->userId !== null) {
            $query->where('user_id', $this->userId);
        }

        if ($this->action !== '') {
            $query->where('action', $this->action);
        }

        return view('livewire.activity-log', [
            'entries' => $query->paginate(50),
            'people' => User::query()->orderBy('name')->get(['id', 'name']),
            'actions' => ActivityLogModel::query()
                ->distinct()
                ->orderBy('action')
                ->pluck('action'),
            'tz' => auth()->user()->displayTimezone(),
        ]);
    }
}
