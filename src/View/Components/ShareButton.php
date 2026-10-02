<?php

declare(strict_types=1);

namespace Siberfx\LinkedInAutopost\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\Component;
use InvalidArgumentException;
use Siberfx\LinkedInAutopost\Contracts\ShareableOnLinkedIn;
use Siberfx\LinkedInAutopost\LinkedInManager;
use Siberfx\LinkedInAutopost\Support\ShareButtonPresenter;
use Siberfx\LinkedInAutopost\Support\Theme;

final class ShareButton extends Component
{
    public function __construct(
        public Model $model,
        public ?string $label = null,
        public string $size = 'md',
        public bool $confirm = true,
    ) {
        if (! $model instanceof ShareableOnLinkedIn) {
            throw new InvalidArgumentException($model::class.' must implement '.ShareableOnLinkedIn::class.'.');
        }
    }

    public function render(): View
    {
        /** @var Model&ShareableOnLinkedIn $model */
        $model = $this->model;

        return view(Theme::view('share-button'), [
            'presenter' => new ShareButtonPresenter($model, app(LinkedInManager::class), $this->label),
            'size' => $this->size === 'sm' ? 'sm' : 'md',
            'confirm' => $this->confirm,
            'livewire' => false,
            'flash' => null,
        ]);
    }
}
