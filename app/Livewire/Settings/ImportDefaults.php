<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Models\SocialAccount;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Settings > Import defaults. What the wizard assumes before you override it.
 *
 * The date format is a default, never a silent one: the wizard still asks on
 * every import, because the cost of getting it wrong is a month of content on
 * the wrong days.
 */
class ImportDefaults extends Component
{
    public string $defaultTime = '09:00';

    public string $dateFormat = 'DD-MM-YYYY';

    /** @var array<int, int> */
    public array $accountIds = [];

    public function mount(): void
    {
        Gate::authorize('manage-import-defaults');

        $workspace = auth()->user()->workspace;

        $this->defaultTime = (string) $workspace->setting('import.default_time', '09:00');
        $this->dateFormat = (string) $workspace->setting('import.date_format', 'DD-MM-YYYY');
        $this->accountIds = (array) $workspace->setting('import.account_ids', []);
    }

    public function save(ActivityLogger $log): void
    {
        Gate::authorize('manage-import-defaults');

        $this->validate([
            'defaultTime' => ['required', 'date_format:H:i'],
            'dateFormat' => ['required', 'in:DD-MM-YYYY,MM-DD-YYYY'],
        ], [
            'defaultTime.date_format' => 'Use a 24-hour time, like 09:00.',
        ]);

        $workspace = auth()->user()->workspace;

        $workspace->mergeSettings([
            'import' => [
                'default_time' => $this->defaultTime,
                'date_format' => $this->dateFormat,
                'account_ids' => array_values(array_map('intval', $this->accountIds)),
            ],
        ]);

        $log->log('import_defaults.updated', $workspace, [
            'default_time' => $this->defaultTime,
            'date_format' => $this->dateFormat,
        ]);

        $this->dispatch('toast', message: 'Import defaults saved.');
    }

    public function render()
    {
        return view('livewire.settings.import-defaults', [
            'accounts' => SocialAccount::query()->active()->orderBy('name')->get(),
        ]);
    }
}
