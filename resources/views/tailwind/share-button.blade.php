@php
    $reason = $presenter->disabledReason();
    $classes = ($size === 'sm' ? 'px-2.5 py-1.5 text-xs' : 'px-3 py-2 text-sm')
        .' inline-flex items-center gap-1.5 rounded-lg border border-gray-300 font-semibold text-gray-700 hover:bg-gray-50 disabled:cursor-not-allowed disabled:opacity-50 dark:border-gray-600 dark:text-gray-200 dark:hover:bg-gray-800';
@endphp
<span class="inline-flex flex-col items-start gap-1">
    @if ($livewire)
        <button type="button" wire:click="share" @if ($confirm && ! $reason) wire:confirm="{{ $presenter->confirmText() }}" @endif
                @disabled($reason) title="{{ $presenter->title() }}" wire:loading.attr="disabled" class="{{ $classes }}">
            <svg class="size-4 text-[#0A66C2]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.95v5.66H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z"/></svg>
            <span>{{ $presenter->text() }}</span>
        </button>
    @else
        <form method="POST" action="{{ $presenter->signedAction() }}" class="inline" @if ($confirm && ! $reason) onsubmit="return confirm(@js($presenter->confirmText()))" @endif>
            @csrf
            <button type="submit" @disabled($reason) title="{{ $presenter->title() }}" class="{{ $classes }}">
                <svg class="size-4 text-[#0A66C2]" viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.95v5.66H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z"/></svg>
                <span>{{ $presenter->text() }}</span>
            </button>
        </form>
    @endif
    @if ($reason)
        <span class="sr-only">{{ $reason }}</span>
    @endif
    @if ($flash)
        <span class="text-xs {{ $flash['type'] === 'error' ? 'text-red-600 dark:text-red-400' : 'text-green-700 dark:text-green-400' }}">{{ $flash['message'] }}</span>
    @endif
</span>
