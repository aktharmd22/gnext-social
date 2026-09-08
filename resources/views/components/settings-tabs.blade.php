@php
    /*
     * Each tab declares the capability that reaches it, and the strip renders
     * only what this user can actually open. A tab that 403s on click is worse
     * than an absent one.
     *
     * API configuration (Meta app, Connected accounts) is open to both roles by
     * product decision; the rest of settings remains admin-only.
     */
    $tabs = collect([
        ['settings.meta-app', 'Meta app', 'manage-credentials'],
        ['settings.accounts', 'Connected accounts', 'manage-accounts'],
        ['settings.brand', 'Brand', 'manage-brand'],
        ['settings.notifications', 'Notifications', 'manage-notifications'],
        ['settings.import-defaults', 'Import defaults', 'manage-import-defaults'],
        ['settings.users', 'Users', 'manage-users'],
        ['settings.events', 'Calendar events', 'manage-calendar-events'],
    ])->filter(fn (array $tab) => Gate::allows($tab[2]));
@endphp

{{-- A horizontal scroller rather than a wrapping row, so the strip keeps its
     shape at 360px instead of becoming three lines. --}}
<nav class="-mx-4 overflow-x-auto px-4 xl:mx-0 xl:px-0" aria-label="Settings sections">
    <div class="flex min-w-max gap-1 border-b border-ink-100 pb-px">
        @foreach ($tabs as [$route, $label, $ability])
            @php $active = request()->routeIs($route); @endphp
            <a href="{{ route($route) }}"
               @if ($active) aria-current="page" @endif
               class="relative whitespace-nowrap rounded-t-control px-3 py-2.5 text-small font-semibold transition-colors
                      {{ $active ? 'text-signal' : 'text-ink-500 hover:text-ink-900' }}">
                {{ $label }}
                @if ($active)
                    <span aria-hidden="true" class="absolute inset-x-2 -bottom-px h-0.5 rounded-full bg-signal"></span>
                @endif
            </a>
        @endforeach
    </div>
</nav>
