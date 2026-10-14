<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\ComponentAttributeBag;
use InvalidArgumentException;
use Livewire\Component;
use Siberfx\LinkedInAutopost\Data\Page;
use Siberfx\LinkedInAutopost\Exceptions\NotConnected;
use Siberfx\LinkedInAutopost\LinkedInAutopostServiceProvider;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Support\ConnectionPresenter;
use Siberfx\LinkedInAutopost\Support\StatusMessages;
use Siberfx\LinkedInAutopost\Support\Theme;

final class ConnectionCard extends Component
{
    /** @var array{type: string, message: string}|null */
    public ?array $flash = null;

    /** @var array<int, string> The checked pages, as Livewire's checkboxes leave them. */
    public array $pages = [];

    public function mount(LinkedInManager $linkedin): void
    {
        $this->flash = StatusMessages::current(request());
        $this->pages = array_map(fn (Page $page) => $page->id, $linkedin->selectedPages());
    }

    public function selectPages(LinkedInManager $linkedin): void
    {
        Gate::authorize(LinkedInAutopostServiceProvider::ABILITY);

        try {
            $connection = $linkedin->selectPages(array_values($this->pages));
        } catch (NotConnected|InvalidArgumentException $e) {
            $this->flash = ['type' => 'error', 'message' => $e->getMessage()];

            return;
        }

        $this->pages = $connection->selectedPages;
        $this->flash = ['type' => 'success', 'message' => 'LinkedIn pages saved.'];
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
            'pagesUrl' => route($routes.'pages.update'),
        ]);
    }
}
