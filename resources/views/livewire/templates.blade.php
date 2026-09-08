<div class="grid min-w-0 gap-4 xl:grid-cols-2">

    {{-- ================================================================= --}}
    {{-- Caption templates                                                 --}}
    {{-- ================================================================= --}}
    <x-card title="Caption templates" subtitle="Reusable copy, and the brand footer.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" wire:click="newTemplate">New template</x-button>
        </x-slot:actions>

        @if ($editingTemplate !== null)
            <div class="mb-4 space-y-3 rounded-control border border-signal-edge bg-signal-weak p-3">
                <x-field label="Name" name="templateName" required>
                    <x-slot:control>
                        <input id="templateName" type="text" wire:model="templateName"
                               placeholder="Weekend offer"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Body" name="templateBody" required
                         hint="Use {placeholders} for the bits that change.">
                    <x-slot:control>
                        <textarea id="templateBody" wire:model="templateBody" rows="4" dir="auto"
                                  class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                         text-ink-900 focus:border-signal focus:outline-none"></textarea>
                    </x-slot:control>
                </x-field>

                <label class="flex items-start gap-2.5">
                    <input type="checkbox" wire:model="templateIsFooter"
                           class="mt-0.5 size-4 rounded border-ink-300 text-signal focus:ring-signal">
                    <span class="text-small text-ink-700">
                        This is the brand footer
                        <span class="block text-ink-500">
                            Appended when a post publishes. Only one template can be the footer,
                            so setting this unsets any other.
                        </span>
                    </span>
                </label>

                <div class="flex gap-2">
                    <x-button variant="primary" size="sm" wire:click="saveTemplate">Save</x-button>
                    <x-button variant="ghost" size="sm" wire:click="cancelTemplate">Cancel</x-button>
                </div>
            </div>
        @endif

        <div class="space-y-2">
            @forelse ($templates as $template)
                <div wire:key="tpl-{{ $template->id }}"
                     class="rounded-control border border-ink-200 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-small font-semibold text-ink-900">{{ $template->name }}</span>

                        @if ($template->is_footer)
                            <span class="rounded-full bg-scheduled-soft px-2 py-[2px] text-micro font-semibold text-scheduled">
                                Brand footer
                            </span>
                        @endif

                        <span class="ml-auto flex gap-1">
                            <button type="button" wire:click="editTemplate({{ $template->id }})"
                                    class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">Edit</button>
                            <button type="button" wire:click="deleteTemplate({{ $template->id }})"
                                    wire:confirm="Delete this template?"
                                    class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-failed">Delete</button>
                        </span>
                    </div>

                    <p class="mt-1.5 whitespace-pre-line break-words text-small text-ink-500" dir="auto">{{ Str::limit($template->body, 160) }}</p>
                </div>
            @empty
                <x-empty-state
                    title="No templates yet"
                    body="Save a caption you reuse, or the footer that goes on every post."
                    icon="templates" />
            @endforelse
        </div>
    </x-card>

    {{-- ================================================================= --}}
    {{-- Hashtag sets                                                      --}}
    {{-- ================================================================= --}}
    <x-card title="Hashtag sets" subtitle="Inserted into a caption in one click.">
        <x-slot:actions>
            <x-button variant="secondary" size="sm" wire:click="newSet">New set</x-button>
        </x-slot:actions>

        @if ($editingSet !== null)
            <div class="mb-4 space-y-3 rounded-control border border-signal-edge bg-signal-weak p-3">
                <x-field label="Name" name="setName" required>
                    <x-slot:control>
                        <input id="setName" type="text" wire:model="setName" placeholder="UAE general"
                               class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                      text-ink-900 focus:border-signal focus:outline-none">
                    </x-slot:control>
                </x-field>

                <x-field label="Hashtags" name="setTags" required
                         hint="Separated by spaces or commas. The # is optional.">
                    <x-slot:control>
                        <textarea id="setTags" wire:model="setTags" rows="3"
                                  placeholder="#dubai #uae #mydubai"
                                  class="w-full rounded-control border border-ink-200 bg-white px-3 py-2 text-small
                                         text-ink-900 focus:border-signal focus:outline-none"></textarea>
                    </x-slot:control>
                </x-field>

                <div class="flex gap-2">
                    <x-button variant="primary" size="sm" wire:click="saveSet">Save</x-button>
                    <x-button variant="ghost" size="sm" wire:click="cancelSet">Cancel</x-button>
                </div>
            </div>
        @endif

        <div class="space-y-2">
            @forelse ($sets as $set)
                <div wire:key="set-{{ $set->id }}" class="rounded-control border border-ink-200 p-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <span class="text-small font-semibold text-ink-900">{{ $set->name }}</span>
                        <span class="text-micro text-ink-500" data-numeric>
                            {{ $set->count() }} {{ Str::plural('tag', $set->count()) }}
                        </span>

                        <span class="ml-auto flex gap-1">
                            <button type="button" wire:click="editSet({{ $set->id }})"
                                    class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-ink-900">Edit</button>
                            <button type="button" wire:click="deleteSet({{ $set->id }})"
                                    wire:confirm="Delete this hashtag set?"
                                    class="rounded-control px-2 py-1 text-micro font-semibold text-ink-500 hover:bg-ink-050 hover:text-failed">Delete</button>
                        </span>
                    </div>

                    <p class="mt-1.5 break-words text-small text-ink-500">{{ $set->toCaptionBlock() }}</p>
                </div>
            @empty
                <x-empty-state
                    title="No hashtag sets yet"
                    body="Group the tags you use together so they go in with one click."
                    icon="templates" />
            @endforelse
        </div>
    </x-card>
</div>
