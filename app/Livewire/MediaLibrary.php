<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Enums\MediaStatus;
use App\Jobs\FetchMediaJob;
use App\Models\PostMedia;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Everything ingested, with its platform validation state.
 *
 * Exists mainly for one job: finding the media that failed to fetch, before the
 * post it belongs to reaches its scheduled minute.
 */
class MediaLibrary extends Component
{
    use WithPagination;

    #[Url(as: 'st')]
    public string $status = '';

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function refetch(int $mediaId): void
    {
        $media = PostMedia::query()->findOrFail($mediaId);

        // The post is reachable, so the media is too: authorising through the
        // post keeps one rule rather than two.
        $this->authorize('update', $media->post);

        $media->forceFill([
            'status' => MediaStatus::Pending,
            'error_message' => null,
        ])->save();

        FetchMediaJob::dispatch($media->id);

        $this->dispatch('toast', message: 'Fetching again.');
    }

    public function refetchAllFailed(): void
    {
        $failed = PostMedia::query()->where('status', MediaStatus::Failed->value)->get();

        foreach ($failed as $media) {
            $media->forceFill(['status' => MediaStatus::Pending, 'error_message' => null])->save();
            FetchMediaJob::dispatch($media->id);
        }

        $this->dispatch('toast', message: $failed->count().' '.str('file')->plural($failed->count()).' queued again.');
    }

    public function editPost(int $postId): void
    {
        $this->dispatch('composer:edit', postId: $postId);
    }

    public function render()
    {
        $query = PostMedia::query()
            ->with('post')
            ->whereHas('post')
            ->latest('id');

        if ($this->status !== '') {
            $query->where('status', $this->status);
        }

        return view('livewire.media-library', [
            'media' => $query->paginate(40),
            'failedCount' => PostMedia::query()->where('status', MediaStatus::Failed->value)->count(),
            'statusOptions' => MediaStatus::cases(),
        ]);
    }
}
