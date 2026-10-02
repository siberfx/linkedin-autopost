@php
    $badge = $presenter->badge();
    $tone = ['success' => 'text-bg-success', 'warning' => 'text-bg-warning', 'danger' => 'text-bg-danger', 'neutral' => 'text-bg-secondary'][$badge['tone']];
    $confirmText = 'Disconnect LinkedIn? The token is revoked and nothing posts until you connect again.';
@endphp
<div {{ ($attributes ?? new \Illuminate\View\ComponentAttributeBag)->class('card') }}>
    <div class="card-header d-flex align-items-center gap-2 fw-semibold">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#0A66C2" aria-hidden="true"><path d="M20.45 20.45h-3.56v-5.57c0-1.33-.02-3.04-1.85-3.04-1.86 0-2.14 1.45-2.14 2.95v5.66H9.35V9h3.41v1.56h.05c.48-.9 1.64-1.85 3.37-1.85 3.6 0 4.27 2.37 4.27 5.46v6.28ZM5.34 7.43a2.06 2.06 0 1 1 0-4.13 2.06 2.06 0 0 1 0 4.13ZM7.12 20.45H3.56V9h3.56v11.45ZM22.22 0H1.77C.79 0 0 .77 0 1.73v20.54C0 23.23.79 24 1.77 24h20.45c.98 0 1.78-.77 1.78-1.73V1.73C24 .77 23.2 0 22.22 0Z"/></svg>
        LinkedIn
    </div>
    <div class="card-body">
        @include('linkedin-autopost::bootstrap.flash', ['flash' => $flash])

        @if (! $connection->connected)
            <div class="d-flex flex-wrap align-items-center justify-content-between gap-3">
                <div>
                    <div class="fw-medium">Not connected</div>
                    <div class="text-body-secondary small">Connect a LinkedIn account to share your content.</div>
                </div>
                <a href="{{ $connectUrl }}" class="btn btn-primary">Connect LinkedIn</a>
            </div>
        @else
            <div class="d-flex flex-wrap align-items-center gap-3">
                @if ($connection->picture)
                    <img src="{{ $connection->picture }}" alt="" width="48" height="48" class="rounded-circle object-fit-cover" referrerpolicy="no-referrer">
                @else
                    <span aria-hidden="true" class="d-inline-flex align-items-center justify-content-center rounded-circle bg-primary-subtle text-primary fw-semibold" style="width:48px;height:48px">{{ $presenter->initials() }}</span>
                @endif

                <div class="flex-grow-1" style="min-width:0">
                    <div class="d-flex flex-wrap align-items-center gap-2">
                        <span class="fw-semibold text-truncate">{{ $presenter->displayName() }}</span>
                        <span class="badge {{ $tone }}">{{ $badge['label'] }}</span>
                    </div>
                    @if ($connection->email)
                        <div class="text-body-secondary small text-truncate">{{ $connection->email }}</div>
                    @endif
                    <div class="text-body-secondary small mt-1">
                        @if ($presenter->connectedOn()) Connected {{ $presenter->connectedOn() }} @endif
                        @if ($presenter->connectedOn() && $presenter->validUntil()) · @endif
                        @if ($presenter->validUntil()) Valid until {{ $presenter->validUntil() }} @endif
                    </div>
                </div>

                <div class="d-flex gap-2">
                    <a href="{{ $connectUrl }}" class="btn {{ $presenter->needsReconnect() ? 'btn-primary' : 'btn-outline-secondary' }}">Reconnect</a>
                    @if ($livewire)
                        <button type="button" class="btn btn-outline-danger" wire:click="disconnect" wire:confirm="{{ $confirmText }}">Disconnect</button>
                    @else
                        <form method="POST" action="{{ $disconnectUrl }}" onsubmit="return confirm(@js($confirmText))">
                            @csrf
                            @method('DELETE')
                            <button type="submit" class="btn btn-outline-danger">Disconnect</button>
                        </form>
                    @endif
                </div>
            </div>

            @if ($connection->status === 'unknown')
                <div class="text-body-secondary small mt-2">LinkedIn could not be asked about this token just now; it may still work.</div>
            @endif
        @endif
    </div>
</div>
