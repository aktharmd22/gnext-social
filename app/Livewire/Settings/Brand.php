<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Settings > Brand. The workspace name, its timezone, and where the footer
 * lives.
 */
class Brand extends Component
{
    public string $name = '';

    public string $timezone = '';

    public function mount(): void
    {
        Gate::authorize('manage-brand');

        $workspace = auth()->user()->workspace;

        $this->name = (string) $workspace?->name;
        $this->timezone = (string) $workspace?->timezone;
    }

    public function save(ActivityLogger $log): void
    {
        Gate::authorize('manage-brand');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'timezone' => ['required', 'string', 'timezone'],
        ], [
            'timezone.timezone' => 'That is not a timezone identifier. Use something like Asia/Dubai.',
        ]);

        $workspace = auth()->user()->workspace;

        $workspace->fill(['name' => $this->name, 'timezone' => $this->timezone])->save();

        $log->logChanges('workspace.updated', $workspace);

        $this->dispatch('toast', message: 'Brand settings saved.');
    }

    public function render()
    {
        return view('livewire.settings.brand', [
            // A short, curated list beats 400 zone identifiers in a dropdown.
            'zones' => [
                'Asia/Dubai', 'Asia/Riyadh', 'Asia/Qatar', 'Asia/Kuwait',
                'Asia/Karachi', 'Asia/Kolkata', 'Europe/London', 'UTC',
            ],
            'footer' => \App\Models\CaptionTemplate::query()->where('is_footer', true)->first(),
        ]);
    }
}
