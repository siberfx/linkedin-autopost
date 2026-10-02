@php
    $badge = $presenter->badge();
    $tone = [
        'success' => 'bg-green-50 text-green-700 ring-green-600/20 dark:bg-green-950 dark:text-green-300',
        'warning' => 'bg-amber-50 text-amber-800 ring-amber-600/20 dark:bg-amber-950 dark:text-amber-300',
        'danger' => 'bg-red-50 text-red-700 ring-red-600/20 dark:bg-red-950 dark:text-red-300',
        'neutral' => 'bg-gray-50 text-gray-600 ring-gray-500/20 dark:bg-gray-800 dark:text-gray-300',
    ][$badge['tone']];
    $confirmText = 'Disconnect LinkedIn? The token is revoked and nothing posts until you connect again.';
@endphp
<div {{ ($attributes ?? new \Illuminate\View\ComponentAttributeBag)->class('space-y-4 rounded-xl border border-gray-200 bg-white p-5 shadow-sm dark:border-gray-700 dark:bg-gray-900') }}>
    <div class="flex items-center gap-2 text-sm font-semibold text-gray-900 dark:text-gray-100">
        <svg class="size-5 text-[#0A66C2]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.95v5.66H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z"/></svg>
        LinkedIn
    </div>

    @include('linkedin-autopost::tailwind.flash', ['flash' => $flash])

    @if (! $connection->connected)
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div>
                <p class="text-sm font-medium text-gray-900 dark:text-gray-100">Not connected</p>
                <p class="text-sm text-gray-500 dark:text-gray-400">Connect a LinkedIn account to share your content.</p>
            </div>
            <a href="{{ $connectUrl }}" class="inline-flex items-center rounded-lg bg-[#0A66C2] px-4 py-2 text-sm font-semibold text-white hover:bg-[#004182] focus:outline-none focus-visible:ring-2 focus-visible:ring-[#0A66C2] focus-visible:ring-offset-2">Connect LinkedIn</a>
        </div>
    @else
        <div class="flex flex-wrap items-center gap-4">
            @if ($connection->picture)
                <img src="{{ $connection->picture }}" alt="" class="size-12 rounded-full object-cover" referrerpolicy="no-referrer">
            @else
                <span aria-hidden="true" class="flex size-12 items-center justify-center rounded-full bg-blue-50 text-sm font-semibold text-[#0A66C2] dark:bg-blue-950">{{ $presenter->initials() }}</span>
            @endif

            <div class="min-w-0 flex-1">
                <div class="flex flex-wrap items-center gap-2">
                    <p class="truncate text-sm font-semibold text-gray-900 dark:text-gray-100">{{ $presenter->displayName() }}</p>
                    <span class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium ring-1 ring-inset {{ $tone }}">{{ $badge['label'] }}</span>
                </div>
                @if ($connection->email)
                    <p class="truncate text-sm text-gray-500 dark:text-gray-400">{{ $connection->email }}</p>
                @endif
                <p class="mt-1 text-xs text-gray-500 dark:text-gray-400">
                    @if ($presenter->connectedOn()) Connected {{ $presenter->connectedOn() }} @endif
                    @if ($presenter->connectedOn() && $presenter->validUntil()) · @endif
                    @if ($presenter->validUntil()) Valid until {{ $presenter->validUntil() }} @endif
                </p>
            </div>

            <div class="flex gap-2">
                <a href="{{ $connectUrl }}" class="inline-flex items-center rounded-lg px-3 py-2 text-sm font-semibold {{ $presenter->needsReconnect() ? 'bg-[#0A66C2] text-white hover:bg-[#004182]' : 'border border-gray-300 text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800' }}">Reconnect</a>

                @if ($livewire)
                    <button type="button" wire:click="disconnect" wire:confirm="{{ $confirmText }}"
                            class="inline-flex items-center rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950">Disconnect</button>
                @else
                    <form method="POST" action="{{ $disconnectUrl }}" onsubmit="return confirm(@js($confirmText))">
                        @csrf
                        @method('DELETE')
                        <button type="submit" class="inline-flex items-center rounded-lg border border-red-300 px-3 py-2 text-sm font-semibold text-red-700 hover:bg-red-50 dark:border-red-800 dark:text-red-300 dark:hover:bg-red-950">Disconnect</button>
                    </form>
                @endif
            </div>
        </div>

        @if ($connection->status === 'unknown')
            <p class="text-xs text-gray-500 dark:text-gray-400">LinkedIn could not be asked about this token just now; it may still work.</p>
        @endif
    @endif
</div>
