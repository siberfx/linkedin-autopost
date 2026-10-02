<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\ComponentAttributeBag;
use Livewire\Component;
use Siberfx\LinkedInAutopost\LinkedInAutopostServiceProvider;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Support\ConnectionPresenter;
use Siberfx\LinkedInAutopost\Support\StatusMessages;
use Siberfx\LinkedInAutopost\Support\Theme;

final class ConnectionCard extends Component
{
    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    public function mount(): void
    {
        $this->flash = StatusMessages::current(request());
    }

    public function disconnect(LinkedInManager $linkedin): void
    {
        Gate::authorize(LinkedInAutopostServiceProvider::ABILITY);

        $revoked = $linkedin->disconnect();

        $this->flash = ['type' => 'success', 'message' => $revoked
            ? 'LinkedIn disconnected.'
            : 'LinkedIn disconnected here, but LinkedIn did not confirm revoking the token.'];
    }

    public function render(LinkedInManager $linkedin): View
    {
        $connection = $linkedin->connection();
        $routes = (string) config('linkedin-autopost.routes.name', 'linkedin-autopost.');

        return view(Theme::view('connection'), [
            'connection' => $connection,
            'presenter' => new ConnectionPresenter($connection),
            'flash' => $this->flash,
            'livewire' => true,
            'attributes' => new ComponentAttributeBag,
            'canManage' => Gate::allows(LinkedInAutopostServiceProvider::ABILITY),
            'connectUrl' => route($routes.'redirect'),
            'disconnectUrl' => route($routes.'connection.destroy'),
        ]);
    }
}
