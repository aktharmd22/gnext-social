<?php

declare(strict_types=1);

namespace App\Livewire\Settings;

use App\Enums\CalendarEventKind;
use App\Models\CalendarEvent;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Settings > Calendar events. The UAE overlay.
 *
 * Editable because Hijri dates move every year and are confirmed by moon
 * sighting. Nobody should need a deploy to correct an Eid.
 */
class CalendarEvents extends Component
{
    public ?int $editing = null;

    public string $name = '';

    public string $nameAr = '';

    public string $startsOn = '';

    public string $endsOn = '';

    public string $kind = 'observance';

    public bool $isApproximate = false;

    public function mount(): void
    {
        Gate::authorize('manage-calendar-events');
    }

    public function newEvent(): void
    {
        $this->reset(['editing', 'name', 'nameAr', 'startsOn', 'endsOn', 'kind', 'isApproximate']);
        $this->editing = 0;
        $this->kind = CalendarEventKind::Retail->value;
        $this->resetValidation();
    }

    public function edit(int $id): void
    {
        $event = CalendarEvent::query()->findOrFail($id);

        $this->editing = $event->id;
        $this->name = $event->name;
        $this->nameAr = (string) $event->name_ar;
        $this->startsOn = $event->starts_on->toDateString();
        $this->endsOn = $event->ends_on->toDateString();
        $this->kind = $event->kind->value;
        $this->isApproximate = $event->is_approximate;
        $this->resetValidation();
    }

    public function save(ActivityLogger $log): void
    {
        Gate::authorize('manage-calendar-events');

        $this->validate([
            'name' => ['required', 'string', 'max:120'],
            'startsOn' => ['required', 'date'],
            'endsOn' => ['required', 'date', 'after_or_equal:startsOn'],
            'kind' => ['required', 'in:religious,national,retail,season,observance'],
        ], [
            'endsOn.after_or_equal' => 'The end date cannot be before the start.',
        ]);

        $event = $this->editing
            ? CalendarEvent::query()->findOrFail($this->editing)
            : new CalendarEvent(['workspace_id' => auth()->user()->workspace_id]);

        $event->fill([
            'workspace_id' => auth()->user()->workspace_id,
            'name' => $this->name,
            'name_ar' => $this->nameAr !== '' ? $this->nameAr : null,
            'starts_on' => $this->startsOn,
            'ends_on' => $this->endsOn,
            'kind' => $this->kind,
            'is_approximate' => $this->isApproximate,
            'is_active' => true,
        ])->save();

        $log->logChanges('calendar_event.saved', $event);

        $this->cancel();
        $this->dispatch('toast', message: 'Calendar event saved.');
    }

    public function toggleActive(int $id): void
    {
        Gate::authorize('manage-calendar-events');

        $event = CalendarEvent::query()->findOrFail($id);
        $event->forceFill(['is_active' => ! $event->is_active])->save();
    }

    public function delete(int $id, ActivityLogger $log): void
    {
        Gate::authorize('manage-calendar-events');

        $event = CalendarEvent::query()->findOrFail($id);

        $log->log('calendar_event.deleted', $event, ['name' => $event->name]);
        $event->delete();

        $this->dispatch('toast', message: 'Removed from the calendar.');
    }

    public function cancel(): void
    {
        $this->reset(['editing', 'name', 'nameAr', 'startsOn', 'endsOn', 'kind', 'isApproximate']);
    }

    public function render()
    {
        return view('livewire.settings.calendar-events', [
            'events' => CalendarEvent::query()->orderBy('starts_on')->get(),
            'kinds' => CalendarEventKind::cases(),
        ]);
    }
}
