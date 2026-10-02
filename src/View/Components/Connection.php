<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\Component;
use Siberfx\LinkedInAutopost\LinkedInAutopostServiceProvider;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Support\ConnectionPresenter;
use Siberfx\LinkedInAutopost\Support\StatusMessages;
use Siberfx\LinkedInAutopost\Support\Theme;

final class Connection extends Component
{
    public function render(): View
    {
        $connection = app(LinkedInManager::class)->connection();
        $routes = (string) config('linkedin-autopost.routes.name', 'linkedin-autopost.');

        return view(Theme::view('connection'), [
            'connection' => $connection,
            'presenter' => new ConnectionPresenter($connection),
            'flash' => StatusMessages::current(request()),
            'livewire' => false,
            'canManage' => Gate::allows(LinkedInAutopostServiceProvider::ABILITY),
            'connectUrl' => route($routes.'redirect'),
            'disconnectUrl' => route($routes.'connection.destroy'),
        ]);
    }
}
