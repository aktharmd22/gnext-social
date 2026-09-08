{{--
    Transient confirmations. Anything that must persist -- an expiring token, a
    failed publish -- is a banner, not one of these.
--}}

<div x-data="{
        items: [],
        push(message) {
            const id = Date.now() + Math.random();
            this.items.push({ id, message });
            setTimeout(() => this.items = this.items.filter(i => i.id !== id), 4500);
        }
     }"
     x-on:toast.window="push($event.detail.message)"
     x-init="@if (session('toast')) push(@js(session('toast'))) @endif"
     class="pointer-events-none fixed bottom-4 left-1/2 z-[60] flex w-[min(24rem,calc(100vw-2rem))] -translate-x-1/2 flex-col gap-2
            md:bottom-6 md:left-auto md:right-6 md:translate-x-0"
     aria-live="polite" aria-atomic="true">

    <template x-for="item in items" :key="item.id">
        <div x-transition:enter="transition ease-out duration-200"
             x-transition:enter-start="translate-y-2 opacity-0"
             x-transition:enter-end="translate-y-0 opacity-100"
             x-transition:leave="transition ease-in duration-150"
             x-transition:leave-end="opacity-0"
             class="pointer-events-auto flex items-start gap-2.5 rounded-card border border-ink-200 bg-ink-900 px-3.5 py-2.5 shadow-drawer">
            <span aria-hidden="true" class="mt-px text-published">●</span>
            <span class="text-small text-white" x-text="item.message"></span>
        </div>
    </template>
</div>
