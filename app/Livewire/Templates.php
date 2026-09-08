<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\CaptionTemplate;
use App\Models\HashtagSet;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Caption templates, the brand footer, and hashtag sets.
 *
 * The footer is singular by design: exactly one template per workspace is the
 * footer, because "append the brand footer" has to mean something unambiguous
 * at publish time.
 */
class Templates extends Component
{
    public ?int $editingTemplate = null;

    public string $templateName = '';

    public string $templateBody = '';

    public bool $templateIsFooter = false;

    public ?int $editingSet = null;

    public string $setName = '';

    public string $setTags = '';

    public function mount(): void
    {
        Gate::authorize('manage-templates');
    }

    // ============================================================ templates

    public function newTemplate(): void
    {
        $this->reset(['editingTemplate', 'templateName', 'templateBody', 'templateIsFooter']);
        $this->editingTemplate = 0;
        $this->resetValidation();
    }

    public function editTemplate(int $id): void
    {
        $template = CaptionTemplate::query()->findOrFail($id);

        $this->editingTemplate = $template->id;
        $this->templateName = $template->name;
        $this->templateBody = $template->body;
        $this->templateIsFooter = $template->is_footer;
        $this->resetValidation();
    }

    public function saveTemplate(ActivityLogger $log): void
    {
        Gate::authorize('manage-templates');

        $this->validate([
            'templateName' => ['required', 'string', 'max:120'],
            'templateBody' => ['required', 'string', 'max:2200'],
        ], [
            'templateName.required' => 'Give it a name you will recognise in the composer.',
            'templateBody.required' => 'A template needs some text.',
        ]);

        $template = $this->editingTemplate
            ? CaptionTemplate::query()->findOrFail($this->editingTemplate)
            : new CaptionTemplate(['workspace_id' => auth()->user()->workspace_id]);

        $template->fill([
            'workspace_id' => auth()->user()->workspace_id,
            'name' => $this->templateName,
            'body' => $this->templateBody,
            'is_footer' => $this->templateIsFooter,
        ]);

        $template->save();

        // Exactly one footer, or "append the brand footer" is ambiguous.
        if ($this->templateIsFooter) {
            CaptionTemplate::query()
                ->where('id', '!=', $template->id)
                ->update(['is_footer' => false]);
        }

        $log->logChanges('template.saved', $template);

        $this->cancelTemplate();
        $this->dispatch('toast', message: 'Template saved.');
    }

    public function deleteTemplate(int $id, ActivityLogger $log): void
    {
        Gate::authorize('manage-templates');

        $template = CaptionTemplate::query()->findOrFail($id);

        $log->log('template.deleted', $template, ['name' => $template->name]);
        $template->delete();

        $this->dispatch('toast', message: 'Template deleted.');
    }

    public function cancelTemplate(): void
    {
        $this->reset(['editingTemplate', 'templateName', 'templateBody', 'templateIsFooter']);
    }

    // ========================================================= hashtag sets

    public function newSet(): void
    {
        $this->reset(['editingSet', 'setName', 'setTags']);
        $this->editingSet = 0;
        $this->resetValidation();
    }

    public function editSet(int $id): void
    {
        $set = HashtagSet::query()->findOrFail($id);

        $this->editingSet = $set->id;
        $this->setName = $set->name;
        $this->setTags = implode(' ', array_map(fn ($t) => '#'.ltrim($t, '#'), $set->tags ?? []));
        $this->resetValidation();
    }

    public function saveSet(ActivityLogger $log): void
    {
        Gate::authorize('manage-templates');

        $this->validate([
            'setName' => ['required', 'string', 'max:120'],
            'setTags' => ['required', 'string', 'max:2000'],
        ]);

        $tags = $this->parseTags($this->setTags);

        if ($tags === []) {
            $this->addError('setTags', 'Add at least one hashtag.');

            return;
        }

        if (count($tags) > 30) {
            $this->addError('setTags', sprintf(
                'That is %d hashtags. Instagram rejects a caption with more than 30, so a set this big can never be used whole.',
                count($tags)
            ));

            return;
        }

        $set = $this->editingSet
            ? HashtagSet::query()->findOrFail($this->editingSet)
            : new HashtagSet(['workspace_id' => auth()->user()->workspace_id]);

        $set->fill([
            'workspace_id' => auth()->user()->workspace_id,
            'name' => $this->setName,
            'tags' => $tags,
        ])->save();

        $log->log('hashtag_set.saved', $set, ['name' => $set->name, 'count' => count($tags)]);

        $this->cancelSet();
        $this->dispatch('toast', message: 'Hashtag set saved.');
    }

    public function deleteSet(int $id, ActivityLogger $log): void
    {
        Gate::authorize('manage-templates');

        $set = HashtagSet::query()->findOrFail($id);

        $log->log('hashtag_set.deleted', $set, ['name' => $set->name]);
        $set->delete();

        $this->dispatch('toast', message: 'Hashtag set deleted.');
    }

    public function cancelSet(): void
    {
        $this->reset(['editingSet', 'setName', 'setTags']);
    }

    /**
     * Accepts "#dubai #uae", "dubai, uae" or a mix, and normalises to bare tags.
     *
     * @return list<string>
     */
    private function parseTags(string $input): array
    {
        $parts = preg_split('/[\s,]+/u', trim($input)) ?: [];

        return collect($parts)
            ->map(fn (string $tag) => ltrim(trim($tag), '#'))
            ->filter(fn (string $tag) => $tag !== '' && preg_match('/^[\p{L}\p{N}_]+$/u', $tag) === 1)
            ->unique()
            ->values()
            ->all();
    }

    public function render()
    {
        return view('livewire.templates', [
            'templates' => CaptionTemplate::query()->orderByDesc('is_footer')->orderBy('name')->get(),
            'sets' => HashtagSet::query()->orderBy('name')->get(),
        ]);
    }
}
